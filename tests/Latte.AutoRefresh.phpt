<?php

declare(strict_types=1);

use Nette\Utils\FileSystem;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

// Rendered in two separate processes, and that is a necessity, not caution:
// Engine::loadTemplate() short-circuits through class_exists() and the class
// name is a hash of the template's path, not its content. Once a template is
// loaded in a process, it never compiles again in that process — so a test
// that renders twice in a row would pass even with revalidation switched off
// and would test nothing.

$env = [
	'DONUT_GUI_CACHE' => TEMP_DIR . '/cache',
	'DONUT_GUI_LOG' => TEMP_DIR . '/log',
];
$template = TEMP_DIR . '/page.latte';
$script = TEMP_DIR . '/render.php';

FileSystem::write($script, \sprintf(
	'<?php declare(strict_types=1); require %s;'
	. ' $container = Donut\Gui\Bootstrap::boot(%s)->createContainer(initialize: false);'
	. ' $latte = $container->getByType(Nette\Bridges\ApplicationLatte\LatteFactory::class)->create();'
	. ' echo $latte->renderToString(%s);',
	\var_export(\dirname(__DIR__) . '/vendor/autoload.php', true),
	\var_export($env, true),
	\var_export($template, true),
));

// 2>&1 folds the child's stderr into the captured output, so a fatal error
// there surfaces in the assertion failure instead of vanishing silently.
$render = static fn(): string => \trim((string) \shell_exec(
	\escapeshellarg(\PHP_BINARY) . ' ' . \escapeshellarg($script) . ' 2>&1'
));

FileSystem::write($template, 'PRVNI');
Assert::same('PRVNI', $render());

FileSystem::write($template, 'DRUHE');
Assert::same('DRUHE', $render());
