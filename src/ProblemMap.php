<?php

declare(strict_types=1);

namespace Donut\Gui;

use Donut\Validator\Problem;
use Donut\Validator\Result;


/**
 * Problémy z jedné validace, indexované podle cesty ke kroku.
 *
 * Problém, který nepatří žádnému kroku, má cestu bez dvojtečky (jen
 * `card-dev.json`) a vytáhne se stejným způsobem.
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
	 * @return list<Problem> v pořadí, v jakém je validátor ohlásil
	 */
	public function at(StepPath|string $where): array
	{
		return $this->byLocation[(string) $where] ?? [];
	}
}
