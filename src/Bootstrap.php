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

		// Debug mode vypíná Application::$catchExceptions — nette pak spoléhá
		// na to, že neošetřenou výjimku ukáže Tracy. Bez enableTracy() by
		// každá neošetřená výjimka skončila jako prázdná stránka.
		$configurator->enableTracy();

		$configurator->setTempDirectory($root . '/temp');
		$configurator->addConfig($root . '/config/common.neon');

		return $configurator;
	}
}
