# Topper Digital — topperdigital.xyz

Website of topper., a boutique VFX and motion design studio in Lisbon.

- `index.html`, `site.css`, `js/main.js` — the front page. `js/scene.js` and `js/stage/` are the 3D stage (three.js 0.160, self-hosted in `vendor/three/`): the hat in daylight, the dive into it, the void of smoke and dust inside (services, the deck of films, the client wall), the way back out, and the hat landing in the logo at the end. The hat model is `assets/models/hat.glb`.
- `assets/` — brand files, client logos, the hat model, portfolio videos and posters.
- `admin/` — the private admin area (PHP). Copy `admin/inc/config.example.php` to `admin/inc/config.php` on the server and fill in the database credentials; `config.php` is never committed.

The licensed fonts (`assets/fonts/FuturaNowHeadline-*.woff2`) are not in this repository; they live on the server only.
