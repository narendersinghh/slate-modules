<?php
/**
 * Test content for the Site Migration importer. Run inside a WordPress install with wp-cli:
 *   wp eval-file seed-wordpress.php
 * Needs: Elementor, Yoast SEO, ACF, CPT UI, Contact Form 7, WPForms Lite, Redirection (all free), theme Hello Elementor.
 * ACF Pro field types (repeater, group, flexible content, gallery) are written as ACF stores them, so the
 * importer's raw-meta path is tested even on ACF free.
 */

if (!defined('ABSPATH')) {
    exit;
}

function seed_img(string $name, array $rgb): int
{
    global $wpdb;
    $have = (int)$wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_title = %s ORDER BY ID LIMIT 1", ucfirst($name) . ' title'));
    if ($have) {
        return $have; // re-running the seed reuses the images
    }
    $f = sys_get_temp_dir() . '/' . $name . '.png';
    $im = imagecreatetruecolor(1200, 800);
    imagefilledrectangle($im, 0, 0, 1200, 800, imagecolorallocate($im, ...$rgb));
    imagestring($im, 5, 40, 40, strtoupper($name), imagecolorallocate($im, 255, 255, 255));
    imagepng($im, $f);
    require_once ABSPATH . 'wp-admin/includes/image.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    $id = media_handle_sideload(['name' => $name . '.png', 'tmp_name' => $f], 0, ucfirst($name) . ' title');
    update_post_meta($id, '_wp_attachment_image_alt', 'Alt for ' . $name);
    wp_update_post(['ID' => $id, 'post_excerpt' => 'Caption of ' . $name]);
    return (int)$id;
}

// ------------------------------------------------------------------ users
$jane = username_exists('jane') ?: wp_insert_user(['user_login' => 'jane', 'user_email' => 'jane@example.test', 'user_pass' => wp_generate_password(),
    'display_name' => 'Jane Writer', 'role' => 'editor', 'description' => 'Jane writes about books.']);

// ------------------------------------------------------------------ CPT UI
update_option('cptui_post_types', ['book' => ['name' => 'book', 'label' => 'Books', 'singular_label' => 'Book', 'description' => 'Our library',
    'public' => 'true', 'publicly_queryable' => 'true', 'show_ui' => 'true', 'show_in_rest' => 'true', 'rest_base' => '', 'has_archive' => 'true',
    'has_archive_string' => 'library', 'exclude_from_search' => 'false', 'capability_type' => 'post', 'hierarchical' => 'false', 'rewrite' => 'true',
    'rewrite_slug' => 'library', 'rewrite_withfront' => 'true', 'query_var' => 'true', 'menu_icon' => 'dashicons-book', 'supports' => ['title', 'editor', 'thumbnail', 'excerpt'],
    'taxonomies' => [], 'labels' => ['menu_name' => 'Books', 'add_new_item' => 'Add new book']]]);
update_option('cptui_taxonomies', ['genre' => ['name' => 'genre', 'label' => 'Genres', 'singular_label' => 'Genre', 'public' => 'true', 'hierarchical' => 'true',
    'show_ui' => 'true', 'show_in_rest' => 'true', 'rewrite' => 'true', 'rewrite_slug' => 'genre', 'object_types' => ['book']]]);
register_post_type('book', ['public' => true, 'label' => 'Books', 'has_archive' => 'library', 'rewrite' => ['slug' => 'library'], 'show_in_rest' => true,
    'supports' => ['title', 'editor', 'thumbnail', 'excerpt', 'custom-fields']]);
register_taxonomy('genre', ['book'], ['public' => true, 'hierarchical' => true, 'show_in_rest' => true, 'rewrite' => ['slug' => 'genre']]);

// ------------------------------------------------------------------ media
$img = [];
foreach (['sunrise' => [200, 80, 40], 'forest' => [30, 120, 60], 'ocean' => [20, 70, 160], 'cover-one' => [120, 20, 90], 'cover-two' => [60, 60, 60]] as $n => $c) {
    $img[$n] = seed_img($n, $c);
}

