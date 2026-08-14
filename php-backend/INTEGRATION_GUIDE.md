# 🔗 دليل ربط Innovera AI Chatbot بالموقع
### موجّه لفريق الـ Backend

---

## 📋 الملخص السريع

الشات بوت **PHP بالكامل** — لا يحتاج Python أو Node.js.
الربط بالموقع = **رفع مجلد + 3 أسطر HTML** في أي صفحة.

---

## الخطوة 1: رفع الملفات على السيرفر

### 1.1 ارفع مجلد `php-backend/` بالكامل على السيرفر

```
عبر FTP أو cPanel File Manager:
ارفع المجلد إلى → /public_html/chatbot/
```

### 1.2 الهيكل النهائي على السيرفر:

```
/public_html/
├── index.php              ← الموقع الحالي
├── about.php
├── courses.php
│
└── chatbot/               ← مجلد الشات بوت (الجديد)
    ├── .env               ← مفتاح Groq API
    ├── .htaccess
    ├── api/
    │   ├── chat.php        ← الـ Endpoint الرئيسي
    │   └── health.php
    ├── includes/
    ├── config/
    ├── data/
    │   └── courses_data.json
    ├── storage/
    │   └── sessions/       ← لازم يكون قابل للكتابة (chmod 755)
    ├── css/
    ├── js/
    └── index.html
```

### 1.3 صلاحيات المجلدات (مهم!)

```bash
chmod 755 /public_html/chatbot/storage/sessions/
```

---

## الخطوة 2: إعداد ملف .env

افتح ملف `/public_html/chatbot/.env` وتأكد من وجود الـ API Key:

```env
GROQ_API_KEY=gsk_xxxxxxxxxxxxxxxxxxxxxxxxxxxxx
GROQ_MODEL=llama-3.1-8b-instant
```

ملف .htaccess يمنع الوصول المباشر لملف .env من المتصفح تلقائياً.

---

## الخطوة 3: اختبر إن السيرفر شغال

افتح في المتصفح:
```
https://www.innoveracorp.com/chatbot/api/health.php
```

لازم يرجع:
```json
{
    "status": "ok",
    "service": "Innovera AI Chatbot (PHP)",
    "curl": "available",
    "data": "loaded",
    "api_key": "configured"
}
```

لو api_key ظهر "MISSING" → راجع ملف .env
لو curl ظهر "missing" → فعّل extension في php.ini على السيرفر

---

## الخطوة 4: ربط الشات بوت بأي صفحة في الموقع

### أضف هذه الأسطر الثلاثة قبل </body> مباشرة:

```html
<!-- Innovera AI Chatbot -->
<div id="innovera-chatbot" data-api-url="/chatbot/api/chat.php"></div>
<link rel="stylesheet" href="/chatbot/css/style.css">
<script src="/chatbot/js/chat.js"></script>
```

### مثال عملي على صفحة PHP:

```php
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <title>Innovera - الصفحة الرئيسية</title>
</head>
<body>

    <header>...</header>
    <main>...</main>
    <footer>...</footer>

    <!-- الشات بوت — أضف هذه الأسطر الثلاثة فقط -->
    <div id="innovera-chatbot" data-api-url="/chatbot/api/chat.php"></div>
    <link rel="stylesheet" href="/chatbot/css/style.css">
    <script src="/chatbot/js/chat.js"></script>

</body>
</html>
```

كده الزرار الدائري هيظهر في الزاوية السفلية اليمنى تلقائياً!

---

## متطلبات السيرفر

| المتطلب | القيمة |
|---------|--------|
| PHP | 8.1 أو أحدث |
| cURL Extension | مطلوب |
| mbstring Extension | مطلوب |
| openssl Extension | مطلوب |
| Apache mod_rewrite | مطلوب |
| اتصال إنترنت | مطلوب |

---

## حل المشاكل الشائعة

### مشكلة: الشات مش بيرد
1. افتح /chatbot/api/health.php وتأكد كل حاجة "ok"
2. تأكد إن GROQ_API_KEY صحيح في .env
3. تأكد إن السيرفر عنده اتصال إنترنت

### مشكلة: Error 403 أو 500
1. تأكد من صلاحيات مجلد storage/sessions/ (755)
2. تأكد إن mod_rewrite مفعّل
3. راجع لوج الأخطاء: /var/log/apache2/error.log

### مشكلة: الرد مقطوع
لو في Nginx كـ reverse proxy، أضف في nginx.conf:
```
proxy_buffering off;
fastcgi_buffering off;
```

---

## الـ API Reference

### POST /chatbot/api/chat.php

Request:
```json
{
    "message": "ما هي شراكات Innovera؟",
    "session_id": "uuid-string"
}
```

Response: text/event-stream (SSE)
```
data: نص الرد حرف بحرف...

data: [DONE]
```

Error Responses:
| Status | السبب |
|--------|-------|
| 400 | رسالة فارغة أو طويلة |
| 429 | تجاوز حد الـ Rate Limit |
| 405 | استخدم POST مش GET |

### GET /chatbot/api/health.php

يرجع JSON بحالة السيرفر.
