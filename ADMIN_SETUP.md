# Website manager

Open `/admin` (it redirects to `/admin/`). No public navigation button is added.
The fixed account is `admin@gmail.com`; sign-in asks only for the owner's initial password.
Use the **Password** tab to replace that initial password. Customer OTP sessions cannot access this panel.

## What can be edited

- All scrap products: price, unit, category, name, description, badge, image, visibility.
- Add new products to the existing seven categories.
- Upload JPG/PNG/WebP images (up to 3 MB) or use an HTTPS image URL.
- Contact phone/WhatsApp number, email, office address, opening hours and contact person.
- Homepage heading/subtitle, footer banner heading/button and footer copyright.

Public cards, both calculators, saved estimates, contact links and receipts read the same published content.
Changes appear when a visitor loads or refreshes a page. Existing booked receipts remain estimates, not a price guarantee.
Leads and order management are outside this panel's scope.

## Hosting and persistence

PHP 8.1+ with writable storage is required. No MySQL database is needed for this content manager.
By default, data is saved to `hal-site-data/` next to `public_html/` (outside the public website).
This folder contains the published `content.json`, private changed-password hash and login rate-limit records.
Set `HAL_CONTENT_DIR` on the server if the hosting document root differs. Keep it outside the public document root.
Do not delete the folder when deploying. Back it up along with `public_html/images/uploads/`.
Git deployments update code and initial defaults; existing saved content and uploaded images are preserved.
Do not enable a deployment option that cleans untracked uploaded files.

Security includes separate HttpOnly admin sessions, 30-minute idle expiry, 8-hour maximum sessions, CSRF tokens,
password hashing, login throttling (5 attempts per IP in 15 minutes), validated uploads and revision checks to prevent lost edits.
The original sample `admin.html` now redirects to the protected panel.

## Local verification

Start PHP with `HAL_CONTENT_DIR` pointing at `.local-tests/admin-data` and document root at this repository.
Run `python tests/test_admin.py http://127.0.0.1:8090` against that disposable preview only.
The integration test temporarily edits its content and restores the starting catalog/settings.
