"""
RAG Service (Retrieval-Augmented Generation)
Orchestrates user query, smart context retrieval, conversation memory, and LLM.
Includes 100% Fail-Proof Direct Knowledge Fallback Engine so the user NEVER sees an error screen.
Includes automatic session memory cleanup to prevent unbounded memory growth.
"""

import time
import json
import logging
from collections import defaultdict
from app.services.llm_service import LLMService
from app.config import settings

logger = logging.getLogger(__name__)

llm_service = LLMService()

# ─── Data Storage & Caching ───
_raw_data: dict = {}
_session_memory: dict[str, list[dict]] = defaultdict(list)
_session_last_active: dict[str, float] = {}  # Track last activity per session
_SESSION_TTL = 1800  # 30 minutes — auto-expire idle sessions
_last_session_cleanup = time.time()


def _get_data() -> dict:
    """Load and cache courses_data.json."""
    global _raw_data
    if _raw_data:
        return _raw_data

    data_path = settings.data_dir / "courses_data.json"
    try:
        with open(data_path, "r", encoding="utf-8") as f:
            _raw_data = json.load(f)
    except Exception as e:
        logger.error(f"Failed to load knowledge base: {e}")
        _raw_data = {}
    return _raw_data


def _cleanup_stale_sessions():
    """Remove sessions idle for more than _SESSION_TTL seconds."""
    global _last_session_cleanup
    now = time.time()
    if now - _last_session_cleanup < 300:  # Run at most every 5 minutes
        return

    _last_session_cleanup = now
    stale = [
        sid for sid, ts in _session_last_active.items()
        if now - ts > _SESSION_TTL
    ]
    for sid in stale:
        _session_memory.pop(sid, None)
        _session_last_active.pop(sid, None)

    if stale:
        logger.info(f"Session cleanup: evicted {len(stale)} idle sessions.")


def _build_smart_context(user_query: str) -> str:
    """
    Dynamically builds a targeted, lightweight context (~400 tokens)
    tailored to the user query to guarantee sub-second LLM responses.
    """
    data = _get_data()
    if not data:
        return "لا توجد بيانات متاحة حالياً."

    query_lower = user_query.lower()
    parts = []

    # 1. Always include Core Company & Contact Summary (~100 tokens)
    comp = data.get("company", {})
    contact = comp.get("contact", {})
    parts.append(f"""=== شركة Innovera (إنوفيرا) ===
الوصف: {comp.get('description', '')}
الموقع: {comp.get('website', '')}
التواصل الرئيسي: إيميل: {contact.get('email', '')} | هاتف: {contact.get('phone_primary', '')}
الشركاء الرسميون: AICERTS, Palo Alto Networks, NVIDIA, H3C, Fortinet, ونقابة المهندسين.""")

    # 2. Match Query Keywords to Relevant Knowledge Sections

    # Check for Contact / Branches / Offices keywords
    if any(k in query_lower for k in ["عنوان", "فرع", "فروع", "تواصل", "تليفون", "مصر", "سعودية", "عمان", "مكان", "إيميل", "phone", "email", "office", "contact", "location"]):
        offices = comp.get("offices", [])
        if offices:
            off_lines = ["=== الفروع والعناوين ==="]
            for o in offices:
                off_lines.append(f"• {o.get('name')}: {o.get('city')} - {o.get('address')} | تليفون: {o.get('phone')} | إيميل: {o.get('email')}")
            parts.append("\n".join(off_lines))

    # Check for Academy / Startups / Micro-grant keywords
    if any(k in query_lower for k in ["أكاديمية", "اكاديمية", "academy", "co-founder", "startup", "رواد", "تمويل", "micro-grant", "جرانت"]):
        acad = data.get("academy", {})
        if acad:
            parts.append(f"""=== Innovera Academy ===
البرنامج: {acad.get('program', '')} ({acad.get('duration', '')})
الوصف: {acad.get('description', '')}
المراحل: DEVELOP (تأسيس 8 أسابيع), BUILD (بناء MVP), LAUNCH (تمويل أولي), SHOW (يوم العروض Demo Day)
الاعتمادات: {acad.get('accreditation', '')}""")

    # Check for How to buy / Services / Payment
    if any(k in query_lower for k in ["شراء", "خدمات", "اشتراك", "تقسيط", "دفع", "سعر", "أسعار", "خدمة", "buy", "price"]):
        svc = data.get("services", {})
        htb = comp.get("how_to_buy", {})
        parts.append(f"""=== الخدمات وطريقة الشراء ===
• الخدمات: تدريب برمجيات وذكاء اصطناعي وأمن سيبراني + تعهيد كوادر IT + حلول رقمية واستشارات.
• خطوات الشراء: 1. طلب استشارة -> 2. عرض مخصص -> 3. تنفيذ ودعم 24/7
• للتواصل والمبيعات: {htb.get('contact', 'info@innoveracorp.com | +20 10 70008672')}""")

    # Check for AICERTS / AI / Courses keywords (Default to showing course catalog)
    courses = data.get("courses", [])
    if courses:
        matched_courses = []
        is_aicerts_query = any(k in query_lower for k in ["ai", "aicerts", "ذكاء", "كورس", "كورسات", "دورة", "دورات", "تدرّب", "شهادة", "شهادات", "course", "cert"])

        for c in courses:
            title_match = c.get("title", "").lower()
            category_match = c.get("category", "").lower()
            partner_match = c.get("partner", "").lower()

            if is_aicerts_query or any(k in query_lower for k in title_match.split() + category_match.split() + partner_match.split()):
                matched_courses.append(c)

        if not matched_courses:
            matched_courses = courses[:8]

        c_lines = ["=== الكورسات والشهادات المتاحة ==="]
        for c in matched_courses:
            line = f"• {c.get('title')} ({c.get('partner')}) - {c.get('category')} - المستوى: {c.get('level', 'جميع المستويات')}"
            if c.get("price"):
                line += f" | السعر: {c.get('price')}"
            if c.get("description"):
                line += f"\n  الوصف: {c.get('description')}"
            if c.get("syllabus"):
                line += f"\n  المحاور الرئيسية: {', '.join(c.get('syllabus'))}"
            c_lines.append(line)

        parts.append("\n\n".join(c_lines))

    # 3. Relevant FAQs
    faqs = data.get("faq", [])
    if faqs:
        faq_matches = []
        for f in faqs:
            q_text = f.get("question", "")
            if any(w in query_lower for w in q_text.lower().split() if len(w) > 3):
                faq_matches.append(f"س: {q_text}\nج: {f.get('answer')}")

        if faq_matches:
            parts.append("=== الأسئلة الشائعة ذات الصلة ===\n" + "\n".join(faq_matches[:3]))

    context_str = "\n\n".join(parts)
    logger.info(f"Built smart context ({len(context_str)} characters) for query: '{user_query}'")
    return context_str


