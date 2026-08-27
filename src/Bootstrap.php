<?php

declare(strict_types=1);

namespace Donut\Gui;

use Nette\Bootstrap\Configurator;
use Nette\Utils\FileSystem;


final class Bootstrap
{
	/**
	 * Production unless `DONUT_GUI_DEBUG` says otherwise.
	 *
	 * The GUI has a user who is not its developer: for them it is a finished
	 * application, and a Tracy bar over it is noise. Nette's own
	 * detectDebugMode() guesses from the client's address, which on a tool
	 * that only ever listens on localhost would mean everyone.
	 *
	 * The price is that production freezes the Latte and DI caches — editing
	 * a template has no effect until `gui/temp/cache` is cleared. That is the
	 * developer's problem, and the developer is the one setting the variable.
	 *
	 * The environment arrives as an array rather than through getenv(), the
	 * same way Donut\Profile takes it, so that this decision can be tested.
	 *
	 * @param array<string, string> $env
	 */
	public static function boot(array $env): Configurator
	{
		$root = \dirname(__DIR__);
		// An empty value counts as unset, the same rule Profile uses for its
		// own variables; '0' is an explicit off rather than "a value exists".
		$flag = $env['DONUT_GUI_DEBUG'] ?? '';
		$debug = $flag !== '' && $flag !== '0';

		$configurator = new Configurator;
		$configurator->setDebugMode($debug);

		// Without enableTracy() an uncaught exception is a blank page. In
		// debug mode Tracy displays it; in production it writes it to the log
		// directory instead — without one, the error the user just hit would
		// go nowhere at all. Tracy refuses to start when that directory is
		// missing rather than creating it, so this does.
		FileSystem::createDir($root . '/log');
		$configurator->enableTracy($root . '/log');

		$configurator->setTempDirectory($root . '/temp');
		$configurator->addConfig($root . '/config/common.neon');

		return $configurator;
	}
}
