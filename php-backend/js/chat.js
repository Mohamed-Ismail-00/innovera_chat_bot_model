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

    // ─── Spacious Markdown Renderer ───
    function renderMarkdown(text) {
        if (!text) return '';

        let html = text;

        // 1. Remove non-Arabic/English artifacts like Chinese characters
        html = html.replace(/[\u4e00-\u9fa5]/g, '');

        // 2. Escape HTML special characters
        html = html.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

        // 3. Fix headings (#, ##, ###, #### with or without space) -> Block elements with margins
        html = html.replace(/^#{1,6}\s*(.+)$/gm, '<strong style="display:block;font-size:15px;margin:14px 0 6px;color:#0f1b3d;border-bottom:1px solid #e2e8f0;padding-bottom:3px;">$1</strong>');

        // 4. Bold: **text** or __text__
        html = html.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
        html = html.replace(/__(.+?)__/g, '<strong>$1</strong>');

        // 5. Italic: *text* or _text_
        html = html.replace(/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/g, '<em>$1</em>');

        // 6. Markdown Links: [text](url)
        const links = [];
        html = html.replace(/\[([^\]]+)\]\(([^)]+)\)/g, (match, p1, p2) => {
            const index = links.length;
            links.push(`<a href="${p2}" target="_blank" rel="noopener">${p1}</a>`);
            return `___LINK_${index}___`;
        });

        // 7. Bare Email addresses
        html = html.replace(
            /([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/g,
            '<a href="mailto:$1">$1</a>'
        );

        // 8. Restore Markdown Links
        html = html.replace(/___LINK_(\d+)___/g, (match, p1) => links[parseInt(p1, 10)] || '');

        // 9. Bullet lists: lines starting with - or • or *
        html = html.replace(/^[\s]*[-•*]\s+(.+)$/gm, '<li style="margin-bottom:6px;">$1</li>');
        html = html.replace(/(<li style="margin-bottom:6px;">.*<\/li>\n?)+/g, '<ul style="margin:10px 0;padding-right:20px;">$&</ul>');

        // 10. Numbered lists: lines starting with 1. 2. etc
        html = html.replace(/^[\s]*\d+\.\s+(.+)$/gm, '<li style="margin-bottom:6px;">$1</li>');

        // 11. Inline code: `text`
        html = html.replace(/`([^`]+)`/g, '<code style="background:rgba(15,27,61,0.08);padding:3px 6px;border-radius:4px;font-size:12px;">$1</code>');

        // 12. Double line breaks (\n\n) -> Spacious paragraph gap
        html = html.replace(/\n\n+/g, '<div style="height:12px;"></div>');

        // 13. Single line breaks (\n) -> <br>
        html = html.replace(/\n/g, '<br>');

        // 14. Clean up extra <br> after lists or div breaks
        html = html.replace(/<\/ul><br>/g, '</ul>');
        html = html.replace(/<\/div><br>/g, '</div>');

        return html;
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
