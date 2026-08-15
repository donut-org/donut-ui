<?php

declare(strict_types=1);

namespace Donut\Gui;


/**
 * Kolik řádků má opakující se kontejner formuláře a s jakými indexy.
 *
 * Při POSTu se odvodí z došlých dat — JS řádky nikdy nepřečísluje, takže
 * indexy můžou mít díry a kontejnery musí vzniknout přesně pro ty klíče,
 * které dorazily. Jinak se vezmou z načteného objektu, plus jeden prázdný
 * řádek navíc, aby měl uživatel kam psát i bez JS.
 *
 * Jestli POST patří zrovna tomuhle formuláři, rozhoduje volající — jen on
 * zná jméno svého signálu. Formulář sestavený z cizího POSTu by se vykreslil
 * prázdný, i když objekt za ním prázdný není.
 */
final class RowShape
{
	/**
	 * @param  mixed $post     podpole POSTu pro tenhle kontejner, nebo null
	 * @param  int   $existing kolik řádků má načtený objekt
	 * @return array<int, int>
	 */
	public static function of(mixed $post, int $existing): array
	{
		if (\is_array($post) && $post !== []) {
			$keys = [];

			foreach (\array_keys($post) as $key) {
				// Jméno komponenty v Nette musí odpovídat [a-zA-Z0-9_]+.
				if (\ctype_digit((string) $key)) {
					$keys[] = (int) $key;
				}
			}

			\sort($keys);

			return $keys;
		}

		return \range(0, $existing);
	}
}