// ------------------------------------------------------------------ ACF field group (as ACF stores it: acf-field-group + acf-field posts)
$existing = get_posts(['post_type' => 'acf-field-group', 'name' => 'group_book_details', 'post_status' => 'any']);
foreach ($existing as $e) {
    foreach (get_posts(['post_type' => 'acf-field', 'post_parent' => $e->ID, 'numberposts' => -1, 'post_status' => 'any']) as $f) {
        wp_delete_post($f->ID, true);
    }
    wp_delete_post($e->ID, true);
}
$gid = wp_insert_post(['post_type' => 'acf-field-group', 'post_status' => 'publish', 'post_title' => 'Book details', 'post_name' => 'group_book_details', 'post_excerpt' => 'book-details',
    'post_content' => serialize(['location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'book']]], 'position' => 'normal', 'style' => 'default',
        'label_placement' => 'top', 'instruction_placement' => 'label', 'hide_on_screen' => '', 'active' => true, 'description' => ''])]);
$order = 0;
$field = function (int $parent, string $key, string $name, string $label, string $type, array $extra = []) use (&$order): int {
    return wp_insert_post(['post_type' => 'acf-field', 'post_status' => 'publish', 'post_parent' => $parent, 'post_title' => $label, 'post_name' => $key,
        'post_excerpt' => $name, 'menu_order' => $order++, 'post_content' => serialize($extra + ['type' => $type, 'instructions' => '', 'required' => 0])]);
};
$field($gid, 'field_subtitle', 'subtitle', 'Subtitle', 'text', ['required' => 1]);
$field($gid, 'field_pages', 'pages', 'Pages', 'number');
$field($gid, 'field_format', 'format', 'Format', 'select', ['choices' => ['hardcover' => 'Hardcover', 'paperback' => 'Paperback', 'ebook' => 'E-book']]);
$field($gid, 'field_in_print', 'in_print', 'In print', 'true_false');
$field($gid, 'field_released', 'released', 'Released', 'date_picker', ['return_format' => 'F j, Y']);
$field($gid, 'field_cover', 'cover', 'Cover', 'image', ['return_format' => 'array']);
$field($gid, 'field_shots', 'shots', 'Photos', 'gallery');
$field($gid, 'field_website', 'website', 'Website', 'link');
$field($gid, 'field_related', 'related_books', 'Related books', 'relationship', ['post_type' => ['book']]);
$field($gid, 'field_tags', 'topics', 'Topics', 'checkbox', ['choices' => ['history' => 'History', 'science' => 'Science', 'travel' => 'Travel']]);
$rep = $field($gid, 'field_chapters', 'chapters', 'Chapters', 'repeater', ['layout' => 'table', 'button_label' => 'Add chapter']);
$field($rep, 'field_ch_title', 'title', 'Chapter title', 'text');
$field($rep, 'field_ch_page', 'page', 'Starts on page', 'number');
$field($rep, 'field_ch_pic', 'picture', 'Picture', 'image');
$grp = $field($gid, 'field_details', 'details', 'Publisher details', 'group');
$field($grp, 'field_d_pub', 'publisher', 'Publisher', 'text');
$field($grp, 'field_d_year', 'year', 'Year', 'number');
$flex = $field($gid, 'field_sections', 'sections', 'Sections', 'flexible_content', ['layouts' => [
    'layout_quote' => ['key' => 'layout_quote', 'name' => 'quote', 'label' => 'Quote', 'display' => 'block'],
    'layout_note' => ['key' => 'layout_note', 'name' => 'note', 'label' => 'Note', 'display' => 'block']]]);
$field($flex, 'field_s_quote', 'quote_text', 'Quote text', 'textarea', ['parent_layout' => 'layout_quote']);
$field($flex, 'field_s_note', 'note_body', 'Note body', 'wysiwyg', ['parent_layout' => 'layout_note']);

