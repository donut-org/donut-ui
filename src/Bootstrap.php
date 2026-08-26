<?php

declare(strict_types=1);

namespace Donut\Gui;

use Nette\Bootstrap\Configurator;


final class Bootstrap
{
	public static function boot(): Configurator
	{
		$root = \dirname(__DIR__);

		$configurator = new Configurator;
		$configurator->setDebugMode(true);

		// Debug mode disables Application::$catchExceptions — Nette then
		// relies on Tracy to display an uncaught exception. Without
		// enableTracy(), every uncaught exception would end up a blank page.
		$configurator->enableTracy();

		$configurator->setTempDirectory($root . '/temp');
		$configurator->addConfig($root . '/config/common.neon');

		return $configurator;
	}
}
