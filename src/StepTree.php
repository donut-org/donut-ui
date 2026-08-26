<?php

declare(strict_types=1);

namespace Donut\Gui;

use Donut\Format\ForeachStep;
use Donut\Format\IfStep;
use Donut\Format\Step;
use Donut\Format\Workflow;


/**
 * Structural operations over the step tree, addressed via StepPath.
 *
 * Both Workflow and every step class are readonly, so the tree never mutates
 * in place — every operation rebuilds it and returns a new Workflow. That
 * makes the whole class a pure function, and its invariants can be tested
 * against a real payload.
 *
 * A path names a position, not just an existing step: insert() inserts at
 * the given place and shifts the rest, so remove() followed by insert() back
 * at the same path returns the original tree.
 */
final class StepTree
{
	/**
	 * @throws \OutOfRangeException
	 */
	public static function get(Workflow $workflow, StepPath $at): Step
	{
		$segments = $at->segments();
		// apply() (replace/insert/remove/…) always normalizes via
		// array_values() — Workflow::$steps is array<int, Step>, not
		// list<Step>, so without this, get() would point to a different
		// step than the rest of the class on a hole in the keys.
		$steps = \array_values($workflow->steps);
		$last = \count($segments) - 1;

		foreach ($segments as $k => [, $index]) {
			if (!isset($steps[$index])) {
				throw new \OutOfRangeException("Step \"{$at}\" does not exist.");
			}

			if ($k === $last) {
				return $steps[$index];
			}

			$steps = self::childrenOf($steps[$index], $segments[$k + 1][0], $at);
		}

		throw new \OutOfRangeException("\"{$at}\" does not point to any step.");
	}


	/**
	 * @throws \OutOfRangeException
	 */
	public static function replace(Workflow $workflow, StepPath $at, Step $step): Workflow
	{
		return self::apply($workflow, $at, function (array $steps, int $i) use ($step, $at): array {
			if (!isset($steps[$i])) {
				throw new \OutOfRangeException("Step \"{$at}\" does not exist.");
			}

			$steps[$i] = $step;

			return $steps;
		});
	}


	/**
	 * @throws \OutOfRangeException
	 */
	public static function insert(Workflow $workflow, StepPath $at, Step $step): Workflow
	{
		return self::apply($workflow, $at, function (array $steps, int $i) use ($step, $at): array {
			// Inserting after the last element is fine — that's how "+ step"
			// works at the end of the list. Any further, and it would open a
			// hole.
			if ($i > \count($steps)) {
				throw new \OutOfRangeException("Position \"{$at}\" is outside the step list.");
			}

			return \array_merge(\array_slice($steps, 0, $i), [$step], \array_slice($steps, $i));
		});
	}


	/**
	 * Deletes a step along with its whole subtree, if it has one.
	 *
	 * @throws \OutOfRangeException
	 */
	public static function remove(Workflow $workflow, StepPath $at): Workflow
	{
		return self::apply($workflow, $at, function (array $steps, int $i) use ($at): array {
			if (!isset($steps[$i])) {
				throw new \OutOfRangeException("Step \"{$at}\" does not exist.");
			}

			unset($steps[$i]);

			return \array_values($steps);
		});
	}


	/**
	 * @throws \OutOfRangeException
	 */
	public static function moveUp(Workflow $workflow, StepPath $at): Workflow
	{
		return self::swap($workflow, $at, -1);
	}


	/**
	 * @throws \OutOfRangeException
	 */
	public static function moveDown(Workflow $workflow, StepPath $at): Workflow
	{
		return self::swap($workflow, $at, 1);
	}


	private static function swap(Workflow $workflow, StepPath $at, int $delta): Workflow
	{
		return self::apply($workflow, $at, function (array $steps, int $i) use ($delta, $at): array {
			if (!isset($steps[$i])) {
				throw new \OutOfRangeException("Step \"{$at}\" does not exist.");
			}

			$j = $i + $delta;

			// Nothing happens at the edge of the list. The template doesn't
			// render an arrow there; this is a safeguard for a hand-crafted
			// POST.
			if (!isset($steps[$j])) {
				return $steps;
			}

			$step = $steps[$i];
			$steps[$i] = $steps[$j];
			$steps[$j] = $step;

			return \array_values($steps);
		});
	}


	/**
	 * @param callable(list<Step>, int): list<Step> $operation
	 */
	private static function apply(Workflow $workflow, StepPath $at, callable $operation): Workflow
	{
		$segments = $at->segments();

		if ($segments === []) {
			throw new \OutOfRangeException("\"{$at}\" does not point to any step.");
		}

		return new Workflow(
			$workflow->name,
			$workflow->inputs,
			self::transform(\array_values($workflow->steps), $segments, $operation, $at),
			$workflow->description,
		);
	}


	/**
	 * @param  list<Step>                             $steps
	 * @param  list<array{string, int}>               $segments
	 * @param  callable(list<Step>, int): list<Step>   $operation
	 * @return list<Step>
	 */
	private static function transform(array $steps, array $segments, callable $operation, StepPath $at): array
	{
		[, $index] = $segments[0];

		if (\count($segments) === 1) {
			return $operation($steps, $index);
		}

		if (!isset($steps[$index])) {
			throw new \OutOfRangeException("Step \"{$at}\" does not exist.");
		}

		$property = $segments[1][0];

		$steps[$index] = self::withChildren(
			$steps[$index],
			$property,
			self::transform(
				self::childrenOf($steps[$index], $property, $at),
				\array_slice($segments, 1),
				$operation,
				$at,
			),
			$at,
		);

		return $steps;
	}


	/**
	 * @return list<Step>
	 */
	private static function childrenOf(Step $step, string $property, StepPath $at): array
	{
		if ($step instanceof IfStep && $property === 'then') {
			return \array_values($step->then);
		}

		if ($step instanceof IfStep && $property === 'else') {
			return \array_values($step->else);
		}

		if ($step instanceof ForeachStep && $property === 'steps') {
			return \array_values($step->steps);
		}

		throw new \OutOfRangeException(
			"Path \"{$at}\" descends into \"{$property}\", which step " . $step::class . ' does not have.'
		);
	}


	/**
	 * @param list<Step> $children
	 */
	private static function withChildren(Step $step, string $property, array $children, StepPath $at): Step
	{
		if ($step instanceof IfStep && $property === 'then') {
			return new IfStep($step->condition, $children, $step->else, $step->name);
		}

		if ($step instanceof IfStep && $property === 'else') {
			return new IfStep($step->condition, $step->then, $children, $step->name);
		}

		if ($step instanceof ForeachStep && $property === 'steps') {
			return new ForeachStep($step->over, $step->as, $children, $step->name);
		}

		throw new \OutOfRangeException(
			"Path \"{$at}\" descends into \"{$property}\", which step " . $step::class . ' does not have.'
		);
	}
}
