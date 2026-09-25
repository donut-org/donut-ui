<?php

declare(strict_types=1);

namespace Donut\Gui;


/**
 * Where the GUI writes at runtime: cache and log.
 *
 * Both live in the user's XDG directories, not in the installation. The
 * application can then be installed globally and owned by root — the account
 * that runs it doesn't need to write anywhere inside it.
 *
 * The divider is what XDG means by these two variables. The compiled container,
 * compiled templates and sessions are disposable: they are deleted and recreated,
 * so they belong in cache. Log is the only trace of an error the user saw,
 * so it belongs in the state directory — the specification explicitly names
 * logs there.
 *
 * The environment comes as an array, not via getenv(), just as Donut\Profile
 * takes it — otherwise this table couldn't be tested.
 */
final class Dirs
{
	/**
	 * @param array<string, string> $env
	 */
	public static function cache(array $env, string $root): string
	{
		return self::value($env, 'DONUT_GUI_CACHE')
			?? self::userDir($env, 'XDG_CACHE_HOME', '/.cache')
			?? $root . '/temp';
	}


	/**
	 * @param array<string, string> $env
	 */
	public static function log(array $env, string $root): string
	{
		$explicit = self::value($env, 'DONUT_GUI_LOG');

		if ($explicit !== null) {
			return $explicit;
		}

		// The state directory holds only log for now, but it's named after the
		// application — if something else gets added later, it won't have to sit
		// next to Tracy's files.
		return (self::userDir($env, 'XDG_STATE_HOME', '/.local/state') ?? $root) . '/log';
	}


	/**
	 * Returns null when home cannot be determined from the environment. The
	 * caller then falls back to the installation — in a writable clone that's
	 * exactly the directory where everything lives today.
	 *
	 * @param array<string, string> $env
	 */
	private static function userDir(array $env, string $variable, string $fallback): ?string
	{
		$base = self::value($env, $variable);

		if ($base !== null) {
			return $base . '/donut-ui';
		}

		$home = self::value($env, 'HOME');

		return $home === null ? null : $home . $fallback . '/donut-ui';
	}


	/**
	 * An empty value is the same as unset — the same rule that Donut\Profile
	 * applies to its own variables.
	 *
	 * @param array<string, string> $env
	 */
	private static function value(array $env, string $key): ?string
	{
		$value = $env[$key] ?? '';

		return $value === '' ? null : $value;
	}
}
