<?php
declare(strict_types=1);

namespace SlateModules\WpImport;

use Slate\Templates;

/**
 * Elementor Theme Builder templates and block-theme templates (Site Editor) → Slate Templates.
 * Imported templates are saved switched off ("draft"), named "From WordPress: …", so nothing changes on the site
 * until someone reviews and enables them. Dynamic parts become Slate tags; what has no equivalent is listed in the report.
 */
final class TemplatesMap
{
    /** Candidates: [['key','source','name','kind','target','html','notes' => []]]. */
    public static function collect(?\SlateModules\AutoCpt\WpClient $wp = null): array
    {
        $out = [];
        foreach (Data::rows('wpost') as $id => $r) {
            if (($r['status'] ?? '') === 'trash') {
                continue;
            }
            if (($r['type'] ?? '') === 'elementor_library') {
                $meta = Data::metaDecoded($id);
                $tt = (string)($meta['_elementor_template_type'] ?? '');
                if (!in_array($tt, ['single', 'single-post', 'single-page', 'archive', 'search-results', 'error-404', 'product', 'product-archive'], true)) {
                    continue; // header, footer, popup, section, page, loop-item, kit: not page templates
                }
                $conds = is_array($meta['_elementor_conditions'] ?? null) ? $meta['_elementor_conditions'] : [];
                $b = new Builders();
                $html = $b->elementorTemplate(Builders::elementorData(Data::meta($id)), (int)$id);
                $notes = $b->skipped ? ['Widgets without a Slate equivalent: ' . implode(', ', array_keys($b->skipped))] : [];
                foreach (self::elementorTargets($tt, $conds) as [$kind, $target]) {
                    $out[] = ['key' => 'elementor:' . $id . ':' . $kind . '/' . $target, 'source' => 'Elementor Theme Builder', 'name' => (string)$r['title'],
                        'kind' => $kind, 'target' => $target, 'html' => $html, 'notes' => $notes, 'wp_id' => (int)$id];
                }
            }
            if (($r['type'] ?? '') === 'wp_template') {
                $t = self::blockTarget((string)$r['slug']);
                if ($t) {
                    $out[] = self::fromBlocks('block:' . $id, (string)$r['title'] ?: (string)$r['slug'], $t, (string)$r['content'], (int)$id);
                }
            }
        }
        // Block templates of the active theme through the REST API (administrator application password).
        if ($wp && $wp->authenticated() && !array_filter($out, static fn($t) => str_starts_with($t['key'], 'block:'))) {
            $r = $wp->get('/wp/v2/templates', ['context' => 'edit', 'per_page' => 100]);
            foreach ($r['ok'] && is_array($r['data']) ? $r['data'] : [] as $tpl) {
                $t = self::blockTarget((string)($tpl['slug'] ?? ''));
                $raw = (string)($tpl['content']['raw'] ?? '');
                if ($t && $raw !== '' && ($tpl['source'] ?? '') === 'custom') {
                    $out[] = self::fromBlocks('block:' . ($tpl['wp_id'] ?? $tpl['id']), (string)($tpl['title']['raw'] ?? $tpl['slug']), $t, $raw, (int)($tpl['wp_id'] ?? 0));
                }
            }
        }
        return $out;
    }

    private static function fromBlocks(string $key, string $name, array $t, string $content, int $id): array
    {
        $b = new Blocks('template');
        $html = $b->render($content);
        $notes = $b->dropped ? ['Blocks without a Slate equivalent: ' . implode(', ', array_keys($b->dropped))] : [];
        return ['key' => $key, 'source' => 'Site Editor', 'name' => $name, 'kind' => $t[0], 'target' => $t[1], 'html' => trim($html), 'notes' => $notes, 'wp_id' => $id];
    }

