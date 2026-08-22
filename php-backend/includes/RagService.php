<?php
/**
 * RagService — Retrieval-Augmented Generation Service
 * 
 * Orchestrates:
 *  1. Smart Context Builder — keyword-matches the user query to courses_data.json
 *  2. LLM Streaming — sends context + query to Groq via LlmService
 *  3. Direct Knowledge Fallback — generates structured answers from JSON if ALL LLMs fail
 * 
 * Guarantees 100% zero error screens for the user.
 */

require_once __DIR__ . '/LlmService.php';
require_once __DIR__ . '/SessionStore.php';

class RagService
{
    private array $config;
    private LlmService $llm;
    private SessionStore $sessions;
    private ?array $rawData = null;

    // ─── System Prompt Template ───
    private const SYSTEM_PROMPT = <<<'PROMPT'
You are "Innovera Assistant" — the official AI-powered advisor for Innovera (إنوفيرا), a Pan-Arab leader in AI, Cybersecurity, and Digital Transformation.

═══ CRITICAL OUTPUT RULES ═══
• Do NOT output any <think>, </think>, or internal reasoning blocks. Output ONLY your final answer.
• Do NOT show your thought process, analysis steps, or drafts. Respond directly.
• Keep responses concise but helpful — aim for 3-8 short paragraphs maximum.

═══ LANGUAGE DETECTION ═══
• If the user writes in English → respond ENTIRELY in English.
• If the user writes in Arabic → respond ENTIRELY in Arabic.
• If the user mixes both → respond in the language that dominates their message.
• NEVER respond in Arabic when the user clearly wrote in English, and vice versa.

═══ YOUR PERSONALITY & TONE ═══
You are a knowledgeable, friendly AI advisor who:
• Speaks like a trusted consultant — warm, confident, and helpful.
• Gives SPECIFIC answers with real data (course names, prices, partners) from the context below.
• Proactively recommends relevant courses/services based on the user's goals.
• Asks a smart follow-up question when the user's needs are unclear.
• For simple greetings ("hi", "hello", "hey", "مرحبا"), respond with a brief warm welcome and ask how you can help — do NOT list all services unprompted.

═══ RESPONSE QUALITY ═══
1. Be SPECIFIC: Use actual course names, partner names, and prices from the knowledge base.
2. Be CONCISE: Short paragraphs. No walls of text. Get to the point.
3. Be STRUCTURED: Use bullet points and bold text for readability.
4. Be ENGAGING: Use emojis sparingly (🎯, 💡, 🚀). End with a next step or question.
5. Include contact info when relevant: info@innoveracorp.com | +20 10 70008672 | www.innoveracorp.com

═══ OUT-OF-SCOPE ═══
If asked about something NOT in the context: politely redirect to what you CAN help with and suggest contacting the team. NEVER fabricate information.

═══ KNOWLEDGE BASE ═══
{CONTEXT}
PROMPT;

    public function __construct(array $config)
    {
        $this->config   = $config;
        $this->llm      = new LlmService($config);
        $this->sessions = new SessionStore($config);
    }

    // ══════════════════════════════════════
    //  Data Loading
    // ══════════════════════════════════════

    private function getData(): array
    {
        if ($this->rawData !== null) {
            return $this->rawData;
        }

        $path = $this->config['data_dir'] . '/courses_data.json';
        if (!file_exists($path)) {
            error_log("[RagService] Knowledge base not found: {$path}");
            $this->rawData = [];
            return [];
        }

        $content = file_get_contents($path);
        $this->rawData = json_decode($content, true) ?: [];
        return $this->rawData;
    }

    // ══════════════════════════════════════
    //  Smart Context Builder (~400 tokens)
    // ══════════════════════════════════════

