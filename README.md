# HAL Solution

HTML, CSS, JavaScript, and PHP website for HAL Solution. The public pages have no build step. Account creation, login, and profile saving require PHP 8.1+ and MySQL/MariaDB; see [ACCOUNT_SETUP.md](ACCOUNT_SETUP.md).

## Hostinger deployment

Connect this repository to a custom PHP/HTML website using **Advanced → Git**. Deploy the `main` branch to `public_html`. Website files, including `index.html` and `images/`, are at the repository root.

Edit the root website files for future changes. The original ZIP files and extracted reference folder are excluded from Git.

## Functionality

Customer sign-in now uses an SMS OTP: verifying a mobile number creates or opens its account. Customers can select scrap, add an address or GPS pin, place a persistent pickup request, and view their orders in the profile. Existing email/password API accounts remain supported. Public Ecosystem links are commented out.

Configure MSG91 SMS OTP before enabling live sign-in; see [PICKUP_SETUP.md](PICKUP_SETUP.md). After saving an order, customers can send its details to the business through a prefilled WhatsApp link and download a private printable receipt. The customer must tap Send in WhatsApp; no paid WhatsApp API is used and no automatic WhatsApp notification is sent.

Change the business phone in one place: `business-config.json`. Public call/WhatsApp links, order sharing and receipts use this value. The supplied brand image is `images/logo.png`. Business content is sourced from the existing website; see [BUSINESS_DETAILS.md](BUSINESS_DETAILS.md).

Email ownership verification, password-reset delivery, callback delivery, and protected admin access still require integration. Admin/enterprise content is sample data. The contact-page location flow still includes a placeholder address; the new booking flow does not fabricate an address.
