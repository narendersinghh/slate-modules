<?php
/** Translations: every translatable item with its translation in each language, create, open, link, unlink. */
use Slate\AdminUi;
use Slate\Auth;
use Slate\ContentModel;
use Slate\ListTable;
use Slate\Posts;
use SlateModules\Multilingual\Admin;
use SlateModules\Multilingual\Languages;
use SlateModules\Multilingual\Translations;

$langs = Languages::all();
$default = Languages::default();
$extra = Languages::extraCodes();
$types = Languages::translatableTypes();
$admin = Auth::can('manage_settings');

if (!$extra) {
    echo Admin::nav('translations'), AdminUi::emptyState('Add a second language first', $admin ? 'Translations appear here once your website has more than one language.' : 'Ask an administrator to add the languages of your website.',
        'globe', $admin ? ['Add a language' => Admin::url('languages')] : []);
    return;
}
if (!$types) {
    echo Admin::nav('translations'), AdminUi::emptyState('No translatable content', 'Choose which kinds of content can be translated.', 'translate', $admin ? ['Open settings' => Admin::url('settings')] : []);
    return;
}

$typeOptions = [];
foreach ($types as $t) {
    $typeOptions[$t] = ContentModel::type($t)['plural'] ?? $t;
}
$missingOptions = [];
foreach ($extra as $code) {
    $missingOptions[$code] = 'Missing ' . $langs[$code]['native'];
}

// One row per item (with its translations). Built in PHP: the groups live in a JSON field.
$all = [];
$truncated = false;
foreach ($types as $t) {
    $data = Translations::overview($t);
    $truncated = $truncated || $data['truncated'];
    foreach ($data['rows'] as $group) {
        $source = $group[$default] ?? reset($group);
        $all[] = ['id' => $source['id'], 'type' => $t, 'title' => $source['title'], 'source' => $source, 'langs' => $group,
            'missing' => array_values(array_diff(array_keys($langs), array_keys($group)))];
    }
}
$counts = ['all' => count($all), 'todo' => 0, 'done' => 0];
foreach ($all as $r) {
    $counts[$r['missing'] ? 'todo' : 'done']++;
}

$canOthers = Auth::can('edit_others_posts');
$me = (int)Auth::id();
$self = Admin::url('translations');
$return = $self . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : '');
$action = static fn(string $what): string => admin_url('m/' . Admin::SLUG . '/' . $what) . '?' . http_build_query(['return' => $return]);
$editUrl = static fn(string $type, int $id): string => admin_url('content/' . $type . '/' . $id);

$columns = [
    'title' => ['label' => 'Item', 'primary' => true, 'sort' => 'title', 'render' => static function (array $r) use ($editUrl, $langs, $default): string {
        $src = $r['source'];
        $title = $src['title'] !== '' ? $src['title'] : '(no title)';
        $origin = isset($r['langs'][$default]) ? '' : ' <span class="muted small">(' . e($langs[array_key_first($r['langs'])]['native'] ?? '') . ' only)</span>';
        return '<a class="row-title" href="' . e($editUrl($r['type'], (int)$src['id'])) . '">' . e($title) . '</a>' . $origin;
    }, 'export' => static fn(array $r) => $r['source']['title']],
    'type' => ['label' => 'Type', 'sort' => 'type', 'default' => count($types) > 1, 'render' => static fn(array $r): string => e($typeOptions[$r['type']] ?? $r['type'])],
];
foreach ($langs as $code => $l) {
    $columns['lang_' . $code] = [
        'label' => $l['native'] . (empty($l['enabled']) ? ' (off)' : ''),
        'default' => $code !== $default,
        'render' => static function (array $r) use ($code, $l, $editUrl, $action, $canOthers, $me): string {
            $item = $r['langs'][$code] ?? null;
            if ($item) {
                $tone = ['publish' => 'success', 'draft' => '', 'pending' => 'warn', 'future' => 'info', 'private' => 'info'][$item['status']] ?? '';
                $html = '<a href="' . e($editUrl($r['type'], (int)$item['id'])) . '" title="' . e($item['title']) . '">'
                    . AdminUi::pill(Posts::STATUSES[$item['status']] ?? $item['status'], $tone) . '</a>';
                if (count($r['langs']) > 1 && ($canOthers || $item['author_id'] === $me)) {
                    $html .= ' <button class="btn btn-sm btn-ghost" type="submit" formaction="' . e($action('unlink')) . '" name="id" value="' . (int)$item['id'] . '"'
                        . ' title="Unlink from its translations" aria-label="Unlink the ' . e($l['native']) . ' version"'
                        . ' data-confirm="Unlink this ' . e($l['native']) . ' version from its translations? The item itself is kept.">' . icon('x', 13) . '</button>';
                }
                return '<span class="nowrap">' . $html . '</span>';
            }
            if ($canOthers || $r['source']['author_id'] === $me) {
                return '<button class="btn btn-sm" type="submit" formaction="' . e($action('translate')) . '" name="pair" value="' . (int)$r['source']['id'] . ':' . e($code) . '">'
                    . icon('plus', 13) . ' Translate</button>';
            }
            return '<span class="muted">-</span>';
        },
        'export' => static fn(array $r) => isset($r['langs'][$code]) ? (Posts::STATUSES[$r['langs'][$code]['status']] ?? $r['langs'][$code]['status']) . ' #' . $r['langs'][$code]['id'] : 'Missing',
    ];
}
$columns['progress'] = ['label' => 'Done', 'sort' => 'progress', 'align' => 'right',
    'render' => static fn(array $r): string => (count($langs) - count($r['missing'])) . ' / ' . count($langs)];

