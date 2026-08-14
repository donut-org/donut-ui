<?php

declare(strict_types=1);

namespace Donut\Gui;

use Donut\Format\ForeachStep;
use Donut\Format\IfStep;
use Donut\Format\Step;


/**
 * Kolik kroků zmizí spolu s daným krokem, kdyby se smazal.
 *
 * Používá ho potvrzení mazání v steps.latte — bez rekurze by u vnořeného
 * podstromu (if/foreach v then/else/steps) hlásilo jen přímé potomky
 * a číslo by bylo menší, než co se skutečně smaže.
 */
final class StepCount
{
	public static function subtree(Step $step): int
	{
		if ($step instanceof IfStep) {
			return self::listCount($step->then) + self::listCount($step->else);
		}

		if ($step instanceof ForeachStep) {
			return self::listCount($step->steps);
		}

		return 0;
	}


	/**
	 * Český tvar „N vnořený krok / vnořené kroky / vnořených kroků".
	 */
	public static function label(int $n): string
	{
		$word = match (true) {
			$n === 1 => 'vnořený krok',
			$n >= 2 && $n <= 4 => 'vnořené kroky',
			default => 'vnořených kroků',
		};

		return "{$n} {$word}";
	}


	/**
	 * @param array<int, Step> $steps
	 */
	private static function listCount(array $steps): int
	{
		$count = 0;

		foreach ($steps as $child) {
			$count += 1 + self::subtree($child);
		}

		return $count;
	}
}
