# HAL Solution

HTML, CSS, JavaScript, and PHP website for HAL Solution. The public pages have no build step. Account creation, login, and profile saving require PHP 8.1+ and MySQL/MariaDB; see [ACCOUNT_SETUP.md](ACCOUNT_SETUP.md).

## Hostinger deployment

Connect this repository to a custom PHP/HTML website using **Advanced → Git**. Deploy the `main` branch to `public_html`. Website files, including `index.html` and `images/`, are at the repository root.

Edit the root website files for future changes. The original ZIP files and extracted reference folder are excluded from Git.

## Functionality

Email/password accounts and persistent customer profiles are implemented with PHP sessions and MySQL. Configure the private database connection on Hostinger before enabling accounts. The old customer dashboard now forwards to the real profile page. Public Ecosystem links are commented out.

OTP, email ownership verification, password-reset delivery, persistent bookings, callback delivery, and protected admin access still require integration. Admin/enterprise content is sample data, and location handling still includes a placeholder address. Customer profile phone numbers are not OTP-verified.
