# Testing the WordPress importer

A real WordPress (Docker) and a throwaway Slate site, both on one Docker network.

## 1. WordPress on port 8096

```sh
docker network create wp-imp-net
docker run -d --name wp-imp-db --network wp-imp-net -e MARIADB_ROOT_PASSWORD=root -e MARIADB_DATABASE=wp \
  -e MARIADB_USER=wp -e MARIADB_PASSWORD=wp mariadb:10.11
docker run -d --name wp-imp-wp --network wp-imp-net -p 8096:80 -e WORDPRESS_DB_HOST=wp-imp-db -e WORDPRESS_DB_USER=wp \
  -e WORDPRESS_DB_PASSWORD=wp -e WORDPRESS_DB_NAME=wp -v wp-imp-html:/var/www/html wordpress:6
# wp-cli helper
wp() { docker run --rm -i --network wp-imp-net --volumes-from wp-imp-wp --user 33 -e WORDPRESS_DB_HOST=wp-imp-db \
  -e WORDPRESS_DB_USER=wp -e WORDPRESS_DB_PASSWORD=wp -e WORDPRESS_DB_NAME=wp -e HOME=/tmp wordpress:cli wp "$@"; }
wp core install --url=http://wp-imp-wp --title="Acme Test Site" --admin_user=admin --admin_password=<choose> --admin_email=admin@example.test --skip-email
wp config set WP_ENVIRONMENT_TYPE local          # application passwords over plain http
wp rewrite structure '/%year%/%monthnum%/%postname%/' --hard
wp theme install hello-elementor --activate
wp plugin install elementor advanced-custom-fields custom-post-type-ui wpforms-lite redirection --activate
wp plugin install contact-form-7 --version=6.0.6 --activate   # newer versions need WordPress 7.1
wp plugin install wordpress-seo --version=25.0 --activate
wp redirection database install
wp eval-file - < seed-wordpress.php                  # test content (safe to re-run)
wp user application-password create admin slate --porcelain
```

The site URL is `http://wp-imp-wp` (the container name): Slate reaches it inside the network. With Docker Desktop the
host only reaches WordPress at `localhost:8096`, which is fine because the browser only needs Slate.

`seed-wordpress.php` creates: a CPT UI post type "book" (URL base `library`) and taxonomy "genre"; an ACF field group with
text, number, select, true/false, date, image, gallery, link, relationship, checkbox, repeater, group and flexible content
(Pro field types are written as ACF stores them, so the raw-meta path is tested on ACF free); 3 books, 3 posts (one sticky,
one by a second author, one draft) with comments; a Gutenberg page (columns, gallery, buttons, quote, list, table, embed,
separator, CF7 shortcode); a contact page with WPForms and CF7 shortcodes; an Elementor page (containers, heading, text,
button, image, icon list, CF7 shortcode widget, an Elementor Pro form in the data) set as the front page; Elementor kit
colours and fonts; an Elementor Theme Builder "single book" template with dynamic tags and a loop grid; a customised
block-theme "single" template with a Query Loop; two menus with nesting in two theme locations; Yoast per-item meta and
global settings; Redirection rules. A Rank Math redirection table is created by hand:

```sh
docker exec wp-imp-db mariadb -uwp -pwp wp -e "CREATE TABLE IF NOT EXISTS wp_rank_math_redirections (id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, sources text NOT NULL, url_to text NOT NULL, header_code smallint unsigned NOT NULL, hits bigint unsigned NOT NULL DEFAULT 0, status varchar(25) NOT NULL DEFAULT 'active', created datetime NOT NULL, updated datetime NOT NULL, last_accessed datetime NOT NULL); INSERT INTO wp_rank_math_redirections (sources,url_to,header_code,status,created,updated,last_accessed) VALUES ('a:1:{i:0;a:2:{s:7:\"pattern\";s:12:\"old-landing/\";s:10:\"comparison\";s:5:\"exact\";}}','http://wp-imp-wp/elementor-landing/',301,'active',NOW(),NOW(),NOW());"
```

Optional companion plugin: copy `companion/slate-export.php` to `wp-content/plugins/slate-export/` and `wp plugin activate slate-export`
(or download the zip from the Connect step of the wizard).

## 2. Slate on port 8097

```sh
T=test-env/wp-imp-slate
git -C repo archive HEAD | tar -x -C $T
rsync -a --inplace --exclude .git slate-modules/{wordpress-importer,auto-cpt-generator,slate-forms,slate-comments} $T/modules/
docker run -d --name wp-imp-slate --network wp-imp-net -p 8097:8097 -e SLATE_CI_PASS=ci-password-123 -e PHP_CLI_SERVER_WORKERS=4 \
  -v $PWD/$T:/app -w /app slate-php84 sh -c '[ -f config.php ] || php bin/ci-install http://localhost:8097; php -S 0.0.0.0:8097 dev-server.php'
for m in auto-cpt-generator wordpress-importer slate-forms slate-comments; do docker exec wp-imp-slate php bin/slate module:activate $m; done
```

Docker Desktop mounts can serve stale files: after changing module files run `rsync --inplace` again and `docker restart wp-imp-slate`.
(Install only when `config.php` is missing, as above, or a restart reinstalls the site.)

## 3. Runs

```sh
# Everything, live site (REST + application password + companion plugin)
docker exec wp-imp-slate php bin/slate import:wordpress --url=http://wp-imp-wp --user=admin --password=<app password> --everything --fresh
# Again: must update, never duplicate (compare a dump of posts/fields/SEO before and after)
docker exec wp-imp-slate php bin/slate import:wordpress --url=http://wp-imp-wp --user=admin --password=<app password> --everything
# 1.x behaviour (what an existing migration gets when it syncs): no 2.0 flags
docker exec wp-imp-slate php bin/slate import:wordpress --url=http://wp-imp-wp --user=admin --password=<app password>
# Offline: export file + database dump + uploads archive, with WordPress stopped (docker stop wp-imp-wp)
wp export --dir=/tmp --filename_format=export.xml
docker exec wp-imp-db mariadb-dump -uwp -pwp wp | gzip > database.sql.gz
docker exec wp-imp-wp tar -cf - -C /var/www/html wp-content/uploads | ...  # zip it as uploads.zip
docker exec wp-imp-slate php bin/slate import:wordpress --wxr=/tmp/export.xml --sql=/tmp/database.sql.gz --uploads=/tmp/uploads.zip --everything --fresh
# Parser and guard checks (no WordPress needed)
docker exec wp-imp-slate php modules/wordpress-importer/tests/unit.php
```

The wizard (Site Migration in the dashboard: Connect, Analyse, Choose, Import, Report) runs the same steps.

## 4. What to check

- Counts per type, media, terms, menus (nesting, locations), comments (replies), forms, templates, redirects.
- `/` (Elementor page): same design as WordPress at 1440 and 390 px; "Edit page" makes texts and images editable; the
  CF7 form is a Slate form.
- A book: ACF values (repeater rows, group fields, flexible layouts, gallery, relationship) in its custom fields; the imported
  "single book" template (switched off) previewed with `?slate_template=<id>`.
- Posts: Yoast title template, description, focus keyword, noindex and canonical; global SEO settings.
- Redirects: Rank Math and Redirection rules, pointed at the new addresses.
- A second run creates nothing new; designed pages edited in Slate are kept.
