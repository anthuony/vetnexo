# VetNexo demo routing fix

The previous `/demo` rewrite was removed because the deployed `workers.dev` URL returned `ERR_TOO_MANY_REDIRECTS`.

Use the direct static page URL:
- `https://YOUR-SITE.workers.dev/demo.html`

Landing page calls already link directly to `demo.html`. For Cloudflare Pages, set the output directory to `site` and deploy the contents of this folder. For a Cloudflare Worker, ensure static assets are served from the `site` directory and do not configure a redirect from `/demo` back to `/demo`.

The demo is an interactive front-end preview with fictional data. The full PHP app under `app/` requires PHP/MySQL hosting; it cannot run as PHP on a static Pages site.
