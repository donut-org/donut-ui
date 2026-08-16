<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// Vestavěný server servíruje statické soubory jen tehdy, když mu je router
// script přenechá vrácením false. Bez tohohle by /assets/bootstrap.min.css
// skončilo v aplikaci jako neznámá adresa.
$uri = $_SERVER['REQUEST_URI'] ?? '';

if (\PHP_SAPI === 'cli-server' && \is_string($uri) && Donut\Gui\StaticFile::shouldServe(__DIR__, $uri)) {
	return false;
}

Donut\Gui\Bootstrap::boot()
	->createContainer()
	->getByType(Nette\Application\Application::class)
	->run();
