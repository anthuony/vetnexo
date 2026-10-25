# VetNexo launch package

- `site/`: English marketing landing page and interactive fictional-data demo; intended for GitHub + Cloudflare Pages.
- `app/`: rebranded PHP application source. Original SQL dumps, private backups, payment proof images and uploaded files were excluded.

## Cloudflare Pages + GitHub
1. Review the package locally and keep the repository private until the audit is complete.
2. Push the `site/` folder to a GitHub repository (or push the package and set the Pages root/output directory to `site`).
3. Cloudflare Dashboard → Workers & Pages → Create → Pages → Connect to Git. Select the repo. For static HTML, leave build command blank; set output directory to `site` (or `/` if the repository root is the contents of `site`).
4. Deploy and test the `pages.dev` URL. Attach a custom domain only after confirming ownership and contact details.
5. The demo contact form currently prepares an email to the reserved `.example` address. Replace it with a verified business email or secure CRM endpoint before public launch.

## PHP app hosting
Cloudflare Pages does not execute PHP/MySQL. Host `app/` separately on managed PHP hosting or a server with PHP 8.1+, Apache/Nginx and MySQL/MariaDB. Use a separate database, HTTPS, environment-based secrets, secure cookies, monitoring and backups.

## Readiness and safety
The public demo is a front-end simulation and does not authenticate or persist data. The PHP app is rebranded but not production-certified. Do not use real patient/client data until independent security, privacy, legal and clinical-workflow review and hardening. Confirm all feature claims and service scope before taking payment. Pricing is a proposed offer, not a verified market benchmark. VetNexo is provisional; check trademark, domain and social-handle availability.
