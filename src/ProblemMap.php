<?php

declare(strict_types=1);

namespace Donut\Gui;

use Donut\Validator\Problem;
use Donut\Validator\Result;


/**
 * Problems from a single validation, indexed by step path.
 *
 * A problem that doesn't belong to any step has a path without a colon
 * (just `card-dev.json`) and is looked up the same way.
 */
final class ProblemMap
{
	/**
	 * @param array<string, list<Problem>> $byLocation
	 */
	private function __construct(
		private readonly array $byLocation,
	) {
	}


	public static function fromResult(Result $result): self
	{
		$byLocation = [];

		foreach ($result->getProblems() as $problem) {
			$byLocation[$problem->location][] = $problem;
		}

		return new self($byLocation);
	}


	/**
	 * @return list<Problem> in the order the validator reported them
	 */
	public function at(StepPath|string $where): array
	{
		return $this->byLocation[(string) $where] ?? [];
	}
}
