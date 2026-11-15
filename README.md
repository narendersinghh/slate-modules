# Slate modules

Official modules for [Slate CMS](https://github.com/narendersinghh/slate), plus the default module **registry**
(`registry.json`) every Slate site reads under **Modules → Available**.

| Module | Folder | What it does |
|---|---|---|
| Forms | `slate-forms/` | Form builder, submissions inbox, email notifications, spam filtering, CSV export |
| Site Migration | `wordpress-importer/` | Moves a WordPress/Elementor site to Slate with its content, SEO, URLs and design (`php bin/slate import:wordpress`) |
| Auto CPT Generator | `auto-cpt-generator/` | Builds post types, taxonomies and fields from a WordPress site, a WXR export, a JSON spec or a description (AI) |
| Comments | `slate-comments/` | Privacy-friendly comments with moderation, cache-safe spam protection, email notifications, webhooks and API output |
| Multilingual | `slate-multilingual/` | Languages with URL prefixes (`/es/…`), linked translations, hreflang, language switcher, string translations (needs Slate 0.12) |
| Shop | `slate-shop/` | Products, signed-cookie cart, checkout, orders, stock, manual / Stripe / Razorpay payments with verified webhooks |

## Install

In Slate: **Modules → Available → Install**, or **Modules → Add new → From GitHub** with a folder address such as
`https://github.com/narendersinghh/slate-modules/tree/main/slate-forms`, or over SSH:

```bash
php bin/slate module:install https://github.com/narendersinghh/slate-modules/tree/main/slate-forms
php bin/slate module:activate slate-forms
```

## Publishing a module here

1. Add a folder with `module.json` (with `slug`, `version`, `source`) and `module.php` — see Slate's `docs/modules.md`.
2. Add an entry to `registry.json` (`download` = the folder address).
3. Bump `version` in both places for every release; sites then see **Update** in Modules.

Anyone can run their own registry the same way: a public or private repository with a `registry.json`, added to a site
under **Modules → Sources**.
