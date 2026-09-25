<?php

declare(strict_types=1);

namespace Donut\Gui;


/**
 * Fingerprint of the installation that invalidates the compiled container.
 *
 * Production mode never rebuilds the container: the file is only included if
 * it exists, and the only thing that can invalidate it is the cache key. Nette
 * includes the filemtime of Composer's ClassLoader.php with the comment
 * "composer update" — but Composer copies that file with the mtime of its own
 * release, so it changes when Composer is upgraded, not when project
 * dependencies are upgraded. Without something else in the key, an upgraded
 * installation would still run on a container compiled for the previous one.
 *
 * installed.php is overwritten by every `composer install` and `update`, and
 * an installation unpacked as a new tree brings a new file. False alarm — an
 * installation that changed nothing — costs one extra compilation, which is
 * the cheaper error.
 *
 * Templates don't need this: they are refreshed by Latte's own revalidation,
 * enabled in config/common.neon.
 */
final class Revision
{
	public static function of(string $root): string
	{
		// File may not exist, so suppress the warning.
		$time = @\filemtime($root . '/vendor/composer/installed.php');

		// A broken installation holds an empty revision. Anything variable
		// would mean a new container on every request.
		return $time === false ? '' : (string) $time;
	}
}