// ------------------------------------------------------------------ genres + books
$fiction = term_exists('fiction', 'genre') ?: wp_insert_term('Fiction', 'genre', ['slug' => 'fiction', 'description' => 'Made-up stories']);
$scifi = term_exists('sci-fi', 'genre') ?: wp_insert_term('Science fiction', 'genre', ['slug' => 'sci-fi', 'parent' => (int)$fiction['term_id']]);
$hist = term_exists('history', 'genre') ?: wp_insert_term('History', 'genre', ['slug' => 'history']);
$books = [];
foreach ([['The Long Sunrise', 'long-sunrise', 'sunrise', $scifi], ['Forest Paths', 'forest-paths', 'forest', $hist], ['Deep Ocean', 'deep-ocean', 'ocean', $fiction]] as $i => [$t, $s, $im, $term]) {
    $old = get_page_by_path($s, OBJECT, 'book');
    $id = wp_insert_post(['ID' => $old->ID ?? 0, 'post_type' => 'book', 'post_status' => 'publish', 'post_title' => $t, 'post_name' => $s, 'post_excerpt' => "Short about $t",
        'post_content' => "<!-- wp:paragraph --><p>The story of <strong>$t</strong>.</p><!-- /wp:paragraph -->", 'post_date' => '2024-0' . ($i + 1) . '-10 10:00:00']);
    set_post_thumbnail($id, $img[$im]);
    wp_set_object_terms($id, [(int)$term['term_id']], 'genre');
    $books[] = $id;
    $m = ['subtitle' => "Subtitle of $t", 'pages' => (string)(200 + $i * 50), 'format' => $i ? 'paperback' : 'hardcover', 'in_print' => $i === 1 ? '0' : '1',
        'released' => '2024051' . $i, 'cover' => (string)$img['cover-one'], 'shots' => serialize([(string)$img['forest'], (string)$img['ocean']]),
        'website' => serialize(['title' => 'Publisher page', 'url' => 'https://publisher.example/' . $s, 'target' => '_blank']),
        'topics' => serialize(['history', 'travel']),
        'chapters' => '2', 'chapters_0_title' => 'Beginnings', 'chapters_0_page' => '1', 'chapters_0_picture' => (string)$img['sunrise'],
        'chapters_1_title' => 'The middle', 'chapters_1_page' => '87', 'chapters_1_picture' => '',
        'details_publisher' => 'Acme Press', 'details_year' => '2024',
        'sections' => serialize(['quote', 'note']), 'sections_0_quote_text' => 'A quote from the book.', 'sections_1_note_body' => '<p>A <em>note</em>.</p>'];
    foreach ($m as $k => $v) {
        update_post_meta($id, $k, maybe_unserialize($v));
        $refs = ['subtitle' => 'field_subtitle', 'pages' => 'field_pages', 'format' => 'field_format', 'in_print' => 'field_in_print', 'released' => 'field_released',
            'cover' => 'field_cover', 'shots' => 'field_shots', 'website' => 'field_website', 'topics' => 'field_tags', 'chapters' => 'field_chapters',
            'details_publisher' => 'field_d_pub', 'details_year' => 'field_d_year', 'sections' => 'field_sections'];
        if (isset($refs[$k])) {
            update_post_meta($id, '_' . $k, $refs[$k]);
        }
    }
    update_post_meta($id, '_yoast_wpseo_metadesc', "Read about $t, a great book.");
    update_post_meta($id, '_yoast_wpseo_focuskw', strtolower($t));
}
update_post_meta($books[0], 'related_books', [(string)$books[1], (string)$books[2]]);
update_post_meta($books[0], '_related_books', 'field_related');

