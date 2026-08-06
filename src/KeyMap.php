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
		// array_keys() konvertuje klíče pole složené jen z číslic na int —
		// "456" jako jméno klíče je platné (Template::isKeyName()), takže
		// bez strval() by se sem dostal int a classAt() by proti němu
		// porovnávala string striktně a nikdy neuspěla.
		$keys = \array_map(\strval(...), \array_keys($this->writesByKey + $this->readsByKey));
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

			// Jeden krok umí touž cestu ke stejnému klíči přidat víckrát —
			// dva vstupy kamene čtoucí stejný klíč, nebo dva kanály out
			// mířící do stejného klíče. Template::getKeys() dedupuje jen
			// uvnitř jedné šablony, ne napříč šablonami/kanály jednoho
			// kroku, takže bez těchhle sad by writeSitesOf()/readSitesOf()
			// vrátily tutéž cestu vícekrát.
			$writesHere = [];
			$readsHere = [];

			if ($step instanceof RunStep) {
				// $step->in je klíčované jménem vstupu kamene; klíče mapy
				// jsou až v šablonách, které jsou jeho hodnotami.
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
			}
		}
	}


	/**
	 * Zapíše cestu ke klíči, nejvýš jednou na krok — $seenHere je sada
	 * klíčů, které tenhle krok do $target už zapsal.
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
				// Stejný důvod jako v keys() — klíč pole $keys je pod
				// numerickým jménem klíče int, strval() ho vrátí na string.
				$names = \array_map(\strval(...), \array_keys($keys));
				\sort($names);

				return $names;
			},
			$byPath,
		);
	}
}
