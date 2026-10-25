# Deployment guide

## Public landing + interactive sample demo
Deploy the `site/` directory to Cloudflare Pages, GitHub Pages, or another static host. For Cloudflare Pages, set the output/root directory to `site`. All images are bundled locally in `site/assets/clinic-care.jpg`; no external image CDN is required. `index.html` and `demo.html` use relative paths for compatibility with repository subpaths.

## Full PHP application
The PHP app is in `app/` and must be deployed separately to Apache/PHP + MySQL hosting. Static hosting will not execute PHP. Configure the database, app URL, PHP version and write permissions using the installation instructions before exposing it publicly.

## Demo behavior
The public demo is a front-end simulation using fictional data. It supports switching sections, filtering table rows, and adding temporary sample rows. It does not persist records and is not connected to the PHP app or any database. The `Full app` link works only if the `app/` folder is deployed under the same PHP-enabled web root.

## Pre-launch checklist
- [ ] Test database import on a clean database and verify migrations.
- [ ] Set environment-specific database credentials outside public source control.
- [ ] Replace the `.example` contact email with a verified address.
- [ ] Test mobile layout and image loading on the target domain.
- [ ] Audit security, privacy, authentication, roles, file uploads and backup restore.
- [ ] Confirm the commercial plan/pricing and implementation scope in writing.
