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
أنت "Innovera Assistant" — المساعد الذكي الرسمي لشركة Innovera (إنوفيرا).

القواعد:
1. أجب بأسلوب ودود ومباشر باللغة العربية.
2. نسّق عناوينك بأسطر جديدة واضحة ووضع مسافات قبل وجمع العناصر.
3. لا تستخدم أية حروف أو رموز غريبة غير عربية أو غير إنجليزية.
4. اذكر دائماً معلومات التواصل الرسمية: info@innoveracorp.com | +20 10 70008672 | www.innoveracorp.com

المعلومات المتاحة:
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
    //  Direct Knowledge Fallback Engine
    // ══════════════════════════════════════

    private function generateDirectFallback(string $userQuery): string
    {
        $data = $this->getData();
        $q = mb_strtolower($userQuery);

        // 1. Academy
        if ($this->matchesAny($q, ['أكاديمية','اكاديمية','academy','startup','رواد'])) {
            $acad = $data['academy'] ?? [];
            $stages = implode("\n", array_map(fn($s) => "• {$s}", $acad['stages'] ?? []));
            $duration = $acad['duration'] ?? '12–16 أسبوع';
            $accred = $acad['accreditation'] ?? 'AICERTS و Palo Alto Networks و Fortinet ونقابة المهندسين';
            return "**Innovera Academy (من الصف إلى الشريك المؤسس)** 🎓\n\nبرنامج عملي مدته **{$duration}** مصمم لطلاب الجامعات والخريجين لتحويل مشاريعهم البرمجية إلى شركات ناشئة حقيقية (Startups).\n\n**مراحل البرنامج:**\n{$stages}\n\n**الاعتمادات والتغطية:**\nمعتمد رسمياً من {$accred}.\n\n📧 للتسجيل والتقديم: info@innoveracorp.com | 📞 +20 10 70008672 | 🌐 www.innoveracorp.com";
        }

        // 2. Partnerships
        if ($this->matchesAny($q, ['شراكات','شركاء','شريك','اعتماد','اعتمادات','partner'])) {
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
            return "**شراكات Innovera الاعتمادية والدولية** 🤝\n\nنحن شركاء رسميون مع كبرى الشركات والمؤسسات التقنية العالمية:\n\n{$pStr}\n\n📧 للتواصل والشراكات المؤسسية: info@innoveracorp.com | 📞 +20 10 70008672";
        }

        // 3. Branches / Contact
        if ($this->matchesAny($q, ['عنوان','فرع','فروع','تواصل','تليفون','مصر','سعودية','عمان','مكان','إيميل','office','contact','location'])) {
            $offices = $data['company']['offices'] ?? [];
            $oLines = [];
            foreach ($offices as $o) {
                $oLines[] = "• **" . ($o['name'] ?? '') . "**: " . ($o['city'] ?? '') . " - " . ($o['address'] ?? '') . "\n  📞 هاتف: " . ($o['phone'] ?? '') . " | 📧 إيميل: " . ($o['email'] ?? '');
            }
            $oStr = implode("\n", $oLines);
            return "**فروع ومكاتب شركة Innovera الدولية** 📍\n\n{$oStr}\n\n🌐 الموقع الرسمي: www.innoveracorp.com";
        }

        // 4. Courses
        if ($this->matchesAny($q, ['كورس','كورسات','دورة','دورات','أسعار','سعر','aicerts','palo alto','ذكاء','أمن'])) {
            $courses = array_slice($data['courses'] ?? [], 0, 8);
            $cLines = [];
            foreach ($courses as $c) {
                $price = !empty($c['price']) ? " - السعر: " . $c['price'] : '';
                $cLines[] = "• **" . ($c['title'] ?? '') . "** (" . ($c['partner'] ?? '') . "){$price}\n  " . ($c['description'] ?? '');
            }
            $cStr = implode("\n", $cLines);
            return "**أبرز الكورسات والشهادات المتاحة لدى Innovera** 📚\n\n{$cStr}\n\n📧 للاستفسار عن التسجيل والخصومات المتاحة: info@innoveracorp.com | 📞 +20 10 70008672";
        }

        // 5. Default
        $desc = $data['company']['description'] ?? 'نحن شركة عربية رائدة في مجالات الذكاء الاصطناعي، الأمن السيبراني، وتطوير البرمجيات.';
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

        try {
            $this->llm->streamResponse(
                $userMessage,
                $systemMessage,
                $history,
                function (string $chunk) use (&$fullResponse, &$hasContent) {
                    if ($chunk === '') return;
                    $hasContent = true;
                    $fullResponse .= $chunk;

                    // Escape newlines for SSE protocol safety
                    $safe = str_replace(["\r\n", "\n"], '\\n', $chunk);
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
