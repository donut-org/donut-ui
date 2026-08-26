<?php

declare(strict_types=1);

namespace Donut\Gui;


/**
 * Decides whether the built-in PHP server's router script should hand a
 * request off to the server as a static file.
 *
 * The built-in server only serves the file itself when the router returns
 * false; without that, index.php would get even a request for
 * bootstrap.min.css.
 */
final class StaticFile
{
	public static function shouldServe(string $root, string $uri): bool
	{
		$path = \parse_url($uri, \PHP_URL_PATH);

		if (!\is_string($path)) {
			return false;
		}

		$decoded = \urldecode($path);

		// realpath() on a path with a null byte throws a ValueError. This
		// guard runs before Bootstrap::boot(), so not even Tracy would catch
		// it, and /assets/x%00.css would end up a bare 500 with absolute
		// paths in the log.
		if (\str_contains($decoded, "\0")) {
			return false;
		}

		$rootReal = \realpath($root);
		$fileReal = \realpath($root . $decoded);

		if ($rootReal === false || $fileReal === false) {
			return false;
		}

		// The router against itself: index.php is under the docroot and is a
		// file, so the guard would otherwise hand it to the server — which
		// would run it as the requested script, `return false` at the top
		// level would end it, and the request would come back an empty 200.
		// The resolved path is compared, not the URI string, so /./index.php
		// or /assets/../index.php change nothing about it.
		if ($fileReal === $rootReal . \DIRECTORY_SEPARATOR . 'index.php') {
			return false;
		}

		// The separator at the end of the prefix is necessary: without it,
		// /../wwwother/x would pass, because ".../wwwother/x" starts with
		// ".../www".
		return \is_file($fileReal)
			&& \str_starts_with($fileReal, $rootReal . \DIRECTORY_SEPARATOR);
	}
}