    private function buildSmartContext(string $userQuery): string
    {
        $data = $this->getData();
        if (empty($data)) return 'لا توجد بيانات متاحة حالياً.';

        $q = mb_strtolower($userQuery);
        $parts = [];

        // 1. Always include Core Company & Contact Summary
        $comp    = $data['company'] ?? [];
        $contact = $comp['contact'] ?? [];
        $parts[] = "=== شركة Innovera (إنوفيرا) ===\n" .
            "الوصف: " . ($comp['description'] ?? '') . "\n" .
            "الموقع: " . ($comp['website'] ?? '') . "\n" .
            "التواصل الرئيسي: إيميل: " . ($contact['email'] ?? '') . " | هاتف: " . ($contact['phone_primary'] ?? '') . "\n" .
            "الشركاء الرسميون: AICERTS, Palo Alto Networks, NVIDIA, H3C, Fortinet, ونقابة المهندسين.";

        // 2. Contact / Branches / Offices
        $branchKeywords = ['عنوان','فرع','فروع','تواصل','تليفون','مصر','سعودية','عمان','مكان','إيميل','phone','email','office','contact','location'];
        if ($this->matchesAny($q, $branchKeywords)) {
            $offices = $comp['offices'] ?? [];
            if (!empty($offices)) {
                $lines = ["=== الفروع والعناوين ==="];
                foreach ($offices as $o) {
                    $lines[] = "• " . ($o['name'] ?? '') . ": " . ($o['city'] ?? '') . " - " . ($o['address'] ?? '') . " | تليفون: " . ($o['phone'] ?? '') . " | إيميل: " . ($o['email'] ?? '');
                }
                $parts[] = implode("\n", $lines);
            }
        }

        // 3. Academy / Startups
        $academyKeywords = ['أكاديمية','اكاديمية','academy','co-founder','startup','رواد','تمويل','micro-grant','جرانت'];
        if ($this->matchesAny($q, $academyKeywords)) {
            $acad = $data['academy'] ?? [];
            if (!empty($acad)) {
                $parts[] = "=== Innovera Academy ===\n" .
                    "البرنامج: " . ($acad['program'] ?? '') . " (" . ($acad['duration'] ?? '') . ")\n" .
                    "الوصف: " . ($acad['description'] ?? '') . "\n" .
                    "المراحل: DEVELOP (تأسيس 8 أسابيع), BUILD (بناء MVP), LAUNCH (تمويل أولي), SHOW (يوم العروض Demo Day)\n" .
                    "الاعتمادات: " . ($acad['accreditation'] ?? '');
            }
        }

        // 4. Services / How to Buy / Prices
        $serviceKeywords = ['شراء','خدمات','اشتراك','تقسيط','دفع','سعر','أسعار','خدمة','buy','price'];
        if ($this->matchesAny($q, $serviceKeywords)) {
            $htb = $comp['how_to_buy'] ?? [];
            $parts[] = "=== الخدمات وطريقة الشراء ===\n" .
                "• الخدمات: تدريب برمجيات وذكاء اصطناعي وأمن سيبراني + تعهيد كوادر IT + حلول رقمية واستشارات.\n" .
                "• خطوات الشراء: 1. طلب استشارة -> 2. عرض مخصص -> 3. تنفيذ ودعم 24/7\n" .
                "• للتواصل والمبيعات: " . ($htb['contact'] ?? 'info@innoveracorp.com | +20 10 70008672');
        }

        // 5. Courses
        $courses = $data['courses'] ?? [];
        if (!empty($courses)) {
            $courseKeywords = ['ai','aicerts','ذكاء','كورس','كورسات','دورة','دورات','تدرّب','شهادة','شهادات','course','cert'];
            $isCourseQuery = $this->matchesAny($q, $courseKeywords);

            $matchedCourses = [];
            foreach ($courses as $c) {
                $titleWords    = explode(' ', mb_strtolower($c['title'] ?? ''));
                $categoryWords = explode(' ', mb_strtolower($c['category'] ?? ''));
                $partnerWords  = explode(' ', mb_strtolower($c['partner'] ?? ''));
                $allWords = array_merge($titleWords, $categoryWords, $partnerWords);

                if ($isCourseQuery || $this->matchesAny($q, $allWords)) {
                    $matchedCourses[] = $c;
                }
            }

            if (empty($matchedCourses)) {
                $matchedCourses = array_slice($courses, 0, 8);
            }

            $cLines = ["=== الكورسات والشهادات المتاحة ==="];
            foreach ($matchedCourses as $c) {
                $line = "• " . ($c['title'] ?? '') . " (" . ($c['partner'] ?? '') . ") - " . ($c['category'] ?? '') . " - المستوى: " . ($c['level'] ?? 'جميع المستويات');
                if (!empty($c['price'])) $line .= " | السعر: " . $c['price'];
                if (!empty($c['description'])) $line .= "\n  الوصف: " . $c['description'];
                if (!empty($c['syllabus'])) $line .= "\n  المحاور الرئيسية: " . implode(', ', $c['syllabus']);
                $cLines[] = $line;
            }
            $parts[] = implode("\n\n", $cLines);
        }

        // 6. FAQs
        $faqs = $data['faq'] ?? [];
        if (!empty($faqs)) {
            $faqMatches = [];
            foreach ($faqs as $f) {
                $qText = $f['question'] ?? '';
                $words = array_filter(explode(' ', mb_strtolower($qText)), fn($w) => mb_strlen($w) > 3);
                if ($this->matchesAny($q, $words)) {
                    $faqMatches[] = "س: {$qText}\nج: " . ($f['answer'] ?? '');
                }
            }
            if (!empty($faqMatches)) {
                $parts[] = "=== الأسئلة الشائعة ذات الصلة ===\n" . implode("\n", array_slice($faqMatches, 0, 3));
            }
        }

        $context = implode("\n\n", $parts);
        error_log("[RagService] Built smart context (" . strlen($context) . " chars) for: '{$userQuery}'");
        return $context;
    }

