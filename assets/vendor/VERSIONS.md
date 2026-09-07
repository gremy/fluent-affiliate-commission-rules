# Vendored front-end assets

No build step: these files are committed as downloaded and enqueued as plain
`<script>`/`<link>` tags by `includes/Admin/Menu.php`. Re-download from the URLs
below to update; never edit them in place.

| File | Package | Version | Source |
|---|---|---|---|
| `vue.global.prod.js` | vue | **3.5.17** (matches the Vue Fluent Affiliate 1.6.5 ships) | https://cdn.jsdelivr.net/npm/vue@3.5.17/dist/vue.global.prod.js |
| `element-plus.ro.min.js` | element-plus locale | **2.9.11** | https://cdn.jsdelivr.net/npm/element-plus@2.9.11/dist/locale/ro.min.js |
| `element-plus.full.min.js` | element-plus | **2.9.11** | https://cdn.jsdelivr.net/npm/element-plus@2.9.11/dist/index.full.min.js |

Element Plus component CSS is deliberately **not** vendored: the admin page
loads Fluent Affiliate's own tree-shaken `assets/admin/app.min.css` and theme
`assets/admin/admin.css`, so our screen inherits their look and dark mode.
Fluent's two admin stylesheets style every Element Plus component the app
renders; a smoke test fails the build if that ever stops being true.

When bumping Element Plus, stay on a release whose class names match the ones
in Fluent's `app.min.css` (2.9.x as of Fluent Affiliate 1.6.5), and re-run
`tests/test-assets.php` after updating the version literals there.
