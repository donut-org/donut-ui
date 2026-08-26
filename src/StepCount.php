<?php

declare(strict_types=1);

namespace Donut\Gui;

use Donut\Format\ForeachStep;
use Donut\Format\IfStep;
use Donut\Format\Step;


/**
 * How many steps would disappear along with a given step, were it deleted.
 *
 * Used by the delete confirmation in steps.latte — without recursion, a
 * nested subtree (if/foreach under then/else/steps) would only report its
 * direct children, and the number would be smaller than what actually gets
 * deleted.
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
	 * English plural: "N nested step" / "N nested steps".
	 */
	public static function label(int $n): string
	{
		$word = $n === 1 ? 'nested step' : 'nested steps';

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
