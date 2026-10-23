# Topper Digital — topperdigital.xyz

Website of topper., a boutique VFX and motion design studio in Lisbon.

- `index.html`, `site.css`, `js/main.js` — the front page. `js/scene.js` is the 3D stage: a top hat (sketched in code with three.js 0.160, self-hosted in `vendor/three/`) that follows the page as it scrolls and lands in the logo at the end.
- `vendor/fonts/` — IBM Plex Mono (SIL OFL), self-hosted so the site loads no third-party fonts.
- `assets/` — brand files, portfolio videos and posters.
- `admin/` — the private admin area (PHP). Copy `admin/inc/config.example.php` to `admin/inc/config.php` on the server and fill in the database credentials; `config.php` is never committed.

The licensed fonts (`assets/fonts/FuturaNowHeadline-*.woff2`) are not in this repository; they live on the server only.
