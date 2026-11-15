<?php
/**
 * Comments — remove all data: the comments table and the module's options.
 * Runs when the module is deleted with "Also delete its data".
 */
declare(strict_types=1);

use Slate\DB;
use Slate\Options;

DB::exec('DROP TABLE IF EXISTS {{comments}}');
foreach (['settings', 'types', 'auto_append', 'moderate', 'approve_known', 'close_days', 'max_links', 'blocked_words', 'notify_email', 'order', 'api_count'] as $k) {
    Options::delete('comments_' . $k);
}
