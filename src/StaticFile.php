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

		$decoded = \urldecode($path);

		// realpath() na cestě s nulovým bytem hází ValueError. Stráž běží před
		// Bootstrap::boot(), takže by ho nezachytila ani Tracy a /assets/x%00.css
		// by skončilo holou pětistovkou s absolutními cestami v logu.
		if (\str_contains($decoded, "\0")) {
			return false;
		}

		$rootReal = \realpath($root);
		$fileReal = \realpath($root . $decoded);

		if ($rootReal === false || $fileReal === false) {
			return false;
		}

		// Router sám sobě: index.php je pod docrootem a je to soubor, takže by
		// ho stráž jinak přenechala serveru — ten ho spustí jako požadovaný
		// skript, `return false` na nejvyšší úrovni ho ukončí a z požadavku je
		// prázdná dvoustovka. Porovnává se rozhodnutá cesta, ne řetězec z URI,
		// aby na tom /./index.php nebo /assets/../index.php nic nezměnily.
		if ($fileReal === $rootReal . \DIRECTORY_SEPARATOR . 'index.php') {
			return false;
		}

		// Oddělovač na konci prefixu je nutný: bez něj by /../wwwjine/x
		// prošlo, protože ".../wwwjine/x" začíná na ".../www".
		return \is_file($fileReal)
			&& \str_starts_with($fileReal, $rootReal . \DIRECTORY_SEPARATOR);
	}
}
