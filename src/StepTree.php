<?php

declare(strict_types=1);

namespace Donut\Gui;

use Donut\Format\ForeachStep;
use Donut\Format\IfStep;
use Donut\Format\Step;
use Donut\Format\Workflow;


/**
 * Strukturální operace nad stromem kroků, adresované přes StepPath.
 *
 * Workflow i všechny třídy kroků jsou readonly, takže se strom nemění na
 * místě — každá operace ho přestaví a vrátí nový Workflow. Díky tomu je
 * celá třída čistá funkce a jde otestovat invarianty nad skutečnou zátěží.
 *
 * Cesta pojmenovává pozici, ne jen existující krok: insert() vloží na dané
 * místo a ostatní posune, takže remove() a insert() zpátky na tutéž cestu
 * vrátí původní strom.
 */
final class StepTree
{
	/**
	 * @throws \OutOfRangeException
	 */
	public static function get(Workflow $workflow, StepPath $at): Step
	{
		$segments = $at->segments();
		$steps = $workflow->steps;
		$last = \count($segments) - 1;

		foreach ($segments as $k => [, $index]) {
			if (!isset($steps[$index])) {
				throw new \OutOfRangeException("Krok \"{$at}\" neexistuje.");
			}

			if ($k === $last) {
				return $steps[$index];
			}

			$steps = self::childrenOf($steps[$index], $segments[$k + 1][0], $at);
		}

		throw new \OutOfRangeException("\"{$at}\" neukazuje na žádný krok.");
	}


	/**
	 * @throws \OutOfRangeException
	 */
	public static function replace(Workflow $workflow, StepPath $at, Step $step): Workflow
	{
		return self::apply($workflow, $at, function (array $steps, int $i) use ($step, $at): array {
			if (!isset($steps[$i])) {
				throw new \OutOfRangeException("Krok \"{$at}\" neexistuje.");
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
			// Vložit za poslední prvek je v pořádku — tak funguje „+ krok"
			// na konci seznamu. Dál už ne, tam by vznikla díra.
			if ($i > \count($steps)) {
				throw new \OutOfRangeException("Pozice \"{$at}\" je mimo seznam kroků.");
			}

			return \array_merge(\array_slice($steps, 0, $i), [$step], \array_slice($steps, $i));
		});
	}


	/**
	 * Smaže krok i s celým podstromem, pokud nějaký má.
	 *
	 * @throws \OutOfRangeException
	 */
	public static function remove(Workflow $workflow, StepPath $at): Workflow
	{
		return self::apply($workflow, $at, function (array $steps, int $i) use ($at): array {
			if (!isset($steps[$i])) {
				throw new \OutOfRangeException("Krok \"{$at}\" neexistuje.");
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
				throw new \OutOfRangeException("Krok \"{$at}\" neexistuje.");
			}

			$j = $i + $delta;

			// Na kraji seznamu se nestane nic. Šablona tam šipku
			// nevykresluje; tohle je pojistka pro ručně poslaný POST.
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
			throw new \OutOfRangeException("\"{$at}\" neukazuje na žádný krok.");
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
			throw new \OutOfRangeException("Krok \"{$at}\" neexistuje.");
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
			"Cesta \"{$at}\" sestupuje do \"{$property}\", které krok " . $step::class . ' nemá.'
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
			"Cesta \"{$at}\" sestupuje do \"{$property}\", které krok " . $step::class . ' nemá.'
		);
	}
}