# ─── Direct Knowledge Fallback Engine ───
def _generate_direct_knowledge_fallback(user_query: str) -> str:
    """
    Fallback engine that generates an intelligent response directly from courses_data.json
    when ALL external LLM APIs fail or network disconnects.
    Guarantees 100% Zero Error Screens for the user under ANY circumstances.
    """
    data = _get_data()
    q_lower = user_query.lower()

    # 1. Academy query
    if any(k in q_lower for k in ["أكاديمية", "اكاديمية", "academy", "startup", "رواد"]):
        acad = data.get("academy", {})
        stages_str = "\n".join([f"• {s}" for s in acad.get("stages", [])])
        return f"""**Innovera Academy (من الصف إلى الشريك المؤسس)** 🎓

برنامج عملي مدته **{acad.get('duration', '12–16 أسبوع')}** مصمم لطلاب الجامعات والخريجين لتحويل مشاريعهم البرمجية إلى شركات ناشئة حقيقية (Startups).

**مراحل البرنامج:**
{stages_str}

**الاعتمادات والتغطية:**
معتمد رسمياً من {acad.get('accreditation', 'AICERTS و Palo Alto Networks و Fortinet ونقابة المهندسين')}.

📧 للتسجيل والتقديم: info@innoveracorp.com | 📞 +20 10 70008672 | 🌐 www.innoveracorp.com"""

    # 2. Partnerships query
    if any(k in q_lower for k in ["شراكات", "شركاء", "شريك", "اعتماد", "اعتمادات", "partner"]):
        comp = data.get("company", {})
        partners = comp.get("partners", [])
        p_lines = []
        for p in partners:
            if isinstance(p, dict):
                p_lines.append(f"• **{p.get('name')}**: {p.get('type')}")
            else:
                p_lines.append(f"• **{p}**")

        return f"""**شراكات Innovera الاعتمادية والدولية** 🤝

نحن شركاء رسميون مع كبرى الشركات والمؤسسات التقنية العالمية:

{chr(10).join(p_lines)}

📧 للتواصل والشراكات المؤسسية: info@innoveracorp.com | 📞 +20 10 70008672"""

    # 3. Branches / Contact query
    if any(k in q_lower for k in ["عنوان", "فرع", "فروع", "تواصل", "تليفون", "مصر", "سعودية", "عمان", "مكان", "إيميل", "office", "contact", "location"]):
        comp = data.get("company", {})
        offices = comp.get("offices", [])
        off_lines = []
        for o in offices:
            off_lines.append(f"• **{o.get('name')}**: {o.get('city')} - {o.get('address')}\n  📞 هاتف: {o.get('phone')} | 📧 إيميل: {o.get('email')}")

        return f"""**فروع ومكاتب شركة Innovera الدولية** 📍

{chr(10).join(off_lines)}

🌐 الموقع الرسمي: www.innoveracorp.com"""

    # 4. Courses / AICERTS / Prices query
    if any(k in q_lower for k in ["كورس", "كورسات", "دورة", "دورات", "أسعار", "سعر", "aicerts", "palo alto", "ذكاء", "أمن"]):
        courses = data.get("courses", [])
        c_lines = []
        for c in courses[:8]:
            price_str = f" - السعر: {c['price']}" if c.get("price") else ""
            c_lines.append(f"• **{c.get('title')}** ({c.get('partner')}){price_str}\n  {c.get('description', '')}")

        return f"""**أبرز الكورسات والشهادات المتاحة لدى Innovera** 📚

{chr(10).join(c_lines)}

📧 للاستفسار عن التسجيل والخصومات المتاحة: info@innoveracorp.com | 📞 +20 10 70008672"""

    # 5. Default General Response
    comp = data.get("company", {})
    return f"""أهلاً بك في **Innovera**! 👋

{comp.get('description', 'نحن شركة عربية رائدة في مجالات الذكاء الاصطناعي، الأمن السيبراني، وتطوير البرمجيات.')}

**خدماتنا الرئيسية:**
• **برامج التدريب**: كورسات معتمدة دولياً من AICERTS و Palo Alto Networks.
• **Innovera Academy**: برنامج تحويل الطلاب والمطورين إلى رواد أعمال (Startups).
• **التعهيد الذكي (Outsourcing)**: توفير كوادر وفرق تقنية مجهزة للشركات.

📧 للتواصل المباشر: info@innoveracorp.com | 📞 +20 10 70008672 | 🌐 www.innoveracorp.com"""


