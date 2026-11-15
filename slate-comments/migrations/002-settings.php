<?php
/**
 * 1.1.0: settings moved from the single array option "comments_settings" to one option per setting
 * ("comments_<key>"), so they can be edited on the standard settings screen. Copies the old values over
 * (never overwriting a value that is already set) and removes the old option.
 */
declare(strict_types=1);

use Slate\DB;
use Slate\Options;

return static function (): void {
    $old = Options::get('comments_settings', []);
    if (!is_array($old) || !$old) {
        return;
    }
    $keys = ['types', 'auto_append', 'moderate', 'approve_known', 'close_days', 'max_links', 'blocked_words', 'notify_email', 'order', 'api_count'];
    foreach ($keys as $k) {
        if (!array_key_exists($k, $old)) {
            continue;
        }
        $exists = DB::val('SELECT COUNT(*) FROM {{options}} WHERE name = :n', ['n' => 'comments_' . $k]);
        if (!(int)$exists) {
            Options::set('comments_' . $k, $old[$k]);
        }
    }
    Options::delete('comments_settings');
};
