<?php

declare(strict_types=1);

namespace Donut\Gui;

use Donut\Format\ForeachStep;
use Donut\Format\IfStep;
use Donut\Format\RunStep;
use Donut\Format\SetStep;
use Donut\Format\Workflow;


/**
 * Kde který klíč vzniká a kdo ho čte.
 *
 * Odvozuje se z naparsovaného stromu, ne z validátoru — Template::getKeys()
 * je veřejná a víc než to potřeba není. Podmíněnost zápisu je vidět z cesty:
 * co obsahuje .then, .else nebo .steps, je uvnitř větve.
 *
 * Je to ale druhý průchod stromem vedle toho validátorova, takže by se ty dva
 * mohly rozejít — kdyby v donutu přibyl typ kroku nebo nové místo pro šablonu,
 * klíč by odsud tiše zmizel. Hlídá to KeyMap.ValidatorContract.phpt.
 */
final class KeyMap
{
	/**
	 * @param array<string, list<string>> $writesByKey  klíč => cesty
	 * @param array<string, list<string>> $readsByKey
	 * @param array<string, list<string>> $writesByPath cesta => klíče
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


	/** @return list<string> jména klíčů, abecedně */
	public function writesAt(StepPath|string $path): array
	{
		return $this->writesByPath[(string) $path] ?? [];
	}


	/** @return list<string> jména klíčů, abecedně */
	public function readsAt(StepPath|string $path): array
	{
		return $this->readsByPath[(string) $path] ?? [];
	}


	/** @return list<string> cesty, v pořadí výskytu ve workflow */
	public function writeSitesOf(string $key): array
	{
		return $this->writesByKey[$key] ?? [];
	}


	/** @return list<string> cesty, v pořadí výskytu ve workflow */
	public function readSitesOf(string $key): array
	{
		return $this->readsByKey[$key] ?? [];
	}


	/** @return list<string> všechna jména klíčů, abecedně */
	public function keys(): array
	{
		$keys = \array_keys($this->writesByKey + $this->readsByKey);
		\sort($keys);

		return $keys;
	}


	/**
	 * Třída pro zvýraznění kroku. Krok může týž klíč číst i zapisovat
	 * (`set x = {%x%}`), pak dostane obojí.
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

			if ($step instanceof RunStep) {
				// $step->in je klíčované jménem vstupu kamene; klíče mapy
				// jsou až v šablonách, které jsou jeho hodnotami.
				foreach ($step->in as $template) {
					foreach ($template->getKeys() as $key) {
						$reads[$key][] = $at;
					}
				}

				foreach ($step->out as $key) {
					$writes[$key][] = $at;
				}

			} elseif ($step instanceof SetStep) {
				foreach ($step->value->getKeys() as $key) {
					$reads[$key][] = $at;
				}

				$writes[$step->key][] = $at;

			} elseif ($step instanceof IfStep) {
				foreach ($step->condition->left->getKeys() as $key) {
					$reads[$key][] = $at;
				}

				if ($step->condition->right !== null) {
					foreach ($step->condition->right->getKeys() as $key) {
						$reads[$key][] = $at;
					}
				}

				self::walk($step->then, $here->child('then'), $writes, $reads);
				self::walk($step->else, $here->child('else'), $writes, $reads);

			} elseif ($step instanceof ForeachStep) {
				foreach ($step->over->getKeys() as $key) {
					$reads[$key][] = $at;
				}

				$writes[$step->as][] = $at;

				self::walk($step->steps, $here->child('steps'), $writes, $reads);
			}
		}
	}


	/**
	 * @param  array<string, list<string>> $byKey
	 * @return array<string, list<string>> cesta => klíče, abecedně a bez duplicit
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
				$names = \array_keys($keys);
				\sort($names);

				return $names;
			},
			$byPath,
		);
	}
}