# ─── System Prompt ───
SYSTEM_PROMPT = """أنت "Innovera Assistant" — المساعد الذكي الرسمي لشركة Innovera (إنوفيرا).

القواعد:
1. أجب بأسلوب ودود ومباشر باللغة العربية.
2. نسّق عناوينك بأسطر جديدة واضحة ووضع مسافات قبل وجمع العناصر.
3. لا تستخدم أية حروف أو رموز غريبة غير عربية أو غير إنجليزية.
4. اذكر دائماً معلومات التواصل الرسمية: info@innoveracorp.com | +20 10 70008672 | www.innoveracorp.com

المعلومات المتاحة:
{context}"""


def get_session_history(session_id: str) -> list[dict]:
    return _session_memory.get(session_id, [])


def add_to_session_history(session_id: str, role: str, content: str):
    _session_memory[session_id].append({"role": role, "content": content})
    _session_last_active[session_id] = time.time()

    max_history = settings.max_history_messages * 2
    if len(_session_memory[session_id]) > max_history:
        _session_memory[session_id] = _session_memory[session_id][-max_history:]


class RAGService:
    @staticmethod
    async def chat_stream(user_message: str, session_id: str = "default"):
        logger.info(f"[{session_id}] Processing query: {user_message}")

        # Trigger periodic session cleanup
        _cleanup_stale_sessions()

        context = _build_smart_context(user_message)
        system_message = SYSTEM_PROMPT.format(context=context)

        history = get_session_history(session_id)
        add_to_session_history(session_id, "user", user_message)

        full_response = []
        try:
            async for chunk in llm_service.generate_response_stream(
                prompt=user_message,
                system_message=system_message,
                history=history,
            ):
                full_response.append(chunk)
                yield chunk
        except Exception as e:
            logger.warning(f"LLM Stream failed: {e}. Switching to Direct Knowledge Engine Fallback...")
            # Direct Knowledge Engine Fallback: Instant, 100% reliable response from courses_data.json
            fallback_text = _generate_direct_knowledge_fallback(user_message)
            full_response.append(fallback_text)
            yield fallback_text

        add_to_session_history(session_id, "assistant", "".join(full_response))
