# AMIRALI-SITE — Admin/Header Fix

این نسخه برای اجرای مستقیم با Node/Express آماده شده است.

## اجرا
داخل پوشه‌ای که `package.json` قرار دارد:
```powershell
npm install
npm start
```

سپس:
- سایت: http://localhost:3000/
- ورود/ثبت‌نام: http://localhost:3000/auth
- حساب کاربری: http://localhost:3000/dashboard
- پنل مدیریت: http://localhost:3000/admin
- سلامت API و Neon: http://localhost:3000/api/health

## مهم
فایل `.env` نسخه قبلی خودت را نگه دار و جایگزین نکن. این ZIP عمداً `.env` واقعی را شامل نمی‌کند.
`ADMIN_EMAIL` باید برابر باشد با:
`mmasoomeh110@gmail.com`

مشکل اصلی این نسخه اصلاح شده: لینک‌ها و assetها دیگر نسبت به `/index.html/admin` ساخته نمی‌شوند؛ مسیرهای مدیریتی و حساب کاربری root-relative هستند. همچنین هدر بر اساس Session دکمه «حساب کاربری» و برای ادمین «مدیریت سایت» را نمایش می‌دهد.
