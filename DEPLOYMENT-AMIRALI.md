# Deployment — PHP/cPanel

این نسخه برای Apache/cPanel و PHP 8.x آماده شده است. Node.js/npm لازم نیست.

- Document Root: `public_html`
- Database: PostgreSQL + `pdo_pgsql`
- Required PHP extensions: `pdo_pgsql`, `curl`, `fileinfo`
- Run `schema.sql`
- Configure `.env`
- Enable HTTPS
- Test `/api/health`

برای جزئیات، `INSTALL-PHP-CPANEL.md` را بخوانید.
