"""
Data Ingestion Script
Reads courses_data.json and populates ChromaDB with well-structured, 
bilingual document chunks for optimal RAG retrieval.
"""

import json
import logging
import sys
import shutil
from pathlib import Path

# Add the backend dir to the path so we can import app modules
backend_dir = Path(__file__).parent.parent.parent
sys.path.append(str(backend_dir))

from app.config import settings
from app.services.vector_service import VectorService

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)


def load_data():
    """Load the JSON data file."""
    data_path = settings.data_dir / "courses_data.json"
    with open(data_path, "r", encoding="utf-8") as f:
        return json.load(f)


def format_documents(data: dict):
    """
    Process the JSON data and create well-structured chunks 
    with both Arabic and English keywords for better search matching.
    Each chunk is self-contained and answers a specific type of question.
    """
    documents = []
    metadatas = []
    ids = []

    company = data.get("company", {})
    contact = company.get("contact", {})

    # ─── 1. Company Overview (AR + EN) ───
    doc = (
        f"شركة Innovera (إنوفيرا) - معلومات عامة عن الشركة - About Innovera Company\n"
        f"الاسم: {company.get('name')}\n"
        f"الشعار: {company.get('tagline')}\n"
        f"الوصف: {company.get('description')}\n"
        f"الرسالة: {company.get('mission')}\n"
        f"الرؤية: {company.get('vision')}\n"
        f"الموقع الإلكتروني: {company.get('website')}\n"
        f"هاتف التواصل: {contact.get('phone')}\n"
        f"البريد الإلكتروني: {contact.get('email')}\n"
        f"اللغات المدعومة: {', '.join(contact.get('languages', []))}\n"
    )
    documents.append(doc)
    metadatas.append({"source": "company", "type": "overview"})
    ids.append("company_overview")

    # ─── 2. Partners (AR + EN) ───
    partners = company.get("partners", [])
    doc = (
        f"شركاء Innovera - Partners of Innovera - الشراكات\n"
        f"شركة Innovera شريكة مع: {', '.join(partners)}\n"
        f"Innovera partners with: {', '.join(partners)}\n"
        f"من خلال هذه الشراكات نقدم حلول ذكاء اصطناعي وأمن سيبراني متقدمة."
    )
    documents.append(doc)
    metadatas.append({"source": "company", "type": "partners"})
    ids.append("company_partners")

    # ─── 3. Branches & Location (AR + EN) ───
    branches = company.get("branches", [])
    branch_texts = []
    for b in branches:
        branch_texts.append(f"{b.get('name')} - {b.get('location')} - {b.get('note')}")
    doc = (
        f"فروع Innovera - عنوان الشركة - مقر الشركة - Branches - Location - Address\n"
        f"المناطق التي نخدمها: {', '.join(company.get('areas_served', []))}\n"
        f"الفروع:\n" + "\n".join(branch_texts) + "\n"
        f"للتواصل: {contact.get('phone')} - {contact.get('email')}"
    )
    documents.append(doc)
    metadatas.append({"source": "company", "type": "branches"})
    ids.append("company_branches")

    # ─── 4. Payment Methods (AR + EN) ───
    payments = company.get("payment_methods", [])
    doc = (
        f"طرق الدفع - طريقة الدفع - Payment Methods - كيف أدفع - التقسيط - الأسعار\n"
        f"{chr(10).join(payments)}\n"
        f"للاستفسار عن الدفع والتقسيط تواصل: {contact.get('phone')} أو {contact.get('email')}"
    )
    documents.append(doc)
    metadatas.append({"source": "company", "type": "payment"})
    ids.append("company_payments")

    # ─── 5. Social Media (AR + EN) ───
    social = company.get("social_media", {})
    doc = (
        f"وسائل التواصل الاجتماعي - Social Media - صفحات Innovera\n"
        f"فيسبوك Facebook: {social.get('facebook')}\n"
        f"تويتر Twitter/X: {social.get('twitter')}\n"
        f"لينكدإن LinkedIn: {social.get('linkedin')}\n"
        f"الموقع: {company.get('website')}"
    )
    documents.append(doc)
    metadatas.append({"source": "company", "type": "social"})
    ids.append("company_social")

    # ─── 6. Services (AR + EN) ───
    services = data.get("services", {})
    training = services.get("training", {})
    outsourcing = services.get("outsourcing", {})

    doc = (
        f"خدمات Innovera - Services - ماذا تقدم الشركة - أنواع الخدمات\n\n"
        f"أولاً: التدريب (Training):\n"
    )
    for key, val in training.items():
        doc += f"- {val.get('title')}: {val.get('description')}\n"
    doc += f"\nثانياً: التعهيد والتوظيف (Outsourcing):\n"
    for key, val in outsourcing.items():
        doc += f"- {val.get('title')}: {val.get('description')}\n"
    documents.append(doc)
    metadatas.append({"source": "services", "type": "services"})
    ids.append("company_services")

    # ─── 7. Industries Served (AR + EN) ───
    industries = data.get("industries", [])
    doc = (
        f"القطاعات التي تخدمها Innovera - Industries - المجالات\n"
        f"تخدم Innovera القطاعات التالية:\n"
        f"{chr(10).join(['- ' + i for i in industries])}"
    )
    documents.append(doc)
    metadatas.append({"source": "company", "type": "industries"})
    ids.append("company_industries")

    # ─── 8. All Courses Summary (AR + EN) ───
    courses = data.get("courses", [])
    doc = (
        f"كورسات Innovera - جميع الكورسات المتاحة - الدورات - Available Courses - Course List - أسعار الكورسات - Prices\n\n"
    )
    for c in courses:
        doc += f"• {c.get('title')} | التصنيف: {c.get('category')} | السعر: {c.get('price')} | رابط التسجيل: {c.get('enrollment_url')}\n"
    doc += f"\nللتسجيل أو الاستفسار: {contact.get('phone')} - {contact.get('email')}"
    documents.append(doc)
    metadatas.append({"source": "courses", "type": "course_list"})
    ids.append("all_courses_summary")

    # ─── 9. Each Course Individually (AR + EN) ───
    for course in courses:
        doc = (
            f"كورس {course.get('title')} - تفاصيل الكورس - Course Details\n"
            f"اسم الكورس: {course.get('title')}\n"
            f"التصنيف (Category): {course.get('category')}\n"
            f"السعر (Price): {course.get('price')}\n"
            f"رابط التسجيل: {course.get('enrollment_url')}\n"
            f"الوصف: {course.get('description')}\n"
        )
        if "domains" in course:
            doc += f"المجالات: {', '.join(course['domains'])}\n"
        if "what_you_will_learn" in course:
            doc += f"ماذا ستتعلم: {chr(10).join(['  - ' + w for w in course['what_you_will_learn']])}\n"
        if "modules" in course:
            doc += f"المحتوى: {', '.join(course['modules'])}\n"
        if "virtual_labs" in course:
            doc += f"المعامل الافتراضية: {', '.join(course['virtual_labs'])}\n"
        if "target_audience" in course:
            doc += f"الفئة المستهدفة: {course['target_audience']}\n"
        if "partner" in course:
            doc += f"الشريك: {course['partner']}\n"

        documents.append(doc)
        metadatas.append({"source": "courses", "type": "course", "course_id": str(course.get("id"))})
        ids.append(f"course_{course.get('id')}")

    # ─── 10. FAQ (AR + EN) ───
    for idx, faq in enumerate(data.get("faq", [])):
        doc = f"سؤال شائع - FAQ\nسؤال: {faq.get('question')}\nإجابة: {faq.get('answer')}"
        documents.append(doc)
        metadatas.append({"source": "faq", "type": "faq"})
        ids.append(f"faq_{idx}")

    return documents, metadatas, ids


def main():
    logger.info("Starting data ingestion into ChromaDB...")

    # 1. Clean old database for a fresh start
    chroma_path = Path(settings.chroma_persist_dir)
    if chroma_path.exists():
        shutil.rmtree(chroma_path)
        logger.info(f"Deleted old ChromaDB at: {chroma_path}")

    # 2. Load raw data
    data = load_data()

    # 3. Format into optimized chunks
    documents, metadatas, ids = format_documents(data)
    logger.info(f"Generated {len(documents)} document chunks.")

    # 4. Add to Vector DB
    vector_service = VectorService()
    vector_service.add_documents(documents, metadatas, ids)
    logger.info("Ingestion complete! All data is now searchable.")


if __name__ == "__main__":
    main()
