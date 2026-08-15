<?php

declare(strict_types=1);

namespace Donut\Gui;


/**
 * Kolik řádků má opakující se kontejner formuláře a s jakými indexy.
 *
 * `null` znamená, že tenhle POST kontejneru vůbec nepatří — řádky se pak
 * vezmou z načteného objektu, plus jeden prázdný navíc, aby měl uživatel
 * kam psát i bez JS. Pole, byť prázdné, znamená, že POST kontejneru patří —
 * indexy se odvodí z došlých dat, protože JS řádky nikdy nepřečísluje a
 * v číslování tak můžou být díry. Nezbyl-li po filtru na číslice ani jeden
 * řádek, vrátí se jeden prázdný: prázdný seznam by byl slepá ulička, JS
 * klonuje poslední řádek a kontejner bez řádků by se už nedal rozšířit.
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
		if (\is_array($post)) {
			$keys = [];

			foreach (\array_keys($post) as $key) {
				// Jméno komponenty v Nette musí odpovídat [a-zA-Z0-9_]+.
				if (\ctype_digit((string) $key)) {
					$keys[] = (int) $key;
				}
			}

			\sort($keys);

			// Kontejner, ze kterého nepřišel ani jeden řádek, dostane jeden
			// prázdný. Prázdný seznam by byl slepá ulička: JS klonuje poslední
			// řádek, takže kontejner bez řádků už nejde rozšířit.
			return $keys === [] ? [0] : $keys;
		}

		return \range(0, $existing);
	}
}