// ------------------------------------------------------------------ posts
$news = term_exists('news', 'category') ?: wp_insert_term('News', 'category', ['slug' => 'news']);
$posts = [];
foreach ([['Hello from Acme', 'hello-acme', 1], ['Second story', 'second-story', $jane], ['Hidden draft', 'hidden-draft', 1]] as $i => [$t, $s, $author]) {
    $old = get_page_by_path($s, OBJECT, 'post');
    $id = wp_insert_post(['ID' => $old->ID ?? 0, 'post_type' => 'post', 'post_status' => $i === 2 ? 'draft' : 'publish', 'post_title' => $t, 'post_name' => $s, 'post_author' => $author,
        'post_date' => '2025-0' . ($i + 3) . '-05 09:00:00',
        'post_content' => "<!-- wp:paragraph --><p>Body of $t with a <a href=\"" . home_url('/about-us/') . "\">link to about</a>.</p><!-- /wp:paragraph -->\n"
            . "<!-- wp:image {\"id\":{$img['sunrise']},\"sizeSlug\":\"large\"} --><figure class=\"wp-block-image size-large\"><img src=\"" . wp_get_attachment_url($img['sunrise']) . "\" alt=\"Sunrise\" class=\"wp-image-{$img['sunrise']}\"/><figcaption class=\"wp-element-caption\">Morning</figcaption></figure><!-- /wp:image -->"]);
    wp_set_post_categories($id, [(int)$news['term_id']]);
    wp_set_post_tags($id, ['acme', 'launch']);
    set_post_thumbnail($id, $img['ocean']);
    $posts[] = $id;
}
update_post_meta($posts[0], '_yoast_wpseo_title', '%%title%% %%sep%% Custom title %%sep%% %%sitename%%');
update_post_meta($posts[0], '_yoast_wpseo_metadesc', 'Custom description for the hello post.');
update_post_meta($posts[0], '_yoast_wpseo_focuskw', 'acme launch');
update_post_meta($posts[1], '_yoast_wpseo_meta-robots-noindex', '1');
update_post_meta($posts[1], '_yoast_wpseo_canonical', 'https://canonical.example/second');
update_option('sticky_posts', [$posts[0]]);
foreach ([['Alice', 'alice@example.test', 'Great post!', 0], ['Bob', 'bob@example.test', 'Thanks Alice.', 1]] as [$a, $e, $c, $reply]) {
    $exists = get_comments(['post_id' => $posts[0], 'author_email' => $e, 'count' => true]);
    if (!$exists) {
        $parent = $reply ? (int)(get_comments(['post_id' => $posts[0], 'number' => 1, 'orderby' => 'comment_ID', 'order' => 'ASC'])[0]->comment_ID ?? 0) : 0;
        wp_insert_comment(['comment_post_ID' => $posts[0], 'comment_author' => $a, 'comment_author_email' => $e, 'comment_content' => $c, 'comment_approved' => 1, 'comment_parent' => $parent]);
    }
}

// ------------------------------------------------------------------ forms
$cf7 = get_posts(['post_type' => 'wpcf7_contact_form', 'numberposts' => 1]);
$cf7id = $cf7 ? $cf7[0]->ID : 0;
if ($cf7id) {
    update_post_meta($cf7id, '_form', "<label> Your name\n    [text* your-name autocomplete:name placeholder \"Jane Doe\"] </label>\n\n<label> Your email\n    [email* your-email] </label>\n\n<label> Topic\n    [select topic \"Sales\" \"Support|support\" \"Other\"] </label>\n\n<label> Your message\n    [textarea your-message] </label>\n\n[acceptance terms] I agree to the terms. [/acceptance]\n\n[submit \"Send message\"]");
    $mail = get_post_meta($cf7id, '_mail', true) ?: [];
    $mail['recipient'] = 'sales@example.test';
    $mail['subject'] = 'New enquiry: [your-subject]';
    update_post_meta($cf7id, '_mail', $mail);
    wp_update_post(['ID' => $cf7id, 'post_title' => 'Contact form 1']);
}
$wpf = get_posts(['post_type' => 'wpforms', 'numberposts' => 1, 'post_status' => 'any']);
$wpfid = $wpf ? $wpf[0]->ID : wp_insert_post(['post_type' => 'wpforms', 'post_status' => 'publish', 'post_title' => 'Newsletter signup']);
wp_update_post(['ID' => $wpfid, 'post_content' => wp_slash(wp_json_encode(['id' => (string)$wpfid, 'field_id' => 4, 'fields' => [
    '0' => ['id' => '0', 'type' => 'name', 'label' => 'Name', 'format' => 'simple', 'required' => '1'],
    '1' => ['id' => '1', 'type' => 'email', 'label' => 'Email', 'required' => '1', 'placeholder' => 'you@example.com'],
    '2' => ['id' => '2', 'type' => 'radio', 'label' => 'How often?', 'choices' => ['1' => ['label' => 'Weekly'], '2' => ['label' => 'Monthly']]],
    '3' => ['id' => '3', 'type' => 'textarea', 'label' => 'Anything else?']],
    'settings' => ['form_title' => 'Newsletter signup', 'submit_text' => 'Subscribe', 'notifications' => ['1' => ['email' => 'news@example.test', 'subject' => 'New signup']],
        'confirmations' => ['1' => ['type' => 'message', 'message' => 'Thanks for subscribing!']]]]))]);

