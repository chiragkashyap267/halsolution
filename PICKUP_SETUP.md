# Phone OTP, pickup orders and free WhatsApp sharing

SMS OTP credentials are intentionally blank for now. Login and booking show an OTP setup pending placeholder with a WhatsApp contact link; no mock OTP or authentication bypass is enabled. Authenticated ordering becomes available when the private SMS and database configuration is completed. No real customer SMS messages have been sent during development.

## Customer flow

1. Open `login.html` or `booking.html` and verify an Indian mobile number using a six-digit SMS OTP. A phone-only account is created, or the existing phone account opens.
2. Select scrap and optional approximate quantities. Enter an address/city/pincode or add a real GPS pin. Choose an optional pickup date within the next 30 days and a preferred time.
3. Place the order. The request is saved in MySQL with a unique HAL reference. Repeating the same request does not create a duplicate order.
4. Select **Send order on WhatsApp** to open a prefilled message addressed to the business. The customer must tap **Send inside WhatsApp**. This is a normal customer-initiated WhatsApp link: there is no paid WhatsApp API, no automatic customer confirmation, and no automatic owner alert. If the customer does not send it, the order still exists in the database/profile, but no WhatsApp message arrives.
5. Select **Download receipt** to download a self-contained, printable HTML receipt with the logo, order details and business contact information. Open it in a browser and use Print / Save as PDF if needed. Receipts require an authenticated account and only the owner of an order can download its receipt.
6. The receipt acknowledges the pickup request. It is not a tax invoice or proof of payment. Final weight, rates and payment are determined after collection/inspection. No final amount, GST number or paid status is invented.

The profile shows the latest 50 orders with the same WhatsApp and receipt actions. Pickup verification lasts 30 minutes; the account session lasts up to seven days. Reverify to place an order once verification expires. A verified account phone cannot be replaced through an ordinary profile edit.

## One place to change the business number

Edit `business-config.json`, specifically its `phone` field, to update all public call links, WhatsApp links, displayed business numbers, customer order-sharing links and receipt contact details. Use full country-code format, for example `+918851644415`. Email, company name and address for receipts are also in that file. This file contains public business information only.

`business.js` loads this shared config. `order-actions.js` creates per-order WhatsApp messages. `images/logo.png` is the supplied brand image.

## SMS setup

Configure MySQL using `ACCOUNT_SETUP.md`, then add these values to the private `hal-account-config.php` above `public_html`:

- `otp_authkey`: MSG91 authentication key.
- `otp_template_id`: approved MSG91 OTP template ID.
- `otp_daily_limit`: maximum total sends per UTC day window, default 100.

See [MSG91 SendOTP](https://docs.msg91.com/otp/sendotp) and its [setup guide](https://msg91.com/help/sendotp/step-by-step-process-to-configure-otp). SMS OTP remains a paid provider service; choosing free WhatsApp sharing does not make SMS OTP free. No Twilio credentials, WhatsApp template approval or notification cron job is required for this flow.

PHP 8.1+, PDO MySQL, cURL, HTTPS and outbound access to `control.msg91.com` are required. The API also accepts `HAL_OTP_AUTHKEY`, `HAL_OTP_TEMPLATE_ID` and `HAL_OTP_DAILY_LIMIT`. Keep API keys and database credentials out of HTML, JavaScript and Git.

The server generates a code, sends it by SMS, and verifies a session-bound HMAC. Codes are not exposed in HTTP responses or application logs. CSRF, server-side validation and rate limits apply. If SMS is not configured, the application returns an unavailable message instead of a fake success.

Tables are created automatically. Phone sign-in may make legacy email/password columns nullable; the database user needs CREATE and ALTER privileges during initial setup. Existing email/password API accounts are retained. Existing accounts can link a verified phone while signed in; an unverified profile phone never causes automatic account merging.

## Verification

Run `python tests/test_pickups.py` using the local Windows/XAMPP setup. The suite starts a disposable local database on 3307, API copies on 8087, and a fake SMS provider on 8088. It replaces only the provider URL in disposable source copies. No live messages are sent and production code has no test-login bypass.

Checks cover OTP account creation/reuse, wrong/reused OTPs, cooldown, CSRF, authentication, cross-account isolation, phone-edit protection, validation, duplicate-order prevention, GPS-only locations, private receipt downloads and escaping receipt content. The existing account integration suite also runs. `--preview` keeps synthetic services available for browser QA until Enter is pressed.

Business details were taken from the existing website; see `BUSINESS_DETAILS.md`. The owner-supplied phone overrides the older number on that website.
