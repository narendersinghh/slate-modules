<?php
/**
 * Unit checks for the Site Migration parsers and guards (no WordPress needed).
 * Run from a Slate install that has this module in modules/:   php modules/wordpress-importer/tests/unit.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}
$root = dirname(__DIR__, 3);
require $root . '/core/bootstrap.php';
\Slate\DB::connect(SLATE_CONFIG['db']);
\Slate\Options::load();
\Slate\Modules::boot();

use SlateModules\WpImport\{Acf, Archive, Blocks, Data, Fetch, FormsMap, Loops, SeoMap, Shortcodes, SqlDump};

$fails = 0;
$n = 0;
$check = static function (string $name, bool $ok, string $detail = '') use (&$fails, &$n): void {
    $n++;
    echo ($ok ? 'ok   ' : 'FAIL ') . $name . ($ok || $detail === '' ? '' : "\n     " . $detail) . "\n";
    $fails += $ok ? 0 : 1;
};

// ---- SSRF guard
$f = new Fetch('https://example.com');
$check('fetch: own host', $f->allowed('https://example.com/wp-content/a.css'));
$check('fetch: www and subdomain', $f->allowed('https://www.example.com/x') && $f->allowed('https://cdn.example.com/x.jpg'));
$check('fetch: other host refused', !$f->allowed('https://evil.test/x') && !$f->allowed('https://example.com.evil.test/x'));
$check('fetch: schemes refused', !$f->allowed('file:///etc/passwd') && !$f->allowed('gopher://example.com/') && !$f->allowed('ftp://example.com/x'));
$check('fetch: credentials in URL refused', !$f->allowed('https://user:pass@example.com/'));
$check('fetch: private address refused for a public source', !(new Fetch('https://example.com'))->allowed('http://127.0.0.1/') && Fetch::isPrivateHost('169.254.169.254'));
$check('fetch: local test source may use its own private host', (new Fetch('http://wp-test.localhost'))->allowed('http://wp-test.localhost/x'));

// ---- uploads archive paths
$check('archive: uploads path normalised', Archive::relative('wp-content/uploads/2024/05/a.jpg') === '2024/05/a.jpg' && Archive::relative('2024/05/a.jpg') === '2024/05/a.jpg');
$check('archive: traversal refused', Archive::relative('../../etc/passwd') === null && Archive::relative('/abs/a.jpg') === null && Archive::relative('wp-content/uploads/../x.jpg') === null);
$check('archive: executable names refused', Archive::relative('wp-content/uploads/a.php') === null && Archive::relative('wp-content/uploads/shell.php.jpg') === null && Archive::relative('x/.htaccess') === null);

// ---- SQL dump: only INSERT rows of known tables, escapes, never executed
$sql = sys_get_temp_dir() . '/wpi-unit-' . bin2hex(random_bytes(3)) . '.sql';
file_put_contents($sql, "-- dump\nCREATE TABLE `xy_options` (option_id int);\nDROP TABLE IF EXISTS `xy_users`;\n"
    . "INSERT INTO `xy_options` (`option_id`, `option_name`, `option_value`, `autoload`) VALUES (1,'blogname','It\\'s \\\"quoted\\\"\\nline','yes'),(2,'_transient_x','skip','no'),\n(3,'wpseo_titles','a:1:{s:9:\\\"separator\\\";s:7:\\\"sc-pipe\\\";}','yes');\n"
    . "INSERT INTO `xy_postmeta` VALUES (1,10,'subtitle','A; tricky ) value'),(2,10,'_edit_lock','1');\n"
    . "INSERT INTO `xy_posts` (`ID`,`post_type`,`post_title`,`post_name`,`post_content`,`post_status`,`post_parent`,`menu_order`,`post_date_gmt`) VALUES (5,'wpcf7_contact_form','Contact','contact','[text* your-name]','publish',0,0,'2024-01-01 00:00:00');\n"
    . "UPDATE xy_users SET user_pass = 'x';\n");
Data::clear();
SqlDump::load($sql);
@unlink($sql);
$check('sql: string escapes', Data::option('blogname') === "It's \"quoted\"\nline", var_export(Data::option('blogname'), true));
$check('sql: transients skipped', Data::option('_transient_x') === null);
$check('sql: serialized option decoded', (Data::option('wpseo_titles')['separator'] ?? '') === 'sc-pipe');
$check('sql: meta with ; and ) inside a string', (Data::meta(10)['subtitle'] ?? '') === 'A; tricky ) value');
$check('sql: lock meta skipped', !isset(Data::meta(10)['_edit_lock']));
$check('sql: special post rows kept', (Data::row('wpost', 5)['type'] ?? '') === 'wpcf7_contact_form');
$check('sql: serialized objects never instantiated', Data::decode('O:8:"stdClass":0:{}') === null || is_string(Data::decode('O:8:"stdClass":0:{}')));

// ---- Gutenberg
$b = new Blocks();
$html = $b->render('<!-- wp:columns --><div class="wp-block-columns"><!-- wp:column --><div class="wp-block-column"><!-- wp:paragraph --><p>A</p><!-- /wp:paragraph --></div><!-- /wp:column --></div><!-- /wp:columns -->'
    . '<!-- wp:latest-posts {"postsToShow":3} /--><!-- wp:spacer {"height":"30px"} --><div style="height:30px" aria-hidden="true" class="wp-block-spacer"></div><!-- /wp:spacer -->'
    . '<!-- wp:embed {"url":"https://youtu.be/abcdefghijk"} --><figure class="wp-block-embed"><div class="wp-block-embed__wrapper">https://youtu.be/abcdefghijk</div></figure><!-- /wp:embed -->');
$check('blocks: columns mapped', str_contains($html, 'class="columns"') && str_contains($html, '<p>A</p>') && !str_contains($html, 'wp:'), $html);
$check('blocks: embed becomes iframe', str_contains($html, 'youtube-nocookie.com/embed/abcdefghijk'), $html);
$check('blocks: dynamic block reported', isset($b->dropped['latest-posts']));
$t = (new Blocks('template'))->render('<!-- wp:post-title {"level":1} /--><!-- wp:query {"query":{"perPage":4,"postType":"post","inherit":true}} --><div><!-- wp:post-template --><!-- wp:post-title {"isLink":true} /--><!-- /wp:post-template --></div><!-- /wp:query -->');
$check('blocks: template tags', str_contains($t, '<h1>{{title}}</h1>') && str_contains($t, '{{#posts}}') && str_contains($t, '<a href="{{url}}">{{title}}</a>'), $t);

// ---- shortcodes
$sc = new Shortcodes(static fn($p, $id) => $p === 'cf7' && $id === '7' ? 'contact' : null, static fn(array $ids) => '<div class="gallery">' . implode(',', $ids) . '</div>');
$out = $sc->convert('[vc_row][vc_column][vc_column_text]Hello [Updated][/vc_column_text][/vc_column][/vc_row][contact-form-7 id="7" title="x"][gallery ids="1,2"][caption align="alignleft"]<img src="a.jpg"> Cap[/caption]');
$check('shortcodes: builder wrappers unwrapped, text kept', str_contains($out, 'Hello [Updated]') && !str_contains($out, 'vc_'), $out);
$check('shortcodes: forms become Slate embeds', str_contains($out, '[form slug="contact"]'), $out);
$check('shortcodes: gallery and caption', str_contains($out, '<div class="gallery">1,2</div>') && str_contains($out, '<figcaption>Cap</figcaption>'), $out);

// ---- forms
$cf7 = FormsMap::cf7(7, 'Contact', ['_form' => "<label> Your name [text* your-name placeholder \"Jane\"] </label>\n[select topic \"A\" \"B|b\"]\n[acceptance ok] I agree [/acceptance]\n[submit \"Go\"]",
    '_mail' => ['recipient' => 'Sales <sales@example.test>, [_site_admin_email]', 'subject' => 'New: [your-name]']], '');
$check('forms: cf7 fields and order', array_column($cf7['fields'], 'type') === ['text', 'select', 'consent'], json_encode($cf7['fields']));
$check('forms: cf7 label, required, placeholder, choices', $cf7['fields'][0]['label'] === 'Your name' && $cf7['fields'][0]['required'] && $cf7['fields'][0]['placeholder'] === 'Jane' && $cf7['fields'][1]['choices'] === ['A' => 'A', 'b' => 'B']);
$check('forms: cf7 mail', $cf7['notify_to'] === 'sales@example.test' && $cf7['subject'] === 'New: {your-name}' && $cf7['submit'] === 'Go');
$el = FormsMap::elementor('1:a', ['form_name' => 'Quote', 'email_to' => 'q@example.test', 'form_fields' => [['custom_id' => 'budget', 'field_type' => 'select', 'field_label' => 'Budget', 'field_options' => "Small\nMedium|m"]]]);
$check('forms: elementor choices', $el['fields'][0]['choices'] === ['Small' => 'Small', 'm' => 'Medium'] && $el['notify_to'] === 'q@example.test');

// ---- ACF raw values
$acf = [['name' => 'chapters', 'type' => 'repeater', 'sub_fields' => [['name' => 'title', 'type' => 'text'], ['name' => 'pic', 'type' => 'image']]],
    ['name' => 'details', 'type' => 'group', 'sub_fields' => [['name' => 'year', 'type' => 'number']]],
    ['name' => 'sections', 'type' => 'flexible_content', 'layouts' => [['name' => 'quote', 'sub_fields' => [['name' => 'q', 'type' => 'textarea']]]]],
    ['name' => 'when', 'type' => 'date_picker'], ['name' => 'tags', 'type' => 'checkbox'], ['name' => 'rel', 'type' => 'relationship']];
$meta = ['chapters' => '2', 'chapters_0_title' => 'One', 'chapters_0_pic' => '11', 'chapters_1_title' => 'Two', 'chapters_1_pic' => '', 'details_year' => '2024',
    'sections' => serialize(['quote']), 'sections_0_q' => 'Hi', 'when' => '20240131', 'tags' => serialize(['a', 'b']), 'rel' => serialize(['5', '6'])];
$v = Acf::values($acf, $meta, ['media' => static fn($x) => (int)$x + 100, 'url' => static fn($u) => $u, 'html' => static fn($h) => $h, 'term' => static fn() => null, 'user' => static fn() => '']);
$check('acf: repeater rows', $v['chapters'] === [['title' => 'One', 'pic' => 111], ['title' => 'Two', 'pic' => null]], json_encode($v['chapters']));
$check('acf: group flattened', $v['details_year'] === 2024);
$check('acf: flexible content', $v['sections'] === [['layout' => 'quote', 'q' => 'Hi']]);
$check('acf: date, checkbox, relationship', $v['when'] === '2024-01-31' && $v['tags'] === ['a', 'b'] && $v['rel'] === ['old:5', 'old:6']);
$note = [];
$defs = Acf::fields($acf, [], [], $note);
$check('acf: definitions', array_column($defs, 'name') === ['chapters', 'details_year', 'sections', 'when', 'tags', 'rel'] && $defs[0]['type'] === 'repeater' && $defs[2]['sub_fields'][0]['name'] === 'layout');

// ---- SEO placeholders
$check('seo: Yoast variables', SeoMap::vars('%%title%% %%sep%% %%sitename%%') === '%title% %sep% %site%');
$check('seo: AIOSEO variables', SeoMap::vars('#post_title #separator_sa #site_title') === '%title% %sep% %site%');
$check('seo: unknown variables dropped', SeoMap::vars('%title% %pt_single% x') === '%title% x');

// ---- live loops in designed pages
$notes = [];
$doc = '<html><body><div class="elementor-posts-container"><article class="elementor-post type-post post-3"><h3 class="elementor-post__title"><a href="/a/">A</a></h3>'
    . '<div class="elementor-post__excerpt"><p>x</p></div></article><article class="elementor-post type-post post-4"><h3 class="elementor-post__title"><a href="/b/">B</a></h3></article></div></body></html>';
$out = Loops::dynamize($doc, $notes);
$check('loops: Elementor posts widget becomes a live query', str_contains($out, '{{#query type=post limit=2}}') && str_contains($out, '{{title}}') && substr_count($out, '<article') === 1, $out);

Data::clear();
echo "\n$n checks, $fails failed\n";
exit($fails ? 1 : 0);
