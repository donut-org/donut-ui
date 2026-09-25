<?php

declare(strict_types=1);

use Donut\Gui\Bootstrap;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

$cache = TEMP_DIR . '/cache';
$log = TEMP_DIR . '/log';

$container = Bootstrap::boot(['DONUT_GUI_CACHE' => $cache, 'DONUT_GUI_LOG' => $log])
	->createContainer(initialize: false);

// %tempDir% is the user's cache, not the installation's. That flips the
// default: anything that takes it from the framework writes where an
// unprivileged account is allowed to.
Assert::same($cache, $container->getParameter('tempDir'));

// %cacheDir% is the same directory under the name the configuration uses.
Assert::same($cache, $container->getParameter('cacheDir'));

// Tracy refuses to start without an existing directory, so boot() creates
// it.
Assert::true(\is_dir($log));
Assert::same($log, Tracy\Debugger::$logDirectory);

// PHP writes to the sessions directory but does not create it. Without it
// the flash message after saving a workflow turns into a warning from
// session_start().
Assert::true(\is_dir($cache . '/sessions'));