    // ══════════════════════════════════════
    //  Language Detection
    // ══════════════════════════════════════

    private function isEnglish(string $text): bool
    {
        $arabicChars = preg_match_all('/[\x{0600}-\x{06FF}]/u', $text);
        $latinChars  = preg_match_all('/[a-zA-Z]/', $text);
        return $latinChars > $arabicChars;
    }

    // ══════════════════════════════════════
    //  Direct Knowledge Fallback Engine
    // ══════════════════════════════════════

    private function generateDirectFallback(string $userQuery): string
    {
        $data = $this->getData();
        $q = mb_strtolower($userQuery);
        $en = $this->isEnglish($userQuery);

        // 1. Academy
        if ($this->matchesAny($q, ['أكاديمية','اكاديمية','academy','startup','رواد','incubator','co-founder','accelerator'])) {
            $acad = $data['academy'] ?? [];
            $duration = $acad['duration'] ?? '12–16 weeks';
            if ($en) {
                return "**Innovera Academy — From Classroom to Co-Founder** 🎓\n\nA hands-on **{$duration}** incubator & accelerator program designed for university students and fresh graduates to transform their tech projects into real startups.\n\n**Program Stages:**\n• **DEVELOP** (8 weeks): Technical foundation, market research, pitch deck\n• **BUILD** (weeks 4-9): MVP development with cloud credits and mentorship\n• **LAUNCH** (4 weeks): Micro-grant funding (no equity taken!) and investor pitches\n• **SHOW** (Final week): Demo Day presentations and career opportunities\n\n**Accredited by**: AICERTS, Palo Alto Networks, Fortinet & Engineering Syndicate\n\n📧 Apply now: info@innoveracorp.com | 📞 +20 10 70008672 | 🌐 www.innoveracorp.com";
            }
            $stages = implode("\n", array_map(fn($s) => "• {$s}", $acad['stages'] ?? []));
            $accred = $acad['accreditation'] ?? 'AICERTS و Palo Alto Networks و Fortinet ونقابة المهندسين';
            return "**Innovera Academy (من الصف إلى الشريك المؤسس)** 🎓\n\nبرنامج عملي مدته **{$duration}** مصمم لطلاب الجامعات والخريجين لتحويل مشاريعهم البرمجية إلى شركات ناشئة حقيقية (Startups).\n\n**مراحل البرنامج:**\n{$stages}\n\n**الاعتمادات والتغطية:**\nمعتمد رسمياً من {$accred}.\n\n📧 للتسجيل والتقديم: info@innoveracorp.com | 📞 +20 10 70008672 | 🌐 www.innoveracorp.com";
        }

        // 2. Partnerships
        if ($this->matchesAny($q, ['شراكات','شركاء','شريك','اعتماد','اعتمادات','partner','partnership','certified','accredit'])) {
            $partners = $data['company']['partners'] ?? [];
            $pLines = [];
            foreach ($partners as $p) {
                if (is_array($p)) {
                    $pLines[] = "• **" . ($p['name'] ?? '') . "**: " . ($p['type'] ?? '');
                } else {
                    $pLines[] = "• **{$p}**";
                }
            }
            $pStr = implode("\n", $pLines);
            if ($en) {
                return "**Innovera's Strategic Partnerships & Accreditations** 🤝\n\nWe are official partners with leading global technology companies:\n\n{$pStr}\n\n📧 For corporate partnerships: info@innoveracorp.com | 📞 +20 10 70008672";
            }
            return "**شراكات Innovera الاعتمادية والدولية** 🤝\n\nنحن شركاء رسميون مع كبرى الشركات والمؤسسات التقنية العالمية:\n\n{$pStr}\n\n📧 للتواصل والشراكات المؤسسية: info@innoveracorp.com | 📞 +20 10 70008672";
        }

        // 3. Branches / Contact
        if ($this->matchesAny($q, ['عنوان','فرع','فروع','تواصل','تليفون','مصر','سعودية','عمان','مكان','إيميل','office','contact','location','branch','address','where'])) {
            $offices = $data['company']['offices'] ?? [];
            $oLines = [];
            foreach ($offices as $o) {
                $oLines[] = "• **" . ($o['name'] ?? '') . "**: " . ($o['city'] ?? '') . " - " . ($o['address'] ?? '') . "\n  📞 " . ($o['phone'] ?? '') . " | 📧 " . ($o['email'] ?? '');
            }
            $oStr = implode("\n", $oLines);
            if ($en) {
                return "**Innovera International Offices** 📍\n\n{$oStr}\n\n🌐 Website: www.innoveracorp.com";
            }
            return "**فروع ومكاتب شركة Innovera الدولية** 📍\n\n{$oStr}\n\n🌐 الموقع الرسمي: www.innoveracorp.com";
        }

        // 4. Courses
        if ($this->matchesAny($q, ['كورس','كورسات','دورة','دورات','أسعار','سعر','aicerts','palo alto','ذكاء','أمن','course','courses','training','certification','program','price','cost','learn'])) {
            $courses = array_slice($data['courses'] ?? [], 0, 8);
            $cLines = [];
            foreach ($courses as $c) {
                $price = !empty($c['price']) ? " | Price: " . $c['price'] : '';
                $cLines[] = "• **" . ($c['title'] ?? '') . "** (" . ($c['partner'] ?? '') . "){$price}\n  " . ($c['description'] ?? '');
            }
            $cStr = implode("\n", $cLines);
            if ($en) {
                return "**Innovera's Certified Courses & Programs** 📚\n\n{$cStr}\n\n📧 For enrollment & discounts: info@innoveracorp.com | 📞 +20 10 70008672";
            }
            return "**أبرز الكورسات والشهادات المتاحة لدى Innovera** 📚\n\n{$cStr}\n\n📧 للاستفسار عن التسجيل والخصومات المتاحة: info@innoveracorp.com | 📞 +20 10 70008672";
        }

        // 5. Default
        $desc = $data['company']['description'] ?? '';
        if ($en) {
            return "**Welcome to Innovera!** 👋\n\n{$desc}\n\n**Our Core Services:**\n• **Professional Training**: Internationally certified courses from AICERTS & Palo Alto Networks\n• **Innovera Academy**: A startup incubator transforming students into co-founders\n• **Smart Outsourcing**: Providing ready-made technical teams for enterprises\n\n📧 Get in touch: info@innoveracorp.com | 📞 +20 10 70008672 | 🌐 www.innoveracorp.com";
        }
        return "أهلاً بك في **Innovera**! 👋\n\n{$desc}\n\n**خدماتنا الرئيسية:**\n• **برامج التدريب**: كورسات معتمدة دولياً من AICERTS و Palo Alto Networks.\n• **Innovera Academy**: برنامج تحويل الطلاب والمطورين إلى رواد أعمال (Startups).\n• **التعهيد الذكي (Outsourcing)**: توفير كوادر وفرق تقنية مجهزة للشركات.\n\n📧 للتواصل المباشر: info@innoveracorp.com | 📞 +20 10 70008672 | 🌐 www.innoveracorp.com";
    }