    /** Block template slug → [kind, target]. */
    public static function blockTarget(string $slug): ?array
    {
        $type = static fn(string $wp) => Importer::slateTypeFor($wp);
        $tax = static fn(string $wp) => Importer::slateTaxFor($wp);
        return match (true) {
            $slug === 'single' || $slug === 'single-post' => ['single', 'post'],
            $slug === 'page' => ['single', 'page'],
            (bool)preg_match('/^page-(.+)$/', $slug, $m) => ['single', 'page__' . $m[1]],
            (bool)preg_match('/^single-(.+)$/', $slug, $m) && $type($m[1]) !== null => ['single', $type($m[1])],
            in_array($slug, ['archive', 'index', 'home'], true) => ['archive', 'post'],
            (bool)preg_match('/^archive-(.+)$/', $slug, $m) && $type($m[1]) !== null => ['archive', $type($m[1])],
            $slug === 'category' => ['term', 'category'],
            $slug === 'tag' => ['term', 'tag'],
            (bool)preg_match('/^taxonomy-([^-]+)/', $slug, $m) && $tax($m[1]) !== null => ['term', $tax($m[1])],
            $slug === '404' => ['special', '404'],
            $slug === 'search' => ['special', 'search'],
            default => null,
        };
    }

    /** Elementor display conditions → Slate [kind, target] pairs. */
    public static function elementorTargets(string $tt, array $conds): array
    {
        $out = [];
        foreach ($conds as $c) {
            $p = explode('/', (string)$c);
            if (($p[0] ?? '') !== 'include') {
                continue;
            }
            $what = $p[1] ?? '';
            $sub = $p[2] ?? '';
            if ($what === 'singular') {
                $t = $sub === '' ? 'post' : (Importer::slateTypeFor(preg_replace('/^(in_|.*_by_author$)/', '', $sub)) ?? null);
                $t && $out[] = ['single', $t];
            } elseif ($what === 'archive') {
                if ($sub === '' || $sub === 'post_archive') {
                    $out[] = ['archive', 'post'];
                } elseif (str_ends_with($sub, '_archive') && ($t = Importer::slateTypeFor(substr($sub, 0, -8)))) {
                    $out[] = ['archive', $t];
                } elseif ($sub === 'search') {
                    $out[] = ['special', 'search'];
                } elseif ($t = Importer::slateTaxFor($sub)) {
                    $out[] = ['term', $t];
                }
            } elseif ($what === 'general') {
                $out[] = in_array($tt, ['archive', 'product-archive'], true) ? ['archive', 'post'] : ['single', 'post'];
            }
        }
        if (!$out) {
            $out[] = match ($tt) {
                'single-page' => ['single', 'page'],
                'archive' => ['archive', 'post'],
                'search-results' => ['special', 'search'],
                'error-404' => ['special', '404'],
                default => ['single', 'post'],
            };
        }
        if ($tt === 'error-404') {
            $out = [['special', '404']];
        }
        return array_values(array_unique($out, SORT_REGULAR));
    }

    /** Save one candidate as a Slate template (switched off). Returns [template id, 'created'|'updated'|'kept']. */
    public static function save(array $t): array
    {
        $existing = Importer::mapExtra('template', $t['key']);
        $id = $existing['tpl'] ?? null;
        $cur = $id ? Templates::find((string)$id) : null;
        if ($cur && !empty($existing['saved']) && (string)$cur['updated_at'] !== (string)$existing['saved']) {
            return [(string)$id, 'kept'];
        }
        $html = "<!-- Imported from WordPress ({$t['source']}: " . str_replace('--', '-', $t['name']) . "). Review, then switch it on. -->\n" . $t['html'];
        $newId = Templates::save(['id' => $cur ? $id : '', 'name' => 'From WordPress: ' . $t['name'], 'kind' => $t['kind'], 'target' => $t['target'],
            'html' => $html, 'css' => (string)($cur['css'] ?? ''), 'wrap' => true, 'enabled' => !empty($cur['enabled'])]);
        $saved = Templates::find($newId);
        Importer::setMapPublic('template', $t['key'], 0, '', ['tpl' => $newId, 'saved' => (string)($saved['updated_at'] ?? '')]);
        return [$newId, $cur ? 'updated' : 'created'];
    }
}
