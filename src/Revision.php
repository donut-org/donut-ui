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
 * Two files date the installation, because neither moves the other:
 *
 * - installed.php is overwritten by every `composer install` and `update` that
 *   changes something, and an installation unpacked as a new tree brings a new
 *   file. An install with nothing to do leaves it alone.
 * - common.neon is what `git pull` rewrites when the configuration changed. It
 *   defines the services, so the container compiled from it is stale — and
 *   without this, nothing would say so.
 *
 * A false alarm — an installation that changed nothing — costs one extra
 * compilation, which is the cheaper error.
 *
 * The set of presenters is not covered: they are scanned at compile time and
 * baked into the container, so an upgrade that adds one needs the cache thrown
 * away. That failure is loud, which is why it is left to say so itself.
 *
 * Templates don't need any of this: they are refreshed by Latte's own
 * revalidation, enabled in config/common.neon.
 */
final class Revision
{
	public static function of(string $root): string
	{
		$time = \max(
			self::mtime($root . '/vendor/composer/installed.php'),
			self::mtime($root . '/config/common.neon'),
		);

		// A broken installation holds an empty revision. Anything variable
		// would mean a new container on every request.
		return $time === 0 ? '' : (string) $time;
	}


	private static function mtime(string $file): int
	{
		// File may not exist, so suppress the warning.
		$time = @\filemtime($file);

		return $time === false ? 0 : $time;
	}
}
