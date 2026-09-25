<?php

declare(strict_types=1);

namespace Donut\Gui;


/**
 * Kam GUI za běhu zapisuje: cache a log.
 *
 * Obojí leží v XDG adresářích uživatele, ne v instalaci. Aplikace pak může
 * být nainstalovaná globálně a patřit rootovi — účet, který ji spouští,
 * nepotřebuje uvnitř ní zapisovat nikam.
 *
 * Dělítko je to, co těmi dvěma proměnnými myslí XDG. Kompilát kontejneru,
 * kompilát šablon i sessions jsou zahoditelné: smažou se a vyrobí znovu,
 * takže patří do cache. Log je jediná stopa po chybě, kterou uživatel
 * viděl, takže patří do stavového adresáře — specifikace tam logy jmenuje
 * výslovně.
 *
 * Prostředí přichází jako pole, ne přes getenv(), stejně jako ho bere
 * Donut\Profile — jinak by se tahle tabulka nedala otestovat.
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

		// Stavový adresář drží zatím jen log, ale jmenuje se podle
		// aplikace — kdyby přibylo něco dalšího, nebude to muset stát
		// vedle souborů Tracy.
		return (self::userDir($env, 'XDG_STATE_HOME', '/.local/state') ?? $root) . '/log';
	}


	/**
	 * Vrací null, když se z prostředí domov určit nedá. Volající pak sáhne
	 * do instalace — v zapisovatelném klonu je to přesně ten adresář, kde
	 * dneska všechno leží.
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
	 * Prázdná hodnota je totéž co nenastavená — stejné pravidlo, jaké má
	 * Donut\Profile pro své vlastní proměnné.
	 *
	 * @param array<string, string> $env
	 */
	private static function value(array $env, string $key): ?string
	{
		$value = $env[$key] ?? '';

		return $value === '' ? null : $value;
	}
}
