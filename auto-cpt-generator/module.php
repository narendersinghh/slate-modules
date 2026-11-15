<?php
/**
 * Auto CPT Generator, create post types, taxonomies and custom fields automatically.
 */
declare(strict_types=1);

use Slate\Auth;
use Slate\ContentModel;
use Slate\Hooks;
use Slate\Modules;
use Slate\Router;
use SlateModules\AutoCpt\Generator;
use SlateModules\AutoCpt\WpClient;
use SlateModules\AutoCpt\Wxr;

require_once __DIR__ . '/src/WpClient.php';
require_once __DIR__ . '/src/Generator.php';
require_once __DIR__ . '/src/Wxr.php';

Modules::adminPage('Auto CPT generator', 'auto-cpt', static function (): void {
    require __DIR__ . '/view.php';
}, 'manage_content_model', 'sparkles');

Hooks::add('admin_routes', static function (Router $r): void {
    $guard = static function (): void {
        Auth::require('manage_content_model');
        @set_time_limit(180);
    };
    $respond = static function (callable $fn): void {
        try {
            json_response(['ok' => true] + $fn());
        } catch (Throwable $e) {
            json_response(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    };
    $r->post('/m/auto-cpt/scan-wp', static function () use ($guard, $respond): void {
        $guard();
        $respond(static function (): array {
            $in = json_input();
            $wp = new WpClient((string)($in['url'] ?? ''), (string)($in['user'] ?? ''), (string)($in['password'] ?? ''));
            return ['spec' => Generator::fromWordPress($wp)];
        });
    });
    $r->post('/m/auto-cpt/scan-wxr', static function () use ($guard, $respond): void {
        $guard();
        $respond(static function (): array {
            $f = $_FILES['file'] ?? null;
            if (!$f || $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
                throw new RuntimeException('Upload a WordPress export (.xml) file.');
            }
            return ['spec' => Generator::fromWxr(Wxr::parse($f['tmp_name']))];
        });
    });
    $r->post('/m/auto-cpt/describe', static function () use ($guard, $respond): void {
        $guard();
        $respond(static fn(): array => ['spec' => Generator::fromDescription(mb_substr((string)(json_input()['description'] ?? ''), 0, 6000))]);
    });
    $r->post('/m/auto-cpt/apply', static function () use ($guard, $respond): void {
        $guard();
        $respond(static function (): array {
            $spec = json_input()['spec'] ?? null;
            if (is_string($spec)) {
                $spec = json_decode($spec, true);
            }
            if (!is_array($spec)) {
                throw new RuntimeException('Invalid spec JSON.');
            }
            $log = Generator::apply($spec);
            // Links to what now exists, and a readable history entry ("2 post types, 1 taxonomy · WordPress export").
            $spec = Generator::normalize($spec);
            $links = [];
            foreach ($spec['post_types'] as $t) {
                if (($type = ContentModel::type($t['slug'])) && empty($type['builtin'])) {
                    $links[] = ['label' => (string)$type['plural'], 'url' => admin_url('content/' . $t['slug'])];
                }
            }
            foreach ($spec['taxonomies'] as $x) {
                if ($tax = ContentModel::taxonomy($x['slug'])) {
                    $links[] = ['label' => (string)$tax['plural'], 'url' => admin_url('model/taxonomies/' . $x['slug'])];
                }
            }
            if ($spec['field_groups']) {
                $links[] = ['label' => 'Field groups', 'url' => admin_url('model/fields')];
            }
            $plural = static fn(int $n, string $one, string $many): string => $n . ' ' . ($n === 1 ? $one : $many);
            $source = ['wp' => 'WordPress site', 'wxr' => 'WordPress export', 'ai' => 'AI description', 'json' => 'JSON'][(string)(json_input()['source'] ?? '')] ?? '';
            $summary = implode(', ', array_filter([
                $spec['post_types'] ? $plural(count($spec['post_types']), 'post type', 'post types') : '',
                $spec['taxonomies'] ? $plural(count($spec['taxonomies']), 'taxonomy', 'taxonomies') : '',
                $spec['field_groups'] ? $plural(count($spec['field_groups']), 'field group', 'field groups') : '',
            ])) ?: 'No changes';
            log_activity('auto_cpt_applied', $summary . ($source !== '' ? ' · ' . $source : ''));
            return ['log' => $log, 'links' => $links];
        });
    });
});
