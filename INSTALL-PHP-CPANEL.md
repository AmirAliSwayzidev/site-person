# نصب PHP روی cPanel

1. ZIP را در `public_html` استخراج کنید.
2. در cPanel یک PostgreSQL Database و User بسازید.
3. محتوای `schema.sql` را روی دیتابیس اجرا کنید.
4. `.env.example` را به `.env` تغییر نام دهید و `DATABASE_URL`، `APP_SECRET` و `ADMIN_EMAIL` را وارد کنید.
5. برای ایمیل: `RESEND_API_KEY` و `MAIL_FROM` را تنظیم کنید.
6. PHP 8.x را انتخاب کنید و افزونه‌های `PDO_PGSQL`، `cURL` و `FileInfo` را فعال کنید.
7. SSL/HTTPS را فعال کنید.
8. `https://YOUR-DOMAIN/api/health` را باز کنید؛ باید `ok:true` و `database:true` ببینید.
9. دامنه یا Subdomain را در cPanel روی همان `public_html`/Document Root تنظیم کنید.

**نکته:** `server.js`, `package.json`, `package-lock.json` و `vercel.json` عمداً در نسخه PHP وجود ندارند. HTML/CSS/JS فرانت‌اند به PHP تبدیل نشده‌اند.
