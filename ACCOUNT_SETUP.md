# Enable customer accounts on Hostinger

Customer sign-in now uses phone OTP, with automatic phone-only account creation and persistent pickup orders. See [PICKUP_SETUP.md](PICKUP_SETUP.md) for SMS setup and free WhatsApp sharing. The email/password API is retained for existing accounts.

## One-time hosting setup

1. Open **Websites → halsolution.com → Manage site → Databases → Management**.
2. Create a new empty MySQL database and database user with a strong password. Record the **full** database and user names, including Hostinger's account prefix.
3. Open **Files → File Manager**. Go one level above this website's `public_html` directory and create **`hal-account-config.php`**. If only website files are visible, use **All hosting files** and open this domain's directory.
4. Copy the contents of `api/config.example.php` into that file, then replace the placeholder database name, username, and password with the values from step 2. Normally `host` is `localhost` and `port` is `3306`; use the values shown by Hostinger if different.
5. Save/deploy that private file, then complete provider setup in `PICKUP_SETUP.md`. Open `/login.html` on the HTTPS website, verify your phone, save profile details, sign out, and sign in again to confirm persistence. Tables are created automatically.

The config must be **outside `public_html`**, next to it, so Git deployments do not overwrite it and web requests cannot download it. Do not put passwords in README files, screenshots, GitHub, or browser-side JavaScript.

If your hosting configuration cannot read the private sibling file, the backend also accepts `api/config.local.php` (ignored by Git and blocked by `api/.htaccess`) or the server environment variables `HAL_DB_HOST`, `HAL_DB_PORT`, `HAL_DB_NAME`, `HAL_DB_USER`, and `HAL_DB_PASSWORD`. Prefer the private sibling file on Hostinger PHP hosting. Back up the database and private config separately from Git.

## Included behavior

- Header user icon and mobile account links on public pages.
- Phone OTP sign-in, sign-out, session-based profiles and order history. Existing email/password API accounts remain supported.
- Name, optional Indian mobile number, address, city, and pincode stored in MySQL.
- Server-side validation, prepared queries, password hashing, session-ID rotation, HttpOnly/SameSite cookies, CSRF protection, and database-backed request limits.
- Profile updates always use the authenticated session ID; client-supplied user IDs are ignored.
- No passwords, user profiles, or session tokens are stored in browser localStorage.

## Validation

The integration checks in `tests/test_accounts.py` run against a disposable LOCAL MariaDB database and a PHP server on `127.0.0.1:8087`. They create synthetic test accounts and verify registration, login/logout, persistence, CSRF rejection, session rotation, validation, cross-account isolation, and login rate limits.

The live Hostinger database connection still needs the one-time setup above. The new OTP, booking and profile flow has also been verified in a browser with local synthetic providers. Phone OTP and booking persistence are now implemented; configure providers using `PICKUP_SETUP.md`. Password recovery remains separate follow-up work.