// ------------------------------------------------------------------ pages
$page = function (string $title, string $slug, string $content, array $meta = [], int $parent = 0) {
    $old = get_page_by_path($parent ? get_page_uri($parent) . '/' . $slug : $slug, OBJECT, 'page');
    $id = wp_insert_post(['ID' => $old->ID ?? 0, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $title, 'post_name' => $slug, 'post_content' => $content, 'post_parent' => $parent]);
    foreach ($meta as $k => $v) {
        update_post_meta($id, $k, $v);
    }
    return $id;
};
$about = $page('About us', 'about-us', '<!-- wp:paragraph --><p>We are Acme.</p><!-- /wp:paragraph -->');
$team = $page('Our team', 'team', '<!-- wp:paragraph --><p>The team page.</p><!-- /wp:paragraph -->', [], $about);
$u = static fn($id) => wp_get_attachment_url($img[$id]);
$gut = '<!-- wp:heading --><h2 class="wp-block-heading">Gutenberg showcase</h2><!-- /wp:heading -->
<!-- wp:columns --><div class="wp-block-columns"><!-- wp:column {"width":"66.66%"} --><div class="wp-block-column" style="flex-basis:66.66%"><!-- wp:paragraph --><p>Left column text.</p><!-- /wp:paragraph --></div><!-- /wp:column --><!-- wp:column {"width":"33.33%"} --><div class="wp-block-column" style="flex-basis:33.33%"><!-- wp:paragraph --><p>Right column text.</p><!-- /wp:paragraph --></div><!-- /wp:column --></div><!-- /wp:columns -->
<!-- wp:gallery {"linkTo":"none"} --><figure class="wp-block-gallery has-nested-images columns-default is-cropped"><!-- wp:image {"id":' . $img['forest'] . '} --><figure class="wp-block-image"><img src="' . $u('forest') . '" alt="Forest" class="wp-image-' . $img['forest'] . '"/></figure><!-- /wp:image --><!-- wp:image {"id":' . $img['ocean'] . '} --><figure class="wp-block-image"><img src="' . $u('ocean') . '" alt="Ocean" class="wp-image-' . $img['ocean'] . '"/></figure><!-- /wp:image --></figure><!-- /wp:gallery -->
<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="' . home_url('/contact/') . '">Contact us</a></div><!-- /wp:button --><!-- wp:button {"className":"is-style-outline"} --><div class="wp-block-button is-style-outline"><a class="wp-block-button__link wp-element-button" href="https://example.org">External</a></div><!-- /wp:button --></div><!-- /wp:buttons -->
<!-- wp:quote --><blockquote class="wp-block-quote"><!-- wp:paragraph --><p>Quoted wisdom.</p><!-- /wp:paragraph --><cite>A. Person</cite></blockquote><!-- /wp:quote -->
<!-- wp:list --><ul class="wp-block-list"><!-- wp:list-item --><li>First</li><!-- /wp:list-item --><!-- wp:list-item --><li>Second</li><!-- /wp:list-item --></ul><!-- /wp:list -->
<!-- wp:table --><figure class="wp-block-table"><table class="has-fixed-layout"><thead><tr><th>Plan</th><th>Price</th></tr></thead><tbody><tr><td>Basic</td><td>$5</td></tr></tbody></table></figure><!-- /wp:table -->
<!-- wp:embed {"url":"https://www.youtube.com/watch?v=dQw4w9WgXcQ","type":"video","providerNameSlug":"youtube"} --><figure class="wp-block-embed is-type-video is-provider-youtube wp-block-embed-youtube"><div class="wp-block-embed__wrapper">
https://www.youtube.com/watch?v=dQw4w9WgXcQ
</div></figure><!-- /wp:embed -->
<!-- wp:separator --><hr class="wp-block-separator has-alpha-channel-opacity"/><!-- /wp:separator -->
<!-- wp:shortcode -->[contact-form-7 id="' . $cf7id . '" title="Contact form 1"]<!-- /wp:shortcode -->';
$gutId = $page('Gutenberg showcase', 'gutenberg-showcase', $gut, ['_yoast_wpseo_metadesc' => 'All the core blocks.']);
$contact = $page('Contact', 'contact', '<!-- wp:paragraph --><p>Write to us.</p><!-- /wp:paragraph -->' . "\n" . '[wpforms id="' . $wpfid . '"]' . "\n" . '[contact-form-7 id="' . $cf7id . '" title="Contact form 1"]');

