# Enable customer accounts on Hostinger

The login UI and PHP backend are in Git. Real accounts cannot be saved until a database is connected. No OTP or email provider is used yet; sign-in currently uses email and password, without email/phone ownership verification or automated password recovery.

## One-time hosting setup

1. Open **Websites → halsolution.com → Manage site → Databases → Management**.
2. Create a new empty MySQL database and database user with a strong password. Record the **full** database and user names, including Hostinger's account prefix.
3. Open **Files → File Manager**. Go one level above this website's `public_html` directory and create **`hal-account-config.php`**. If only website files are visible, use **All hosting files** and open this domain's directory.
4. Copy the contents of `api/config.example.php` into that file, then replace the placeholder database name, username, and password with the values from step 2. Normally `host` is `localhost` and `port` is `3306`; use the values shown by Hostinger if different.
5. Save/deploy that private file, then open `/login.html` on the HTTPS website. Account tables are created automatically in the new database. Register a test account, save profile details, sign out, and sign in again to confirm persistence.

The config must be **outside `public_html`**, next to it, so Git deployments do not overwrite it and web requests cannot download it. Do not put passwords in README files, screenshots, GitHub, or browser-side JavaScript.

If your hosting configuration cannot read the private sibling file, the backend also accepts `api/config.local.php` (ignored by Git and blocked by `api/.htaccess`) or the server environment variables `HAL_DB_HOST`, `HAL_DB_PORT`, `HAL_DB_NAME`, `HAL_DB_USER`, and `HAL_DB_PASSWORD`. Prefer the private sibling file on Hostinger PHP hosting. Back up the database and private config separately from Git.

## Included behavior

- Header user icon and mobile account links on public pages.
- Email/password registration, sign-in, sign-out, and session-based access to the profile.
- Name, optional Indian mobile number, address, city, and pincode stored in MySQL.
- Server-side validation, prepared queries, password hashing, session-ID rotation, HttpOnly/SameSite cookies, CSRF protection, and database-backed request limits.
- Profile updates always use the authenticated session ID; client-supplied user IDs are ignored.
- No passwords, user profiles, or session tokens are stored in browser localStorage.

## Validation

The integration checks in `tests/test_accounts.py` run against a disposable LOCAL MariaDB database and a PHP server on `127.0.0.1:8087`. They create synthetic test accounts and verify registration, login/logout, persistence, CSRF rejection, session rotation, validation, cross-account isolation, and login rate limits.

The live Hostinger database connection still needs the one-time setup above. Browser rendering could not be verified because the browser connection is unavailable in this environment. OTP, password recovery, and booking persistence are separate follow-up work.
