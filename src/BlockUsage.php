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
 * Which workflows use which block.
 *
 * The walk over the step tree mirrors KeyMap::walk() — nested steps are
 * if (both then and else) and foreach. A block absent from the map is
 * used by no one.
 *
 * Block deletion reads this map as its check, so it must not silently
 * undercount usage — unlike KeyMap, this isn't a second pass alongside
 * the validator, it's exactly what the protection against deleting a
 * still-used block rests on.
 */
final class BlockUsage
{
	/**
	 * @param  array<string, Workflow> $workflows workflow name => workflow
	 * @return array<string, list<string>> block name => workflow names
	 *
	 * A block name may just as well be purely numeric. The inner list
	 * accounts for that (see below), but the outer array key doesn't — that's
	 * a property of PHP arrays, not this code: PHP always silently converts
	 * a "123" key to int, and no (string) cast before writing changes that.
	 * Looking up `$usage['123']` works correctly (PHP converts the query the
	 * same way), only `array_keys($usage)` would return such a block as int.
	 */
	public static function of(array $workflows): array
	{
		/** @var array<string, array<string, true>> $usage */
		$usage = [];

		foreach ($workflows as $name => $workflow) {
			// The key is the workflow name, not the index — a block used in
			// two steps of the same workflow should be listed once.
			self::walk($workflow->steps, (string) $name, $usage);
		}

		$result = [];

		foreach ($usage as $block => $names) {
			// A workflow name may be purely numeric (no format rule forbids
			// it) — array_keys() would then return int instead of string.
			// Same reason as in KeyMap::keys()/invert().
			$names = \array_map(\strval(...), \array_keys($names));

			// Sort the inner list too: without this, order would follow the
			// directory's file order and the "used by: …" listing would
			// change for no apparent reason.
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
				// SetStep doesn't reference any block.

			} else {
				// A new step type would silently fall through the if/elseif
				// chain unnoticed — this map is the check before deleting
				// blocks, so a silent fall-through would mean deleting a
				// block that some workflow still uses. KeyMap::walk() throws
				// for the same reason.
				throw new \LogicException('unknown step type ' . $step::class);
			}
		}
	}
}
