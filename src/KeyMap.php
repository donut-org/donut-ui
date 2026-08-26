<?php

declare(strict_types=1);

namespace Donut\Gui;

use Donut\Format\ForeachStep;
use Donut\Format\IfStep;
use Donut\Format\RunStep;
use Donut\Format\SetStep;
use Donut\Format\Workflow;


/**
 * Where each key originates and who reads it.
 *
 * Derived from the parsed tree, not from the validator — Template::getKeys()
 * is public and nothing more is needed. Whether a write is conditional shows
 * in the path: anything under .then, .else or .steps is inside a branch.
 *
 * But it's a second walk over the tree alongside the validator's, so the two
 * could drift apart — if donut gained a new step type or a new place for a
 * template, a key would silently vanish from here. KeyMap.ValidatorContract.phpt
 * guards against that.
 */
final class KeyMap
{
	/**
	 * @param array<string, list<string>> $writesByKey  key => paths
	 * @param array<string, list<string>> $readsByKey
	 * @param array<string, list<string>> $writesByPath path => keys
	 * @param array<string, list<string>> $readsByPath
	 */
	private function __construct(
		private readonly array $writesByKey,
		private readonly array $readsByKey,
		private readonly array $writesByPath,
		private readonly array $readsByPath,
	) {
	}


	public static function of(Workflow $workflow): self
	{
		$writesByKey = [];
		$readsByKey = [];

		self::walk($workflow->steps, StepPath::root($workflow->name), $writesByKey, $readsByKey);

		return new self(
			$writesByKey,
			$readsByKey,
			self::invert($writesByKey),
			self::invert($readsByKey),
		);
	}


	/** @return list<string> key names, alphabetically */
	public function writesAt(StepPath|string $path): array
	{
		return $this->writesByPath[(string) $path] ?? [];
	}


	/** @return list<string> key names, alphabetically */
	public function readsAt(StepPath|string $path): array
	{
		return $this->readsByPath[(string) $path] ?? [];
	}


	/** @return list<string> paths, in order of occurrence in the workflow */
	public function writeSitesOf(string $key): array
	{
		return $this->writesByKey[$key] ?? [];
	}


	/** @return list<string> paths, in order of occurrence in the workflow */
	public function readSitesOf(string $key): array
	{
		return $this->readsByKey[$key] ?? [];
	}


	/** @return list<string> all key names, alphabetically */
	public function keys(): array
	{
		// array_keys() converts array keys made up of digits only to int —
		// "456" is a valid key name (Template::isKeyName()), so without
		// strval() an int would land here and classAt() would compare it
		// against a string strictly and never match.
		$keys = \array_map(\strval(...), \array_keys($this->writesByKey + $this->readsByKey));
		\sort($keys);

		return $keys;
	}


	/**
	 * The class for highlighting a step. A step may both read and write the
	 * same key (`set x = {%x%}`), then it gets both.
	 */
	public function classAt(StepPath|string $path, ?string $key): string
	{
		if ($key === null) {
			return '';
		}

		$classes = [];

		if (\in_array($key, $this->writesAt($path), true)) {
			$classes[] = 'write';
		}

		if (\in_array($key, $this->readsAt($path), true)) {
			$classes[] = 'read';
		}

		return \implode(' ', $classes);
	}


	/**
	 * @param array<int, \Donut\Format\Step>  $steps
	 * @param array<string, list<string>>     $writes
	 * @param array<string, list<string>>     $reads
	 */
	private static function walk(array $steps, StepPath $path, array &$writes, array &$reads): void
	{
		foreach ($steps as $i => $step) {
			$here = $path->index($i);
			$at = (string) $here;

			// A single step can add the same path for the same key more than
			// once — two block inputs reading the same key, or two out
			// channels pointing at the same key. Template::getKeys() only
			// dedupes within one template, not across a step's templates or
			// channels, so without these sets writeSitesOf()/readSitesOf()
			// would return the same path more than once.
			$writesHere = [];
			$readsHere = [];

			if ($step instanceof RunStep) {
				// $step->in is keyed by the block input name; the map's keys
				// only come from the templates that are its values.
				foreach ($step->in as $template) {
					foreach ($template->getKeys() as $key) {
						self::record($reads, $readsHere, $key, $at);
					}
				}

				foreach ($step->out as $key) {
					self::record($writes, $writesHere, $key, $at);
				}

			} elseif ($step instanceof SetStep) {
				foreach ($step->value->getKeys() as $key) {
					self::record($reads, $readsHere, $key, $at);
				}

				self::record($writes, $writesHere, $step->key, $at);

			} elseif ($step instanceof IfStep) {
				foreach ($step->condition->left->getKeys() as $key) {
					self::record($reads, $readsHere, $key, $at);
				}

				if ($step->condition->right !== null) {
					foreach ($step->condition->right->getKeys() as $key) {
						self::record($reads, $readsHere, $key, $at);
					}
				}

				self::walk($step->then, $here->child('then'), $writes, $reads);
				self::walk($step->else, $here->child('else'), $writes, $reads);

			} elseif ($step instanceof ForeachStep) {
				foreach ($step->over->getKeys() as $key) {
					self::record($reads, $readsHere, $key, $at);
				}

				self::record($writes, $writesHere, $step->as, $at);

				self::walk($step->steps, $here->child('steps'), $writes, $reads);

			} else {
				// A new step type would silently fall through the if/elseif
				// chain unnoticed — instead of just missing the key from its
				// body, this throws on every workflow that uses it.
				throw new \LogicException('unknown step type ' . $step::class);
			}
		}
	}


	/**
	 * Records a key's path, at most once per step — $seenHere is the set of
	 * keys this step has already written to $target.
	 *
	 * @param array<string, list<string>> $target
	 * @param array<string, true>         $seenHere
	 */
	private static function record(array &$target, array &$seenHere, string $key, string $at): void
	{
		if (isset($seenHere[$key])) {
			return;
		}

		$seenHere[$key] = true;
		$target[$key][] = $at;
	}


	/**
	 * @param  array<string, list<string>> $byKey
	 * @return array<string, list<string>> path => keys, alphabetically and deduplicated
	 */
	private static function invert(array $byKey): array
	{
		$byPath = [];

		foreach ($byKey as $key => $paths) {
			foreach ($paths as $path) {
				$byPath[$path][$key] = true;
			}
		}

		return \array_map(
			function (array $keys): array {
				// Same reason as in keys() — under a numeric key name, the
				// $keys array key is int, strval() turns it back to string.
				$names = \array_map(\strval(...), \array_keys($keys));
				\sort($names);

				return $names;
			},
			$byPath,
		);
	}
}