// Elementor page
$el = [
    ['id' => 'a1', 'elType' => 'container', 'settings' => ['content_width' => 'boxed', 'background_background' => 'classic', 'background_color' => '#0E3B43', 'padding' => ['unit' => 'px', 'top' => '60', 'bottom' => '60', 'left' => '20', 'right' => '20', 'isLinked' => false]], 'elements' => [
        ['id' => 'a2', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => ['title' => 'Elementor landing page', 'header_size' => 'h1', 'title_color' => '#FFD166', 'align' => 'center']],
        ['id' => 'a3', 'elType' => 'widget', 'widgetType' => 'text-editor', 'settings' => ['editor' => '<p>Designed with <strong>Elementor</strong>. This text should be editable in Slate.</p>', 'text_color' => '#FFFFFF']],
        ['id' => 'a4', 'elType' => 'widget', 'widgetType' => 'button', 'settings' => ['text' => 'Read the blog', 'link' => ['url' => home_url('/2025/03/hello-acme/')], 'align' => 'center', 'background_color' => '#EF476F']],
    ]],
    ['id' => 'b1', 'elType' => 'container', 'settings' => ['flex_direction' => 'row'], 'elements' => [
        ['id' => 'b2', 'elType' => 'container', 'settings' => ['width' => ['unit' => '%', 'size' => 50]], 'elements' => [
            ['id' => 'b3', 'elType' => 'widget', 'widgetType' => 'image', 'settings' => ['image' => ['id' => $img['forest'], 'url' => $u('forest')], 'image_size' => 'large']]]],
        ['id' => 'b4', 'elType' => 'container', 'settings' => ['width' => ['unit' => '%', 'size' => 50]], 'elements' => [
            ['id' => 'b5', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => ['title' => 'Why choose us', 'header_size' => 'h2']],
            ['id' => 'b6', 'elType' => 'widget', 'widgetType' => 'icon-list', 'settings' => ['icon_list' => [['_id' => 'i1', 'text' => 'Fast'], ['_id' => 'i2', 'text' => 'Friendly'], ['_id' => 'i3', 'text' => 'Fair']]]],
            ['id' => 'b7', 'elType' => 'widget', 'widgetType' => 'shortcode', 'settings' => ['shortcode' => '[contact-form-7 id="' . $cf7id . '" title="Contact form 1"]']],
        ]],
    ]],
    ['id' => 'c1', 'elType' => 'container', 'settings' => [], 'elements' => [
        ['id' => 'c2', 'elType' => 'widget', 'widgetType' => 'form', 'settings' => ['form_name' => 'Quote request', 'email_to' => 'quotes@example.test', 'email_subject' => 'Quote request',
            'button_text' => 'Get a quote', 'form_fields' => [
                ['_id' => 'f1', 'custom_id' => 'name', 'field_type' => 'text', 'field_label' => 'Full name', 'placeholder' => 'Your name', 'required' => 'true'],
                ['_id' => 'f2', 'custom_id' => 'email', 'field_type' => 'email', 'field_label' => 'Email', 'required' => 'true'],
                ['_id' => 'f3', 'custom_id' => 'budget', 'field_type' => 'select', 'field_label' => 'Budget', 'field_options' => "Small\nMedium|medium\nLarge"],
                ['_id' => 'f4', 'custom_id' => 'message', 'field_type' => 'textarea', 'field_label' => 'Message']]]],
    ]],
];
$elId = $page('Elementor landing', 'elementor-landing', '<h1>Elementor landing page</h1><p>Designed with Elementor.</p>', [
    '_elementor_edit_mode' => 'builder', '_elementor_template_type' => 'wp-page', '_elementor_version' => '3.30.0', '_wp_page_template' => 'elementor_header_footer',
    '_yoast_wpseo_title' => 'Elementor landing %%sep%% %%sitename%%', '_yoast_wpseo_metadesc' => 'A landing page built with Elementor.']);
update_post_meta($elId, '_elementor_data', wp_slash(wp_json_encode($el)));

