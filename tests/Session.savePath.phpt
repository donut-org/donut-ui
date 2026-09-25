<?php

declare(strict_types=1);

use Donut\Gui\Bootstrap;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

$cache = TEMP_DIR . '/cache';

$container = Bootstrap::boot(['DONUT_GUI_CACHE' => $cache, 'DONUT_GUI_LOG' => TEMP_DIR . '/log'])
	->createContainer(initialize: false);

// Sessions are not started — we care about the configuration, not the running
// behaviour. Nette normalizes the savePath key to save_path because
// session.save_path is an ini directive.
$session = $container->getByType(Nette\Http\Session::class);

Assert::same($cache . '/sessions', $session->getOptions()['save_path']);
Assert::true(\is_dir($cache . '/sessions'));
