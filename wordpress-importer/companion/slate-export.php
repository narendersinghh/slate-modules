<?php
/**
 * Plugin Name: Slate Export
 * Description: Lets Slate's Site Migration read everything it needs to move this site (custom field definitions, forms, SEO settings, redirects, templates, menus). Read-only. Only administrators (application password) can use it. Remove it after the migration.
 * Version: 1.0.0
 * Requires at least: 5.6
 * Requires PHP: 7.4
 * Author: Slate
 * License: GPL-2.0-or-later
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Slate_Export
{
    const NS = 'slate-export/v1';
    /** Plugin tables that may be read (without prefix). */
    const TABLES = ['rank_math_redirections', 'redirection_items', 'aioseo_posts', 'gf_form', 'gf_form_meta', 'fluentform_forms', 'fluentform_form_meta'];
    /** Post types whose raw rows are exported (definitions, forms, templates). */
    const SPECIAL = ['acf-field-group', 'acf-field', 'acf-post-type', 'acf-taxonomy', 'acf-ui-options-page', 'wpcf7_contact_form', 'wpforms', 'elementor_library',
        'wp_template', 'wp_template_part', 'wp_block', 'wp_navigation', 'jet-engine', 'mb-post-type', 'mb-taxonomy', 'meta-box', '_pods_pod', '_pods_field',
        'fl-builder-template', 'et_pb_layout', 'bricks_template', 'ct_template'];
    const SKIP_META = '/^(_edit_lock|_edit_last|_encloseme|_pingme|_wp_trash_meta_\w+|_elementor_css|_elementor_page_assets|_elementor_element_cache|_elementor_screenshot\w*|_oembed_\w+|_wp_attachment_backup_sizes|_transient\w*)$/';

    public static function boot()
    {
        add_action('rest_api_init', [__CLASS__, 'routes']);
    }

    public static function can()
    {
        return current_user_can('manage_options');
    }

    public static function routes()
    {
        $r = function ($path, $cb) {
            register_rest_route(self::NS, $path, ['methods' => 'GET', 'callback' => $cb, 'permission_callback' => [__CLASS__, 'can']]);
        };
        $r('/info', [__CLASS__, 'info']);
        $r('/options', [__CLASS__, 'options']);
        $r('/meta', [__CLASS__, 'meta']);
        $r('/termmeta', [__CLASS__, 'termmeta']);
        $r('/posts', [__CLASS__, 'posts']);
        $r('/acf', [__CLASS__, 'acf']);
        $r('/users', [__CLASS__, 'users']);
        $r('/comments', [__CLASS__, 'comments']);
        $r('/terms', [__CLASS__, 'terms']);
        $r('/table', [__CLASS__, 'table']);
    }

    public static function info()
    {
        global $wpdb;
        $plugins = [];
        foreach ((array)get_option('active_plugins', []) as $p) {
            $plugins[] = dirname($p) === '.' ? basename($p, '.php') : dirname($p);
        }
        $tables = [];
        foreach (self::TABLES as $t) {
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->prefix . $t))) {
                $tables[$t] = (int)$wpdb->get_var("SELECT COUNT(*) FROM `{$wpdb->prefix}{$t}`");
            }
        }
        $special = [];
        foreach ($wpdb->get_results("SELECT post_type, COUNT(*) c FROM {$wpdb->posts} WHERE post_status <> 'auto-draft' GROUP BY post_type") as $row) {
            $special[$row->post_type] = (int)$row->c;
        }
        return ['version' => '1.0.0', 'wp' => get_bloginfo('version'), 'plugins' => $plugins, 'theme' => get_stylesheet(), 'template' => get_template(),
            'tables' => $tables, 'post_types' => $special, 'comments' => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved IN ('0','1')"),
            'users' => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users}")];
    }

    /** Options relevant for a migration, raw (as stored). Never secrets, licenses or caches. */
    public static function options()
    {
        global $wpdb;
        $out = [];
        foreach ($wpdb->get_results("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name NOT LIKE '\\_%transient%'") as $o) {
            $n = $o->option_name;
            if (preg_match('/(license|api_key|secret|_key$|password|token)/i', $n) || strlen($o->option_value) > 4194304) {
                continue;
            }
            if (preg_match('/^(blogname|blogdescription|siteurl|home|permalink_structure|category_base|tag_base|show_on_front|page_on_front|page_for_posts|posts_per_page|date_format|time_format|timezone_string|gmt_offset|sticky_posts|site_icon|site_logo|stylesheet|template|theme_mods_.+|nav_menu_options|wpseo.*|rank-math-.*|rank_math_.*|aioseo_options.*|seopress_.*|cptui_.*|options_.+|_options_.+|elementor_active_kit|elementor_cpt_support|elementor_.*|wpcf7|redirection_options|wpforms_settings|jet_engine.*|acf_.*|default_comment_status|comment_moderation)$/', $n)) {
                $out[$n] = $o->option_value;
            }
        }
        return $out;
    }

    /** Raw post meta of the given ids (?ids=1,2,3, at most 200). */
    public static function meta(WP_REST_Request $req)
    {
        global $wpdb;
        $ids = array_slice(array_filter(array_map('intval', explode(',', (string)$req['ids']))), 0, 200);
        if (!$ids) {
            return [];
        }
        $out = [];
        $rows = $wpdb->get_results("SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN (" . implode(',', $ids) . ')');
        foreach ($rows as $r) {
            if (!preg_match(self::SKIP_META, $r->meta_key) && strlen($r->meta_value) < 8388608) {
                $out[$r->post_id][$r->meta_key] = $r->meta_value;
            }
        }
        return (object)$out;
    }

    public static function termmeta(WP_REST_Request $req)
    {
        global $wpdb;
        $page = max(1, (int)$req['page']);
        $out = [];
        $rows = $wpdb->get_results($wpdb->prepare("SELECT term_id, meta_key, meta_value FROM {$wpdb->termmeta} ORDER BY meta_id LIMIT 2000 OFFSET %d", ($page - 1) * 2000));
        foreach ($rows as $r) {
            $out[$r->term_id][$r->meta_key] = $r->meta_value;
        }
        return ['items' => (object)$out, 'more' => count($rows) === 2000];
    }

    /** Raw rows (with meta) of definition / form / template post types. */
    public static function posts(WP_REST_Request $req)
    {
        global $wpdb;
        $page = max(1, (int)$req['page']);
        $in = "'" . implode("','", array_map('esc_sql', self::SPECIAL)) . "'";
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->posts} WHERE post_type IN ($in) AND post_status NOT IN ('auto-draft','trash') ORDER BY ID LIMIT 200 OFFSET %d", ($page - 1) * 200), ARRAY_A);
        $items = [];
        foreach ($rows as $r) {
            $meta = [];
            foreach ($wpdb->get_results($wpdb->prepare("SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d", $r['ID'])) as $m) {
                if (!preg_match(self::SKIP_META, $m->meta_key)) {
                    $meta[$m->meta_key] = $m->meta_value;
                }
            }
            $terms = [];
            foreach (wp_get_object_terms((int)$r['ID'], get_object_taxonomies($r['post_type'])) as $t) {
                if (!is_wp_error($t)) {
                    $terms[] = ['taxonomy' => $t->taxonomy, 'slug' => $t->slug];
                }
            }
            $items[] = ['id' => (int)$r['ID'], 'type' => $r['post_type'], 'title' => $r['post_title'], 'slug' => $r['post_name'], 'content' => $r['post_content'],
                'excerpt' => $r['post_excerpt'], 'status' => $r['post_status'], 'parent' => (int)$r['post_parent'], 'menu_order' => (int)$r['menu_order'],
                'date_gmt' => $r['post_date_gmt'], 'author' => (int)$r['post_author'], 'meta' => $meta, 'terms' => $terms];
        }
        return ['items' => $items, 'more' => count($rows) === 200];
    }

    /** Exact ACF definitions (field groups with nested fields, ACF post types and taxonomies) when ACF is active. */
    public static function acf()
    {
        if (!function_exists('acf_get_field_groups')) {
            return ['active' => false];
        }
        $groups = [];
        foreach (acf_get_field_groups() as $g) {
            $g['fields'] = acf_get_fields($g);
            $groups[] = $g;
        }
        $out = ['active' => true, 'pro' => defined('ACF_PRO'), 'groups' => $groups];
        if (function_exists('acf_get_acf_post_types')) {
            $out['post_types'] = acf_get_acf_post_types();
            $out['taxonomies'] = acf_get_acf_taxonomies();
        }
        return $out;
    }

    public static function users()
    {
        $out = [];
        foreach (get_users(['number' => 2000]) as $u) {
            $out[] = ['id' => $u->ID, 'login' => $u->user_login, 'email' => $u->user_email, 'display_name' => $u->display_name, 'nicename' => $u->user_nicename,
                'url' => $u->user_url, 'role' => $u->roles ? (string)reset($u->roles) : 'subscriber', 'description' => get_user_meta($u->ID, 'description', true),
                'registered' => $u->user_registered];
        }
        return $out;
    }

    public static function comments(WP_REST_Request $req)
    {
        $page = max(1, (int)$req['page']);
        $list = get_comments(['status' => 'all', 'type' => ['comment', 'review'], 'number' => 500, 'offset' => ($page - 1) * 500, 'orderby' => 'comment_ID', 'order' => 'ASC']);
        $items = [];
        foreach ($list as $c) {
            if (!in_array($c->comment_approved, ['0', '1'], true)) {
                continue;
            }
            $items[] = ['id' => (int)$c->comment_ID, 'post_id' => (int)$c->comment_post_ID, 'parent' => (int)$c->comment_parent, 'author' => $c->comment_author,
                'email' => $c->comment_author_email, 'url' => $c->comment_author_url, 'date_gmt' => $c->comment_date_gmt, 'content' => $c->comment_content,
                'approved' => $c->comment_approved === '1', 'user_id' => (int)$c->user_id];
        }
        return ['items' => $items, 'more' => count($list) === 500];
    }

    /** Nav menu terms and other terms the REST API does not list. */
    public static function terms()
    {
        $out = [];
        foreach (get_terms(['taxonomy' => ['nav_menu', 'wp_theme', 'elementor_library_type'], 'hide_empty' => false]) as $t) {
            $out[] = ['id' => $t->term_id, 'name' => $t->name, 'slug' => $t->slug, 'taxonomy' => $t->taxonomy, 'parent' => $t->parent];
        }
        return $out;
    }

    /** Rows of a whitelisted plugin table (redirects, forms, SEO). */
    public static function table(WP_REST_Request $req)
    {
        global $wpdb;
        $t = (string)$req['name'];
        if (!in_array($t, self::TABLES, true) || !$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->prefix . $t))) {
            return new WP_Error('slate_export_table', 'Unknown table.', ['status' => 404]);
        }
        $page = max(1, (int)$req['page']);
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM `{$wpdb->prefix}{$t}` LIMIT 500 OFFSET %d", ($page - 1) * 500), ARRAY_A);
        return ['items' => $rows, 'more' => count($rows) === 500];
    }
}

Slate_Export::boot();
