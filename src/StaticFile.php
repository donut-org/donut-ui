<?php

declare(strict_types=1);

namespace Donut\Gui;


/**
 * Rozhoduje, jestli má router script vestavěného PHP serveru přenechat
 * požadavek serveru jako statický soubor.
 *
 * Vestavěný server po souboru sáhne jen tehdy, když router vrátí false;
 * bez toho dostane index.php i požadavek na bootstrap.min.css.
 */
final class StaticFile
{
	public static function shouldServe(string $root, string $uri): bool
	{
		$path = \parse_url($uri, \PHP_URL_PATH);

		if (!\is_string($path)) {
			return false;
		}

		$rootReal = \realpath($root);
		$fileReal = \realpath($root . \urldecode($path));

		if ($rootReal === false || $fileReal === false) {
			return false;
		}

		// Oddělovač na konci prefixu je nutný: bez něj by /../wwwjine/x
		// prošlo, protože ".../wwwjine/x" začíná na ".../www".
		return \is_file($fileReal)
			&& \str_starts_with($fileReal, $rootReal . \DIRECTORY_SEPARATOR);
	}
}
