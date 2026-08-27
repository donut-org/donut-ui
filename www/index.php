<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// The built-in server serves static files only when the router script hands
// them off by returning false. Without this, /assets/bootstrap.min.css
// would end up in the application as an unknown address.
$uri = $_SERVER['REQUEST_URI'] ?? '';

if (\PHP_SAPI === 'cli-server' && \is_string($uri) && Donut\Gui\StaticFile::shouldServe(__DIR__, $uri)) {
	return false;
}

// getenv() with no argument returns the whole environment; Bootstrap takes
// it as an array so the debug-mode decision can be tested.
Donut\Gui\Bootstrap::boot(\getenv())
	->createContainer()
	->getByType(Nette\Application\Application::class)
	->run();