$bulk = [];
foreach ($extra as $code) {
    $bulk['create_' . $code] = 'Create ' . $langs[$code]['native'] . ' drafts';
}

$t = new ListTable('multilingual.translations', $columns, [
    'base' => $self,
    'search' => 'Search titles or ID…',
    'views' => ['all' => ['All', $counts['all']], 'todo' => ['Needs translation', $counts['todo']], 'done' => ['Fully translated', $counts['done']]],
    'filters' => count($types) > 1 ? ['type' => ['label' => 'Types', 'all' => 'All content types', 'options' => $typeOptions], 'missing' => ['label' => 'Missing language', 'all' => 'All languages', 'options' => $missingOptions]]
        : ['missing' => ['label' => 'Missing language', 'all' => 'All languages', 'options' => $missingOptions]],
    'bulk' => $bulk,
    'bulk_action' => $self . '?' . http_build_query(['return' => $return]),
    'sort' => 'title', 'dir' => 'asc', 'export' => true,
    'row_actions' => static fn(array $r) => '<a href="' . e($editUrl($r['type'], (int)$r['source']['id'])) . '">Edit</a>'
        . ($r['source']['status'] === 'publish' && ($p = Posts::find((int)$r['source']['id'])) ? '<a href="' . e(Posts::permalink($p, true)) . '" target="_blank" rel="noopener">View</a>' : ''),
    'empty' => match ((string)($_GET['view'] ?? '')) {
        'todo' => ['icon' => 'check-circle', 'title' => 'Everything is translated', 'text' => 'Every item has a version in every language.'],
        'done' => ['icon' => 'translate', 'title' => 'No fully translated items yet', 'text' => 'Items appear here once they exist in every language.'],
        default => ['icon' => 'translate', 'title' => 'Nothing to translate yet', 'text' => 'Items of the translatable content types appear here.'],
    },
]);
$s = $t->state();

$rows = array_values(array_filter($all, static function (array $r) use ($s): bool {
    if ($s['view'] === 'todo' && !$r['missing'] || $s['view'] === 'done' && $r['missing']) {
        return false;
    }
    if (($s['filters']['type'] ?? '') !== '' && $r['type'] !== $s['filters']['type']) {
        return false;
    }
    if (($m = $s['filters']['missing'] ?? '') !== '' && isset($r['langs'][$m])) {
        return false;
    }
    if ($s['q'] !== '') {
        foreach ($r['langs'] as $item) {
            if (mb_stripos($item['title'], $s['q']) !== false || (ctype_digit($s['q']) && (int)$s['q'] === $item['id'])) {
                return true;
            }
        }
        return false;
    }
    return true;
}));
$key = static fn(array $r): string|int => match ($s['sort']) {
    'type' => $r['type'] . "\0" . mb_strtolower($r['title']),
    'progress' => count($r['missing']) * -1,
    default => mb_strtolower($r['title']),
};
usort($rows, static fn($a, $b) => ($s['dir'] === 'asc' ? 1 : -1) * ($key($a) <=> $key($b) ?: $a['id'] <=> $b['id']));

if ($t->exporting()) {
    $t->exportCsv($rows);
}
echo Admin::nav('translations');
if ($truncated): ?>
  <div class="banner"><span class="ic"><?= icon('info', 18) ?></span><div>Very large content type: only the first 5,000 items of each type are listed.</div></div>
<?php endif;
echo $t->render(array_slice($rows, $s['offset'], $s['per']), count($rows));
?>

<details class="card" style="margin-top:20px">
  <summary class="card-pad" style="cursor:pointer"><strong>Link items that already exist</strong> <span class="muted small">for translations written before this module, or created by hand</span></summary>
  <form method="post" action="<?= e(admin_url('m/' . Admin::SLUG . '/link')) ?>" class="card-pad" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;padding-top:0">
    <?= csrf_field() ?><input type="hidden" name="return" value="<?= e($return) ?>">
    <span>Item #</span><input class="input input-sm" type="number" name="id" min="1" required style="width:110px" aria-label="Item ID">
    <span>is a translation of #</span><input class="input input-sm" type="number" name="to" min="1" required style="width:110px" aria-label="Translated item ID">
    <button class="btn btn-sm btn-primary">Link</button>
    <p class="help" style="flex-basis:100%;margin:0">The ID is in the address of the editor (…/content/page/<strong>12</strong>). Set each item's language in its editor first (Language box in the sidebar).</p>
  </form>
</details>