// Elementor kit globals
$kit = (int)get_option('elementor_active_kit');
if ($kit) {
    update_post_meta($kit, '_elementor_page_settings', ['system_colors' => [['_id' => 'primary', 'title' => 'Primary', 'color' => '#0E3B43'], ['_id' => 'secondary', 'title' => 'Secondary', 'color' => '#FFD166'],
        ['_id' => 'text', 'title' => 'Text', 'color' => '#222222'], ['_id' => 'accent', 'title' => 'Accent', 'color' => '#EF476F']],
        'system_typography' => [['_id' => 'primary', 'title' => 'Primary', 'typography_typography' => 'custom', 'typography_font_family' => 'Poppins', 'typography_font_weight' => '600'],
            ['_id' => 'text', 'title' => 'Text', 'typography_typography' => 'custom', 'typography_font_family' => 'Inter', 'typography_font_weight' => '400']]]);
    delete_post_meta($kit, '_elementor_css');
}

// Elementor Theme Builder template (Pro data, stored as Pro stores it)
$old = get_page_by_path('single-book-layout', OBJECT, 'elementor_library');
$tpl = wp_insert_post(['ID' => $old->ID ?? 0, 'post_type' => 'elementor_library', 'post_status' => 'publish', 'post_title' => 'Single book layout', 'post_name' => 'single-book-layout']);
update_post_meta($tpl, '_elementor_template_type', 'single-post');
update_post_meta($tpl, '_elementor_edit_mode', 'builder');
update_post_meta($tpl, '_elementor_conditions', ['include/singular/book']);
update_post_meta($tpl, '_elementor_data', wp_slash(wp_json_encode([['id' => 't1', 'elType' => 'container', 'settings' => [], 'elements' => [
    ['id' => 't2', 'elType' => 'widget', 'widgetType' => 'theme-post-title', 'settings' => ['header_size' => 'h1']],
    ['id' => 't3', 'elType' => 'widget', 'widgetType' => 'theme-post-featured-image', 'settings' => []],
    ['id' => 't4', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => ['title' => 'Subtitle', '__dynamic__' => ['title' => '[elementor-tag id="x1" name="acf-text" settings="' . urlencode(wp_json_encode(['key' => 'field_subtitle:subtitle'])) . '"]']]],
    ['id' => 't5', 'elType' => 'widget', 'widgetType' => 'theme-post-content', 'settings' => []],
    ['id' => 't6', 'elType' => 'widget', 'widgetType' => 'loop-grid', 'settings' => ['posts_per_page' => 3, 'posts_post_type' => 'book']],
]]])));
wp_set_object_terms($tpl, 'single-post', 'elementor_library_type');

// Block theme template (customised in the Site Editor of Twenty Twenty-Five)
$old = get_page_by_path('single', OBJECT, 'wp_template');
$bt = wp_insert_post(['ID' => $old->ID ?? 0, 'post_type' => 'wp_template', 'post_status' => 'publish', 'post_title' => 'Single Posts', 'post_name' => 'single',
    'post_content' => '<!-- wp:template-part {"slug":"header"} /--><!-- wp:group {"tagName":"main"} --><main class="wp-block-group"><!-- wp:post-featured-image /--><!-- wp:post-title {"level":1} /--><!-- wp:post-date /--><!-- wp:post-content /--><!-- wp:post-terms {"term":"category"} /--><!-- wp:query {"query":{"perPage":3,"postType":"post","inherit":false}} --><div class="wp-block-query"><!-- wp:post-template --><!-- wp:post-title {"isLink":true} /--><!-- wp:post-excerpt /--><!-- /wp:post-template --></div><!-- /wp:query --></main><!-- /wp:group --><!-- wp:template-part {"slug":"footer"} /-->']);
wp_set_object_terms($bt, 'twentytwentyfive', 'wp_theme');

