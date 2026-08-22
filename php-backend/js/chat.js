/**
 * Innovera AI Chatbot — Frontend Controller (PHP Backend Edition)
 * 
 * Features:
 *  - Session management (UUID per user, persisted in localStorage)
 *  - Robust SSE stream reader handling multi-line protocol data
 *  - Spacious, clean Markdown rendering (bold, lists, links, headers, line breaks)
 *  - Quick reply buttons for common questions
 *  - Auto-retry with exponential backoff on network failures
 *  - Fetch timeout guard (25s) to prevent infinite hangs
 *  - Configurable API URL via data attribute
 */

document.addEventListener('DOMContentLoaded', () => {
    // ─── Elements ───
    const toggleBtn = document.getElementById('chat-toggle-btn');
    const closeBtn = document.getElementById('chat-close-btn');
    const chatContainer = document.getElementById('chat-container');
    const chatInput = document.getElementById('chat-input');
    const sendBtn = document.getElementById('chat-send-btn');
    const messagesArea = document.getElementById('chat-messages');

    // ─── Configuration ───
    const widgetRoot = document.getElementById('innovera-chatbot') || document.body;
    const API_URL = widgetRoot.getAttribute('data-api-url') || 'api/chat.php';

    const MAX_RETRIES = 2;            // Retry up to 2 times on network failure
    const FETCH_TIMEOUT_MS = 25000;   // Abort fetch after 25 seconds of no response

    // ─── Session Management ───
    let sessionId = localStorage.getItem('innovera_session_id');
    if (!sessionId) {
        sessionId = crypto.randomUUID ? crypto.randomUUID() : generateUUID();
        localStorage.setItem('innovera_session_id', sessionId);
    }

    // ─── State ───
    let isWaitingForResponse = false;

    // ─── Quick Replies ───
    const quickReplies = [
        { text: '📚 الكورسات والأسعار', message: 'إيه الكورسات المتاحة وأسعارها؟' },
        { text: '🎓 الأكاديمية', message: 'إيه هي Innovera Academy؟' },
        { text: '🤝 الشراكات', message: 'ما هي شراكات Innovera؟' },
        { text: '📍 الفروع', message: 'فين فروع Innovera؟' },
    ];

    // Show initial quick replies
    showQuickReplies();

    // ─── Toggle Chat ───
    toggleBtn.addEventListener('click', () => {
        chatContainer.classList.remove('hidden');
        toggleBtn.style.transform = 'scale(0)';
        setTimeout(() => chatInput.focus(), 300);
    });

    closeBtn.addEventListener('click', () => {
        chatContainer.classList.add('hidden');
        toggleBtn.style.transform = 'scale(1)';
    });

    // ─── Input Handling ───
    chatInput.addEventListener('input', () => {
        sendBtn.disabled = chatInput.value.trim() === '' || isWaitingForResponse;
    });

    chatInput.addEventListener('keypress', (e) => {
        if (e.key === 'Enter' && !sendBtn.disabled) {
            sendMessage();
        }
    });

    sendBtn.addEventListener('click', () => {
        if (!sendBtn.disabled) {
            sendMessage();
        }
    });

    // ─── Send Message (with auto-retry) ───
    async function sendMessage(overrideMessage) {
        const message = overrideMessage || chatInput.value.trim();
        if (!message) return;

        removeQuickReplies();
        appendMessage(message, 'user');

        chatInput.value = '';
        sendBtn.disabled = true;
        isWaitingForResponse = true;

        const typingIndicator = showTypingIndicator();
        scrollToBottom();

        let lastError = null;
        let success = false;

        for (let attempt = 0; attempt <= MAX_RETRIES; attempt++) {
            if (attempt > 0) {
                // Exponential backoff: 1s, then 2s
                const delay = Math.pow(2, attempt - 1) * 1000;
                console.log(`Retry attempt ${attempt}/${MAX_RETRIES} after ${delay}ms`);
                await sleep(delay);
            }

            try {
                await streamResponse(message, typingIndicator);
                success = true;
                break;
            } catch (error) {
                lastError = error;
                console.warn(`Attempt ${attempt + 1} failed:`, error.message);

                // Don't retry on rate limit — it's intentional
                if (error.message === 'rate_limit') {
                    break;
                }
            }
        }

        if (!success) {
            safeRemove(typingIndicator);
            if (lastError && lastError.message === 'rate_limit') {
                appendMessage(
                    'عذراً، الخادم مشغول حالياً. يرجى المحاولة بعد بضع ثوانٍ. ⏳',
                    'bot'
                );
            } else {
                appendMessage(
                    'عذراً، حدث خطأ في الاتصال بالخادم. تأكد من اتصالك بالإنترنت وحاول مرة أخرى. 🔌',
                    'bot'
                );
            }
        }

        showQuickReplies();
        isWaitingForResponse = false;
        sendBtn.disabled = chatInput.value.trim() === '';
        chatInput.focus();
        scrollToBottom();
    }

    // ─── SSE Stream Reader ───
    async function streamResponse(message, typingIndicator) {
        // AbortController for fetch timeout
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), FETCH_TIMEOUT_MS);

        let response;
        try {
            response = await fetch(API_URL, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'text/event-stream',
                },
                body: JSON.stringify({
                    message: message,
                    session_id: sessionId,
                }),
                signal: controller.signal,
            });
        } catch (fetchError) {
            clearTimeout(timeoutId);
            if (fetchError.name === 'AbortError') {
                throw new Error('timeout');
            }
            throw new Error('network_error');
        }

        clearTimeout(timeoutId);

        if (!response.ok) {
            if (response.status === 429) {
                throw new Error('rate_limit');
            }
            throw new Error('network_error');
        }

        safeRemove(typingIndicator);
        const botMessageContent = appendMessage('', 'bot');
        let fullResponse = '';
        let streamDone = false;

        const reader = response.body.getReader();
        const decoder = new TextDecoder('utf-8');
        let buffer = '';

        try {
            while (!streamDone) {
                const { done, value } = await reader.read();
                if (done) break;

                buffer += decoder.decode(value, { stream: true });
                const lines = buffer.split('\n\n');

                // Keep the last uncompleted chunk in buffer
                buffer = lines.pop() || '';

                for (const line of lines) {
                    const trimmedLine = line.trim();
                    if (!trimmedLine.startsWith('data: ')) continue;

                    let data = trimmedLine.substring(6);

                    if (data === '[DONE]') {
                        streamDone = true;
                        break;
                    }
                    if (data.startsWith('[ERROR]')) {
                        fullResponse = 'عذراً، حدث خطأ مؤقت. حاول مرة أخرى. 🙏';
                        botMessageContent.innerHTML = renderMarkdown(fullResponse);
                        streamDone = true;
                        break;
                    }

                    // Restore escaped newlines sent over SSE
                    data = data.replace(/\\n/g, '\n');

                    // Strip <think>...</think> reasoning blocks (safety net)
                    data = data.replace(/<think>[\s\S]*?<\/think>/g, '');
                    data = data.replace(/<\/?think>/g, '');

                    if (!data.trim()) continue; // Skip empty after filtering

                    fullResponse += data;
                    botMessageContent.innerHTML = renderMarkdown(fullResponse);
                    scrollToBottom();
                }
            }

            // Flush any remaining buffer data
            if (buffer.trim().startsWith('data: ')) {
                let data = buffer.trim().substring(6);
                if (data !== '[DONE]' && !data.startsWith('[ERROR]')) {
                    data = data.replace(/\\n/g, '\n');
                    fullResponse += data;
                    botMessageContent.innerHTML = renderMarkdown(fullResponse);
                    scrollToBottom();
                }
            }
        } finally {
            // Always release the reader lock
            try { reader.cancel(); } catch (_) { /* ignore */ }
        }

        // Guard against empty responses — show a friendly fallback
        if (!fullResponse.trim()) {
            botMessageContent.innerHTML = renderMarkdown(
                'عذراً، لم أتمكن من إنشاء رد الآن. حاول مرة أخرى. 🙏'
            );
        }
    }

    // ─── Spacious Markdown & Table Renderer ───
    function renderMarkdown(text) {
        if (!text) return '';

        let html = text;

        // 0. Strip any <think>...</think> reasoning blocks (defense in depth)
        html = html.replace(/<think>[\s\S]*?<\/think>/g, '');
        html = html.replace(/<\/?think>/g, '');

        // 1. Remove non-Arabic/English artifacts like Chinese characters
        html = html.replace(/[\u4e00-\u9fa5]/g, '');

        // 2. Pre-formatting: Inject newlines before stuck headers and bullets
        // Fix: "text### Header" -> "text\n\n### Header"
        html = html.replace(/([^\n])\s*(#{1,6}\s+)/g, '$1\n\n$2');
        // Fix: "text• Item" or "text:• Item" -> "text:\n• Item"
        html = html.replace(/([^\n\r])\s*([•\-*])\s+/g, '$1\n$2 ');
        // Fix: "text---" -> "text\n\n---\n\n"
        html = html.replace(/([^\n\r])\s*(---|___|\*\*\*)/g, '$1\n\n$2\n\n');

        // 3. Escape HTML special characters (except markdown syntax)
        html = html.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

        // 4. Parse Markdown Tables (| header | header |\n|---|---|\n| cell | cell |)
        html = renderMarkdownTables(html);

        // 5. Horizontal Dividers (--- or ***)
        html = html.replace(/^[\s]*(---|___|\*\*\*)[\s]*$/gm, '<hr class="md-hr">');

        // 6. Blockquotes (> quote)
        html = html.replace(/^>\s*(.+)$/gm, '<blockquote class="md-quote">$1</blockquote>');

        // 7. Headings (#, ##, ###)
        html = html.replace(/^###\s*(.+)$/gm, '<div class="md-heading-3">$1</div>');
        html = html.replace(/^##\s*(.+)$/gm, '<div class="md-heading-2">$1</div>');
        html = html.replace(/^#\s*(.+)$/gm, '<div class="md-heading-1">$1</div>');

        // 8. Bold: **text** or __text__
        html = html.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
        html = html.replace(/__(.+?)__/g, '<strong>$1</strong>');

        // 9. Italic: *text* or _text_
        html = html.replace(/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/g, '<em>$1</em>');

        // 10. Interactive Contact Box Parser
        // Detect lines containing Innovera contact info and convert into rich action cards
        const contactPattern = /(?:📧|Email|✉️)?\s*\[?(?:info@innoveracorp\.com)\]?(?:\(mailto:info@innoveracorp\.com\))?\s*\|?\s*(?:📞|Phone|WhatsApp)?\s*(?:\+?20[\s\u202f]?10[\s\u202f]?700[\s\u202f]?08672|\+201070008672)\s*\|?\s*(?:🌐|Web)?\s*(?:(?:https?:\/\/)?(?:www\.)?innoveracorp\.com)?/gi;
        
        html = html.replace(contactPattern, () => {
            const isEn = /[a-zA-Z]{4,}/.test(html) && !/[\u0600-\u06FF]/.test(html.slice(0, 100));
            const title = isEn ? '📞 Direct Contact & Inquiries:' : '📞 قنوات التواصل المباشرة والتسجيل:';
            const emailLabel = isEn ? '✉️ Email Us' : '✉️ راسلنا عبر الإيميل';
            const waLabel = isEn ? '💬 WhatsApp' : '💬 تواصل واتساب';
            const webLabel = isEn ? '🌐 Official Website' : '🌐 الموقع الرسمي';

            return `\n\n<div class="md-contact-box">
                <div class="md-contact-box-title">${title}</div>
                <div class="md-contact-actions">
                    <a href="mailto:info@innoveracorp.com" class="md-contact-btn">${emailLabel}</a>
                    <a href="https://wa.me/201070008672" target="_blank" rel="noopener" class="md-contact-btn">${waLabel}</a>
                    <a href="https://www.innoveracorp.com" target="_blank" rel="noopener" class="md-contact-btn">${webLabel}</a>
                </div>
            </div>\n\n`;
        });

        // 11. Markdown Links: [text](url)
        const links = [];
        html = html.replace(/\[([^\]]+)\]\(([^)]+)\)/g, (match, p1, p2) => {
            const index = links.length;
            links.push(`<a href="${p2}" target="_blank" rel="noopener" class="md-link">${p1}</a>`);
            return `___LINK_${index}___`;
        });

        // 12. Bare Email addresses (not inside contact box)
        html = html.replace(
            /([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/g,
            '<a href="mailto:$1" class="md-email">$1</a>'
        );

        // 13. Restore Markdown Links
        html = html.replace(/___LINK_(\d+)___/g, (match, p1) => links[parseInt(p1, 10)] || '');

        // 14. Bullet lists: lines starting with - or • or *
        html = html.replace(/^[\s]*[-•*]\s+(.+)$/gm, '<li class="md-li"><span class="md-bullet">•</span><span class="md-li-content">$1</span></li>');
        html = html.replace(/(<li class="md-li">[\s\S]*?<\/li>\n?)+/g, '<ul class="md-ul">$&</ul>');

        // 15. Numbered lists: lines starting with 1. 2. etc
        html = html.replace(/^[\s]*(\d+)\.\s+(.+)$/gm, '<li class="md-oli"><span class="md-num">$1.</span><span class="md-li-content">$2</span></li>');
        html = html.replace(/(<li class="md-oli">[\s\S]*?<\/li>\n?)+/g, '<ol class="md-ol">$&</ol>');

        // 16. Inline code: `text`
        html = html.replace(/`([^`]+)`/g, '<code class="md-code">$1</code>');

        // 17. Double line breaks (\n\n) -> Spacious paragraph gap
        html = html.replace(/\n\n+/g, '<div class="md-gap"></div>');

        // 18. Single line breaks (\n) -> <br>
        html = html.replace(/\n/g, '<br>');

        // 19. Clean up extra <br> after block elements
        html = html.replace(/<\/(ul|ol|table|div|blockquote|hr)><br>/gi, '</$1>');
        html = html.replace(/<div class="md-gap"><\/div><br>/gi, '<div class="md-gap"></div>');

        return html;
    }

    // ─── Table Parser Helper ───
    function renderMarkdownTables(text) {
        // Match consecutive lines that contain pipe '|' characters
        const tableRegex = /((?:^[ \t]*\|[^\n]+\|[ \t]*(?:\r?\n|$))+)/gm;

        return text.replace(tableRegex, (match) => {
            const rawLines = match.trim().split(/\r?\n/).map(l => l.trim()).filter(Boolean);
            if (rawLines.length < 2) return match;

            // Check if second line is a separator like |---|---|
            const isSep = /^\|?[\s:-]+(?:\|[\s:-]+)+\|?$/.test(rawLines[1]);
            if (!isSep) return match;

            const headerRow = rawLines[0];
            const dataRows = rawLines.slice(2);

            const parseRow = (r) => {
                // Split by '|' and trim, remove leading/trailing empty elements
                let cells = r.split('|').map(c => c.trim());
                if (cells[0] === '') cells.shift();
                if (cells[cells.length - 1] === '') cells.pop();
                return cells;
            };

            const headerCells = parseRow(headerRow);
            const theadHtml = '<thead><tr>' + headerCells.map(c => `<th>${c}</th>`).join('') + '</tr></thead>';

            const tbodyRows = dataRows.map(r => {
                const cells = parseRow(r);
                return '<tr>' + cells.map(c => `<td>${c}</td>`).join('') + '</tr>';
            }).join('');
            const tbodyHtml = '<tbody>' + tbodyRows + '</tbody>';

            return `\n\n<div class="md-table-wrapper"><table class="md-table">${theadHtml}${tbodyHtml}</table></div>\n\n`;
        });
    }

    // ─── Quick Replies ───
    function showQuickReplies() {
        removeQuickReplies();

        const container = document.createElement('div');
        container.className = 'quick-replies';
        container.id = 'quick-replies';

        quickReplies.forEach((qr) => {
            const btn = document.createElement('button');
            btn.className = 'quick-reply-btn';
            btn.textContent = qr.text;
            btn.addEventListener('click', () => {
                sendMessage(qr.message);
            });
            container.appendChild(btn);
        });

        messagesArea.appendChild(container);
        scrollToBottom();
    }

    function removeQuickReplies() {
        const existing = document.getElementById('quick-replies');
        if (existing) existing.remove();
    }

    // ─── UI Helpers ───
    function appendMessage(text, sender) {
        const messageDiv = document.createElement('div');
        messageDiv.className = `message ${sender}-message`;

        const contentDiv = document.createElement('div');
        contentDiv.className = 'message-content';

        if (sender === 'bot') {
            contentDiv.innerHTML = renderMarkdown(text);
        } else {
            contentDiv.textContent = text;
        }

        messageDiv.appendChild(contentDiv);
        messagesArea.appendChild(messageDiv);

        scrollToBottom();
        return contentDiv;
    }

    function showTypingIndicator() {
        const indicatorDiv = document.createElement('div');
        indicatorDiv.className = 'typing-indicator';
        indicatorDiv.innerHTML = `
            <div class="typing-dot"></div>
            <div class="typing-dot"></div>
            <div class="typing-dot"></div>
        `;
        messagesArea.appendChild(indicatorDiv);
        return indicatorDiv;
    }

    function scrollToBottom() {
        requestAnimationFrame(() => {
            messagesArea.scrollTop = messagesArea.scrollHeight;
        });
    }

    // ─── Safe Element Removal (idempotent) ───
    function safeRemove(element) {
        if (element && element.parentNode) {
            element.remove();
        }
    }

    // ─── Sleep utility ───
    function sleep(ms) {
        return new Promise(resolve => setTimeout(resolve, ms));
    }

    // ─── UUID Fallback ───
    function generateUUID() {
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
            const r = (Math.random() * 16) | 0;
            const v = c === 'x' ? r : (r & 0x3) | 0x8;
            return v.toString(16);
        });
    }
});
