<?php
declare(strict_types=1);

use SlateModules\Comments\Install;

if (!class_exists(Install::class)) {
    require_once dirname(__DIR__) . '/src/Install.php';
}

return static function (): void {
    Install::run();
};
