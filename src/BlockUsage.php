<?php

declare(strict_types=1);

namespace Donut\Gui;

use Donut\Format\ForeachStep;
use Donut\Format\IfStep;
use Donut\Format\RunStep;
use Donut\Format\Step;
use Donut\Format\Workflow;


/**
 * Kdo který kámen používá.
 *
 * Průchod stromem kroků kopíruje KeyMap::walk() — vnořené steps mají
 * if (then i else) a foreach. Kámen, který v mapě není, nepoužívá nikdo.
 */
final class BlockUsage
{
	/**
	 * @param  array<string, Workflow> $workflows jméno workflow => workflow
	 * @return array<string, list<string>> jméno kamene => jména workflow
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
			$names = \array_keys($names);

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
			}

			// SetStep na žádný kámen neodkazuje. Neznámý typ se tu vědomě
			// přeskakuje: BlockUsage je informativní, ne autoritativní —
			// na rozdíl od zapisovače, kde by tichý přeskok znamenal ztrátu dat.
		}
	}
}
