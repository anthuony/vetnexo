# VetNexo — complete launch package

This package contains both the public commercial site and the source application.

## What is included
- `site/index.html`: English commercial landing page and proposed plans.
- `site/demo.html`: separate interactive demo with fictional sample records. Navigation, filters and add-sample actions work in the browser; changes are temporary and are not saved.
- `site/assets/`: CSS, JavaScript, logo and bundled local veterinary photo. The landing and demo do not depend on remote image URLs.
- `app/`: PHP application source, client/shop modules, local CSS/JS, configuration template and database structure file.
- `DEPLOYMENT.md`: hosting and setup notes.

## Important distinction
The browser demo is an interactive front-end preview, not a live connection to the PHP/MySQL application. The full PHP application requires a PHP + MySQL host and database setup. Cloudflare Pages and GitHub Pages serve static files and do not execute PHP. The `Full app` link on the demo works only when this package is hosted under a PHP-capable server with the database configured.

## Run the static site locally
Open `site/index.html` in a browser, or serve the `site/` folder with any static web server. The assets are local and use relative paths, so they also work under GitHub Pages repository subpaths.

## Run the full PHP app locally
1. Copy `app/` into the document root of Apache (XAMPP/WAMP).
2. Create a MySQL database and import `app/database/schema_clean_structure_only.sql` if it matches your required modules; verify any migration requirements before use.
3. Copy `app/config/env.example.php` to `app/config/env.php`, then configure DB credentials. Never upload real credentials.
4. Visit `/app/login.php`.

## Before selling or going live
- Confirm the database schema and test every module on a fresh database.
- Replace the demo mail address `hello@vetnexo.example` with a verified business address or CRM endpoint.
- Complete a security/privacy audit, including authentication, access control, file uploads, backups, CSRF protection, error handling and patient data privacy.
- The archive deliberately excludes private backups, uploaded payment proofs, production database dumps and environment secrets.
- Do not use real client or patient data in the public sample demo.
