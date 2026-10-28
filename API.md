# API

Base: `/api`

- `GET /api/health`
- `POST /api/auth/send-code`
- `POST /api/auth/verify-code`
- `POST /api/auth/register`
- `POST /api/auth/login`
- `POST /api/auth/logout`
- `GET /api/auth/me`
- `POST /api/auth/change-password`
- `POST /api/auth/reset-password`
- `POST /api/contact`
- `POST /api/orders` (multipart/form-data, attachment max 8MB)
- `GET /api/orders`
- `GET /api/admin/orders`
- `PATCH /api/admin/orders/{id}`
- `GET /api/admin/orders/{id}/attachment`
- `GET /api/admin/contacts`
- `GET /api/admin/users`
- `POST /api/admin/users`
- `PATCH /api/admin/users/{id}/role`
- `POST /api/admin/users/{id}/reset-password`
- `POST /api/admin/agent`

Authentication uses a secure PHP session cookie; the browser-side API contract remains compatible with the existing frontend.
