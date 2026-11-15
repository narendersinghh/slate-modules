<?php
/**
 * Multilingual — removes the module's settings and string translations. Content is never deleted: items keep their
 * "_lang" / "_tgroup" custom fields (harmless without the module) and every translation stays a normal item.
 */
declare(strict_types=1);

use Slate\Options;
use Slate\PageCache;

Options::delete('ml_settings');
Options::delete('ml_strings');

// Core may have stored a copy of the "Language" field group when another group was saved.
$groups = Options::get('field_groups');
if (is_array($groups) && isset($groups['ml_language'])) {
    unset($groups['ml_language']);
    Options::set('field_groups', $groups);
}
PageCache::clear();
