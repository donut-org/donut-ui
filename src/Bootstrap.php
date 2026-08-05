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
		$configurator->setTempDirectory($root . '/temp');
		$configurator->addConfig($root . '/config/common.neon');

		return $configurator;
	}
}