// ------------------------------------------------------------------ menus
foreach (['Main menu', 'Footer menu'] as $mn) {
    if ($m = wp_get_nav_menu_object($mn)) {
        wp_delete_nav_menu($m->term_id);
    }
}
$main = wp_create_nav_menu('Main menu');
$aboutItem = wp_update_nav_menu_item($main, 0, ['menu-item-title' => 'About', 'menu-item-object' => 'page', 'menu-item-object-id' => $about, 'menu-item-type' => 'post_type', 'menu-item-status' => 'publish']);
wp_update_nav_menu_item($main, 0, ['menu-item-title' => 'Team', 'menu-item-object' => 'page', 'menu-item-object-id' => $team, 'menu-item-type' => 'post_type', 'menu-item-parent-id' => $aboutItem, 'menu-item-status' => 'publish']);
wp_update_nav_menu_item($main, 0, ['menu-item-title' => 'News', 'menu-item-object' => 'category', 'menu-item-object-id' => (int)$news['term_id'], 'menu-item-type' => 'taxonomy', 'menu-item-status' => 'publish']);
wp_update_nav_menu_item($main, 0, ['menu-item-title' => 'Library', 'menu-item-url' => home_url('/library/'), 'menu-item-type' => 'custom', 'menu-item-status' => 'publish']);
wp_update_nav_menu_item($main, 0, ['menu-item-title' => 'Landing', 'menu-item-object' => 'page', 'menu-item-object-id' => $elId, 'menu-item-type' => 'post_type', 'menu-item-status' => 'publish']);
wp_update_nav_menu_item($main, 0, ['menu-item-title' => 'Contact', 'menu-item-object' => 'page', 'menu-item-object-id' => $contact, 'menu-item-type' => 'post_type', 'menu-item-status' => 'publish']);
$foot = wp_create_nav_menu('Footer menu');
wp_update_nav_menu_item($foot, 0, ['menu-item-title' => 'Gutenberg', 'menu-item-object' => 'page', 'menu-item-object-id' => $gutId, 'menu-item-type' => 'post_type', 'menu-item-status' => 'publish']);
wp_update_nav_menu_item($foot, 0, ['menu-item-title' => 'Example.org', 'menu-item-url' => 'https://example.org', 'menu-item-type' => 'custom', 'menu-item-target' => '_blank', 'menu-item-status' => 'publish']);
set_theme_mod('nav_menu_locations', ['menu-1' => $main, 'menu-2' => $foot]);

// ------------------------------------------------------------------ settings, SEO, redirects
update_option('show_on_front', 'page');
update_option('page_on_front', $elId);
update_option('blogdescription', 'Books, news and landing pages');
$titles = get_option('wpseo_titles') ?: [];
$titles['separator'] = 'sc-pipe';
$titles['title-book'] = '%%title%% %%sep%% Books %%sep%% %%sitename%%';
$titles['metadesc-book'] = 'Book: %%title%%';
$titles['noindex-tax-post_tag'] = true;
$titles['title-home-wpseo'] = '%%sitename%% %%sep%% %%sitedesc%%';
$titles['company_or_person'] = 'company';
$titles['company_name'] = 'Acme Inc';
update_option('wpseo_titles', $titles);
$social = get_option('wpseo_social') ?: [];
$social['facebook_site'] = 'https://facebook.com/acme';
$social['twitter_site'] = 'acmebooks';
$social['other_social_urls'] = ['https://www.linkedin.com/company/acme'];
update_option('wpseo_social', $social);
global $wpdb;
if ($wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}redirection_items'")) {
    $wpdb->query("DELETE FROM {$wpdb->prefix}redirection_items WHERE url IN ('/old-about/', '/promo/')");
    $group = (int)$wpdb->get_var("SELECT id FROM {$wpdb->prefix}redirection_groups ORDER BY id LIMIT 1") ?: 1;
    $wpdb->insert($wpdb->prefix . 'redirection_items', ['url' => '/old-about/', 'match_url' => '/old-about', 'match_data' => '', 'regex' => 0, 'position' => 0, 'last_count' => 0,
        'last_access' => '1970-01-01 00:00:00', 'group_id' => $group, 'status' => 'enabled', 'action_type' => 'url', 'action_code' => 301, 'action_data' => '/about-us/', 'match_type' => 'url', 'title' => '']);
    $wpdb->insert($wpdb->prefix . 'redirection_items', ['url' => '/promo/', 'match_url' => '/promo', 'match_data' => '', 'regex' => 0, 'position' => 1, 'last_count' => 0,
        'last_access' => '1970-01-01 00:00:00', 'group_id' => $group, 'status' => 'enabled', 'action_type' => 'url', 'action_code' => 302, 'action_data' => 'https://example.org/promo', 'match_type' => 'url', 'title' => '']);
}
flush_rewrite_rules();
echo "Seeded: books " . implode(',', $books) . ", posts " . implode(',', $posts) . ", elementor page $elId, gutenberg $gutId, cf7 $cf7id, wpforms $wpfid\n";