    // ══════════════════════════════════════
    //  Main Chat Stream
    // ══════════════════════════════════════

    /**
     * Process user message and stream response via SSE.
     * Outputs SSE frames directly to the client (echo + flush).
     */
    public function chatStream(string $userMessage, string $sessionId): void
    {
        error_log("[RagService] [{$sessionId}] Processing: {$userMessage}");

        // Periodically clean up stale sessions
        if (rand(1, 10) === 1) {
            $this->sessions->cleanupStaleSessions();
        }

        // Build context and system prompt
        $context       = $this->buildSmartContext($userMessage);
        $systemMessage = str_replace('{CONTEXT}', $context, self::SYSTEM_PROMPT);

        // Get and update session history
        $history = $this->sessions->getHistory($sessionId);
        $this->sessions->addToHistory($sessionId, 'user', $userMessage);

        $fullResponse = '';
        $hasContent = false;
        $insideThinkBlock = false;
        $plainTextThinking = false;

        try {
            $this->llm->streamResponse(
                $userMessage,
                $systemMessage,
                $history,
                function (string $chunk) use (&$fullResponse, &$hasContent, &$insideThinkBlock, &$plainTextThinking) {
                    if ($chunk === '') return;

                    $text = $chunk;

                    // ─── Filter 1: <think>...</think> XML tags ───
                    if (!$insideThinkBlock && str_contains($text, '<think>')) {
                        $parts = explode('<think>', $text, 2);
                        $text = $parts[0];
                        $insideThinkBlock = true;
                    }
                    if ($insideThinkBlock) {
                        if (str_contains($text, '</think>')) {
                            $afterThink = explode('</think>', $text, 2);
                            $text = $afterThink[1] ?? '';
                            $insideThinkBlock = false;
                        } else {
                            return; // Still inside think block
                        }
                    }

                    // ─── Filter 2: Plain-text thinking patterns ───
                    // Detect start of plain-text reasoning (common in Qwen/GPT-OSS)
                    $thinkPatterns = [
                        "thinking process",
                        "Analyze User Input",
                        "Check Constraints",
                        "Formulate Response",
                        "Mental Refinement",
                        "Check Against Constraints",
                        "Here's a thinking",
                        "Here is a thinking",
                        "Let me think",
                        "I need to think",
                    ];

                    $combined = $fullResponse . $text;
                    foreach ($thinkPatterns as $pattern) {
                        if (stripos($combined, $pattern) !== false && !$hasContent) {
                            $plainTextThinking = true;
                            return; // Suppress this chunk
                        }
                    }

                    if ($plainTextThinking) {
                        // Look for the actual response after thinking ends
                        // Common markers: ✅, or the actual greeting/response start
                        if (preg_match('/✅\s*(.+)/su', $text, $m)) {
                            $text = $m[1];
                            $plainTextThinking = false;
                        } else {
                            return; // Still in thinking section
                        }
                    }

                    // Don't output empty chunks, but preserve spaces!
                    if ($text === '') return;

                    $hasContent = true;
                    $fullResponse .= $text;

                    // Escape newlines for SSE protocol safety
                    $safe = str_replace(["\r\n", "\n"], '\\n', $text);
                    echo "data: {$safe}\n\n";
                    if (ob_get_level()) ob_flush();
                    flush();
                }
            );
        } catch (\RuntimeException $e) {
            error_log("[RagService] LLM failed: {$e->getMessage()}. Using Direct Knowledge Fallback...");
            $fallback = $this->generateDirectFallback($userMessage);
            $fullResponse .= $fallback;
            $hasContent = true;

            $safe = str_replace(["\r\n", "\n"], '\\n', $fallback);
            echo "data: {$safe}\n\n";
            if (ob_get_level()) ob_flush();
            flush();
        }

        // Guard against completely empty responses
        if (!$hasContent) {
            $fallback = 'عذراً، لم أتمكن من إنشاء رد الآن. حاول مرة أخرى. 🙏';
            $fullResponse .= $fallback;
            echo "data: {$fallback}\n\n";
            if (ob_get_level()) ob_flush();
            flush();
        }

        // Send end signal
        echo "data: [DONE]\n\n";
        if (ob_get_level()) ob_flush();
        flush();

        // Save assistant response to history
        $this->sessions->addToHistory($sessionId, 'assistant', $fullResponse);
    }

    // ══════════════════════════════════════
    //  Utility
    // ══════════════════════════════════════

    /**
     * Check if $text contains any of the $keywords.
     */
    private function matchesAny(string $text, array $keywords): bool
    {
        foreach ($keywords as $k) {
            if ($k !== '' && str_contains($text, $k)) {
                return true;
            }
        }
        return false;
    }
}
