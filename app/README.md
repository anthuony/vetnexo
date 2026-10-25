# VetNexo — PHP application prototype

This folder contains the rebranded PHP source. Original private backups, payment proofs, uploaded files, and data-bearing SQL dumps were excluded.

## Local requirements
- Apache 2.4; PHP 8.1+; MySQL 8 / MariaDB 10.6+
- PHP extensions: pdo_mysql, mysqli, mbstring, dom, fileinfo

## Local setup
1. Copy this folder into your local Apache document root as `vetnexo`.
2. Create a new empty database and configure `config/env.php` with local credentials. `config/env.php` is intentionally not included; start from `config/env.example.php`.
3. Review the installer and structure-only SQL before use. The included schema has no original client records or complete sample dataset.
4. Visit `/vetnexo/instalar.php` from localhost and create a new administrator.

## Production warning
This prototype has not undergone a full security, privacy, legal or clinical workflow audit. Do not use real clinic/patient data until independent review and hardening. Cloudflare Pages does not run PHP/MySQL; host this app separately on PHP-compatible hosting. The static marketing site is in `../site`.
