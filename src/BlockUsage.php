<?php

declare(strict_types=1);

namespace Donut\Gui;

use Donut\Format\ForeachStep;
use Donut\Format\IfStep;
use Donut\Format\RunStep;
use Donut\Format\SetStep;
use Donut\Format\Step;
use Donut\Format\Workflow;


/**
 * Kdo který kámen používá.
 *
 * Průchod stromem kroků kopíruje KeyMap::walk() — vnořené steps mají
 * if (then i else) a foreach. Kámen, který v mapě není, nepoužívá nikdo.
 *
 * Mazání kamenů čte tuhle mapu jako kontrolu, tak nesmí tiše podhodnotit
 * použití — na rozdíl od KeyMap to tu není druhý průchod vedle validátoru,
 * ale přesně to, na čem stojí ochrana proti smazání kamene, co workflow
 * pořád používá.
 */
final class BlockUsage
{
	/**
	 * @param  array<string, Workflow> $workflows jméno workflow => workflow
	 * @return array<string, list<string>> jméno kamene => jména workflow
	 *
	 * Jméno kamene smí být stejně tak čistě číselné. Vnitřní seznam s tím
	 * počítá (viz níže), ale vnější klíč pole ne — to je vlastnost PHP polí,
	 * ne tohohle kódu: klíč "123" PHP vždycky tiše převede na int a žádný
	 * (string) cast před zápisem to nezmění. Vyhledání `$usage['123']`
	 * funguje správně (PHP převede stejně i dotaz), jen `array_keys($usage)`
	 * by takový kámen vrátil jako int.
	 */
	public static function of(array $workflows): array
	{
		/** @var array<string, array<string, true>> $usage */
		$usage = [];

		foreach ($workflows as $name => $workflow) {
			// Klíčem je jméno workflow, ne index — kámen použitý ve dvou
			// krocích téhož workflow se má uvést jednou.
			self::walk($workflow->steps, (string) $name, $usage);
		}

		$result = [];

		foreach ($usage as $block => $names) {
			// Jméno workflow smí být čistě číselné (žádný formát to
			// nezakazuje) — array_keys() by pak vrátilo int místo stringu.
			// Stejný důvod jako v KeyMap::keys()/invert().
			$names = \array_map(\strval(...), \array_keys($names));

			// Seřadit i vnitřní seznam: bez toho by pořadí určilo pořadí
			// souborů v adresáři a výpis „používá: …" by se měnil bez
			// zjevného důvodu.
			\sort($names);
			$result[$block] = $names;
		}

		\ksort($result);

		return $result;
	}


	/**
	 * @param array<int, Step>                    $steps
	 * @param array<string, array<string, true>> &$usage
	 */
	private static function walk(array $steps, string $workflow, array &$usage): void
	{
		foreach ($steps as $step) {
			if ($step instanceof RunStep) {
				$usage[$step->block][$workflow] = true;

			} elseif ($step instanceof IfStep) {
				self::walk($step->then, $workflow, $usage);
				self::walk($step->else, $workflow, $usage);

			} elseif ($step instanceof ForeachStep) {
				self::walk($step->steps, $workflow, $usage);

			} elseif ($step instanceof SetStep) {
				// SetStep na žádný kámen neodkazuje.

			} else {
				// Nový typ kroku by tichým if/elseif řetězcem propadl beze
				// zmínky — tahle mapa je kontrola před mazáním kamenů, takže
				// by tichý propad znamenal, že se smaže kámen, který nějaké
				// workflow pořád používá. KeyMap::walk() háže ze stejného
				// důvodu.
				throw new \LogicException('neznámý typ kroku ' . $step::class);
			}
		}
	}
}
