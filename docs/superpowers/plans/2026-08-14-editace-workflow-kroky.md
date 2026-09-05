# Editace workflow — kroky — implementační plán

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Přidávat, upravovat, přesouvat a mazat kroky existujícího workflow v GUI.

**Architecture:** Donut se nemění. V GUI vzniknou tři čisté jednotky
(`StepPath::parse()`, `StepTree`, `StepMapper`), malý `WorkflowStore` a nad
nimi ovládání v už existujícím přehledu kroků plus stránka na jeden krok.

**Tech Stack:** PHP 8.3+, nette/application, nette/forms, latte, nette/tester,
PHPStan level max.

**Spec:** `docs/superpowers/specs/2026-08-14-editace-workflow-kroky-design.md`

## Global Constraints

- **Všechno je v `gui/`.** Donut (kořen repozitáře) se v tomhle plánu nemění
  ani jedním řádkem. Testy a PHPStan se pouštějí **z `gui/`**.
- Stav před začátkem: GUI 16 testů, donut 32 testů, PHPStan level max čistý
  u obojího. Donutovu sadu spusť jednou na konci jako pojistku, že jsi do ní
  nesáhl.
- PHP 8.3+, **tabulátory** jako odsazení, `declare(strict_types=1);` v každém
  souboru, dvě prázdné řádky mezi metodami — přesně jako okolní kód v `gui/src/`.
- **Uživatelské texty a komentáře česky, kód a identifikátory anglicky.**
  V `gui/` je to dodržené bez výjimky — nepiš `$krok` ani `$cesta`.
- **Prázdný řetězec a „nevyplněno" jsou totéž** — sekce 6 specifikace formátu.
- **GUI nesmí sahat do `../src` ani `../vendor` relativní cestou** pro kód.
- **Inline `<script>` v Latte musí mít `n:syntax="off"`** — bez něj Latte spadne
  na objektovém literálu. `tests/Latte.TemplatesCompile.phpt` to hlídá.
- **Externí `.js` soubor se nedoručí** — GUI běží z adresáře projektu
  s `gui/www/index.php` jako routerem, takže vestavěný server hledá statické
  soubory v projektu, ne v `gui/www`. JS patří inline do šablony.
- **Žádná CSRF ochrana ani session.** `Form::addProtection()` vyžaduje session,
  kterou GUI nemá; ze stejného důvodu se nepoužívají flash zprávy. Odmítnutí
  se ukazuje jako chyba formuláře. **Nepřidávej `addProtection()` ani session.**
- **Validace u workflow neblokuje uložení.** Vědomý rozdíl oproti kameni: každá
  změna se uloží a problémy se ukážou v přehledu. Nepiš žádnou `hasErrors()`
  bránu.
- **Žádný GET nesmí nic měnit.** Přesun a mazání jdou přes POST.
- `git add` s konkrétními cestami, **nikdy** `git add -A` ani `git add .` —
  v pracovním stromu jsou čtyři nesledované položky
  (`.github/workflows/frontbot.yml`, `docs/logo.png`,
  `donut-org_donut.sublime-workspace`, `rss`), které do commitu nepatří.
- **Do `docs/workflows/` se nesmí zapisovat** — `git status --porcelain docs/workflows/`
  musí zůstat prázdný. Testy z ní jen čtou.

## Tvar cesty ke kroku

Kontrakt mezi Taskem 1 a vším ostatním. Cestu skládá donutův `Validator`
a jeho tvar je připnutý testem `tests/Donut/Validator.location.phpt`:

```
card-dev.json:steps[7]                 sedmý krok workflow card-dev
card-dev.json:steps[7].then[0]         první krok větve then toho kroku
sync.json:steps[3].steps[1].steps[0]   krok ve dvakrát vnořeném foreach
```

`segments()` z toho dělá `[['steps', 7], ['then', 0]]` — dvojice
*jméno kolekce* + *index*. První jméno je vždycky `steps`.

## Struktura souborů

```
gui/src/StepPath.php            rozšíří se o parse(), workflowName(), segments()
gui/src/StepTree.php            get, replace, insert, remove, moveUp, moveDown
gui/src/StepMapper.php          hodnoty formuláře ↔ Step, čtyři typy
gui/src/WorkflowStore.php       path() a save()
gui/tests/StepPath.parse.phpt
gui/tests/StepTree.phpt
gui/tests/StepMapper.phpt
gui/tests/WorkflowStore.phpt

gui/src/Presentation/Workflow/WorkflowPresenter.php   rozšíří se
gui/src/Presentation/Workflow/steps.latte             ovládání u kroků
gui/src/Presentation/Workflow/step.latte              stránka jednoho kroku
gui/src/Presentation/Workflow/WorkflowStepTemplate.php
gui/src/Presentation/rows.latte                       sdílený JS pro opakující se řádky
gui/tests/inc/workflowPresenter.php                   továrna na prezenter
gui/tests/WorkflowPresenter.controls.phpt
gui/tests/WorkflowPresenter.step.phpt
```

---

### Task 1: `StepPath` umí cestu rozebrat

**Files:**
- Modify: `gui/src/StepPath.php`
- Create: `gui/tests/StepPath.parse.phpt`

**Interfaces:**
- Produces:
  - `StepPath::parse(string $path): self` — hodí `\InvalidArgumentException` na tvar, který cestou ke kroku není
  - `StepPath::workflowName(): string`
  - `StepPath::segments(): list<array{string, int}>`

- [ ] **Step 1: Napiš padající test**

Vytvoř `gui/tests/StepPath.parse.phpt`:

```php
<?php

declare(strict_types=1);

use Donut\Gui\StepPath;
use Donut\Parser\WorkflowParser;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

// --- parse a zpátky dá tentýž řetězec ---

foreach ([
	'card-dev.json:steps[0]',
	'card-dev.json:steps[7].then[0]',
	'sync.json:steps[3].steps[1].steps[0]',
	'x.json:steps[2].else[10]',
] as $path) {
	Assert::same($path, (string) StepPath::parse($path), $path);
}

// --- segments ---

Assert::same(
	[['steps', 7], ['then', 0]],
	StepPath::parse('card-dev.json:steps[7].then[0]')->segments(),
);

Assert::same([['steps', 0]], StepPath::parse('card-dev.json:steps[0]')->segments());

// --- jméno workflow ---

Assert::same('card-dev', StepPath::parse('card-dev.json:steps[7].then[0]')->workflowName());
Assert::same('card-dev', StepPath::workflow('card-dev')->workflowName());

// Cesta na celé workflow nemá žádný krok.
Assert::same([], StepPath::workflow('card-dev')->segments());

// --- co cestou ke kroku není ---

foreach ([
	'card-dev.json',              // celé workflow, ne krok
	'card-dev.json:steps',        // seznam, ne krok
	'card-dev.json:steps[7].then', // taky seznam
	'card-dev.json:steps[]',
	'card-dev.json:steps[a]',
	'card-dev.json:kroky[0]',
	'steps[0]',
	'',
	'card-dev.json:steps[0];rm -rf /',
] as $bad) {
	// Assert::exception() bere jako čtvrtý parametr kód výjimky, ne popisek —
	// popisek by se porovnával s kódem 0 a test by padal i proti správné
	// implementaci. Který tvar selhal, řekne hláška z parse(), která ho nese.
	Assert::exception(fn() => StepPath::parse($bad), InvalidArgumentException::class);
}

// --- cesty, které skládá šablona, musí jít rozebrat ---
//
// Pojistka proti tomu, že by se skládání a rozebírání rozešlo: projdi strom
// všech čtyř skutečných workflow, slož cestu tak, jak to dělá steps.latte,
// a ověř, že ji parse() přijme a segments() vrátí totéž, z čeho vznikla.

$parser = new WorkflowParser;
$files = \glob(__DIR__ . '/../../docs/workflows/donut/workflows/*.json');
Assert::count(4, $files === false ? [] : $files);

$checked = 0;

$walk = function (array $steps, StepPath $path, array $segments) use (&$walk, &$checked): void {
	foreach ($steps as $i => $step) {
		$at = $path->index($i);
		$here = [...$segments];
		$here[\count($here) - 1][1] = $i;

		Assert::same((string) $at, (string) StepPath::parse((string) $at));
		Assert::same($here, $at->segments(), (string) $at);
		$checked++;

		if ($step instanceof Donut\Format\IfStep) {
			$walk($step->then, $at->child('then'), [...$here, ['then', 0]]);
			$walk($step->else, $at->child('else'), [...$here, ['else', 0]]);

		} elseif ($step instanceof Donut\Format\ForeachStep) {
			$walk($step->steps, $at->child('steps'), [...$here, ['steps', 0]]);
		}
	}
};

foreach ($files === false ? [] : $files as $file) {
	$workflow = $parser->parseFile($file);
	$walk($workflow->steps, StepPath::root($workflow->name), [['steps', 0]]);
}

Assert::same(96, $checked, 'referenční zátěž má 96 kroků');
```

- [ ] **Step 2: Spusť test a ověř, že padá**

Run: `cd /home/honza/Dokumenty/Projekty/donut-org/donut/gui && vendor/bin/tester tests/StepPath.parse.phpt -C`
Expected: FAIL — `StepPath::parse()` neexistuje.

- [ ] **Step 3: Rozšiř `StepPath`**

Do `gui/src/StepPath.php` přidej tři metody. Nic stávajícího neměň.

```php
	/**
	 * Cesta ke kroku z adresy. Přijímá jen tvar, který končí indexem —
	 * `…:steps[7].then` je seznam, ne krok, a jako cíl editace nedává smysl.
	 *
	 * @throws \InvalidArgumentException
	 */
	public static function parse(string $path): self
	{
		if (!\preg_match('~^[^:]+\.json:steps\[\d+](\.(then|else|steps)\[\d+])*$~', $path)) {
			throw new \InvalidArgumentException("\"{$path}\" není cesta ke kroku.");
		}

		return new self($path);
	}


	public function workflowName(): string
	{
		$colon = \strpos($this->path, ':');
		$head = $colon === false ? $this->path : \substr($this->path, 0, $colon);

		return \substr($head, 0, -\strlen('.json'));
	}


	/**
	 * Dvojice *jméno kolekce* + *index*, v pořadí od kořene.
	 * `steps[7].then[0]` → `[['steps', 7], ['then', 0]]`.
	 *
	 * @return list<array{string, int}>
	 */
	public function segments(): array
	{
		$colon = \strpos($this->path, ':');

		if ($colon === false) {
			return [];
		}

		\preg_match_all(
			'~(steps|then|else)\[(\d+)]~',
			\substr($this->path, $colon + 1),
			$matches,
			\PREG_SET_ORDER,
		);

		return \array_map(
			fn(array $m): array => [$m[1], (int) $m[2]],
			$matches,
		);
	}
```

- [ ] **Step 4: Spusť test a ověř, že prochází**

Run: `cd /home/honza/Dokumenty/Projekty/donut-org/donut/gui && vendor/bin/tester tests/StepPath.parse.phpt -C`
Expected: PASS.

- [ ] **Step 5: Ověř mutací**

Zaveď postupně tyhle tři chyby a po každé spusť test:

1. v `parse()` zruš `$` na konci regulárního výrazu
   (`…\[\d+])*~` místo `…\[\d+])*$~`)
2. v `segments()` vrať `$m[2]` bez `(int)`
3. v `workflowName()` vynech odříznutí `.json`

Expected: každá shodí `gui/tests/StepPath.parse.phpt`.

Po každé mutaci ji vrať a nakonec ověř `git status --porcelain gui/src/`.
**Co některá mutace projde, napiš do reportu.**

- [ ] **Step 6: Spusť sadu GUI a PHPStan**

Run: `cd /home/honza/Dokumenty/Projekty/donut-org/donut/gui && vendor/bin/tester tests -C && vendor/bin/phpstan analyse`
Expected: 17 testů (bylo 16), PHPStan bez chyb.

- [ ] **Step 7: Commit**

```bash
cd /home/honza/Dokumenty/Projekty/donut-org/donut
git add gui/src/StepPath.php gui/tests/StepPath.parse.phpt
git commit -m "GUI: StepPath umí cestu rozebrat"
```

---

### Task 2: `StepTree`

Těžiště projektu. `Workflow` i všechny třídy kroků jsou `readonly`, takže každá
operace strom přestaví a vrátí **nový** `Workflow`.

**Files:**
- Create: `gui/src/StepTree.php`, `gui/tests/StepTree.phpt`

**Interfaces:**
- Consumes: `StepPath::segments()` z Tasku 1.
- Produces, všechno statické:
  - `StepTree::get(Workflow $workflow, StepPath $at): Step`
  - `StepTree::replace(Workflow $workflow, StepPath $at, Step $step): Workflow`
  - `StepTree::insert(Workflow $workflow, StepPath $at, Step $step): Workflow`
  - `StepTree::remove(Workflow $workflow, StepPath $at): Workflow`
  - `StepTree::moveUp(Workflow $workflow, StepPath $at): Workflow`
  - `StepTree::moveDown(Workflow $workflow, StepPath $at): Workflow`
  - Neplatná cesta hodí `\OutOfRangeException`.

**Cesta pojmenovává pozici, ne jen existující krok.** `insert()` vloží na dané
místo a ostatní posune, takže `remove` a `insert` zpátky na tutéž cestu vrátí
původní strom. `+ krok` na konci seznamu je vložení na pozici za posledním.

- [ ] **Step 1: Napiš padající test**

Vytvoř `gui/tests/StepTree.phpt`:

```php
<?php

declare(strict_types=1);

use Donut\Format\ForeachStep;
use Donut\Format\IfStep;
use Donut\Format\SetStep;
use Donut\Format\Workflow;
use Donut\Gui\StepPath;
use Donut\Gui\StepTree;
use Donut\Parser\WorkflowParser;
use Donut\Template;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

$parser = new WorkflowParser;
$files = \glob(__DIR__ . '/../../docs/workflows/donut/workflows/*.json');
Assert::count(4, $files === false ? [] : $files);

/** @return list<StepPath> */
$allPaths = function (Workflow $workflow): array {
	$paths = [];

	$walk = function (array $steps, StepPath $path) use (&$walk, &$paths): void {
		foreach ($steps as $i => $step) {
			$at = $path->index($i);
			$paths[] = $at;

			if ($step instanceof IfStep) {
				$walk($step->then, $at->child('then'));
				$walk($step->else, $at->child('else'));

			} elseif ($step instanceof ForeachStep) {
				$walk($step->steps, $at->child('steps'));
			}
		}
	};

	$walk($workflow->steps, StepPath::root($workflow->name));

	return $paths;
};

// --- invarianty nad celou referenční zátěží ---
//
// Ruční případy by pokryly pár tvarů; tohle pokryje 96 kroků do hloubky 3
// naráz. Kdyby se get() a replace() rozešly o jeden index nebo si spletly
// větev, spadne to hned na prvním workflow.

$checked = 0;

foreach ($files === false ? [] : $files as $file) {
	$workflow = $parser->parseFile($file);
	$before = \serialize($workflow);

	foreach ($allPaths($workflow) as $at) {
		$checked++;

		// get + replace míří na tentýž uzel
		Assert::same(
			$before,
			\serialize(StepTree::replace($workflow, $at, StepTree::get($workflow, $at))),
			"replace(get) na {$at}",
		);

		// remove a insert zpátky vrátí původní strom
		Assert::same(
			$before,
			\serialize(StepTree::insert(
				StepTree::remove($workflow, $at),
				$at,
				StepTree::get($workflow, $at),
			)),
			"remove+insert na {$at}",
		);

		// Tentýž pohyb dvakrát na téže pozici je identita: prohodí tutéž
		// dvojici tam a zpátky. Pozor, `moveUp(moveDown($w, $at), $at)`
		// identita **není** — `$at` je pozice, ne krok, takže druhý swap
		// míří na jiný pár než první.
		Assert::same(
			$before,
			\serialize(StepTree::moveDown(StepTree::moveDown($workflow, $at), $at)),
			"moveDown dvakrát na {$at}",
		);

		Assert::same(
			$before,
			\serialize(StepTree::moveUp(StepTree::moveUp($workflow, $at), $at)),
			"moveUp dvakrát na {$at}",
		);
	}
}

Assert::same(96, $checked, 'referenční zátěž má 96 kroků');

// --- konkrétní chování na malém stromě ---

$set = fn(string $key): SetStep => new SetStep(key: $key, value: Template::parse('x'));

$workflow = new Workflow(name: 'w', steps: [
	$set('a'),
	new IfStep(
		condition: new Donut\Format\Condition(left: Template::parse('{%x%}'), op: 'not_empty'),
		then: [$set('t1'), $set('t2')],
	),
	$set('b'),
]);

$keys = function (Workflow $w): array {
	return \array_map(
		fn($s): string => $s instanceof SetStep ? $s->key : 'if',
		$w->steps,
	);
};

// moveUp prohodí se sousedem
Assert::same(['if', 'a', 'b'], $keys(StepTree::moveUp($workflow, StepPath::parse('w.json:steps[1]'))));

// na kraji je to no-op, ne chyba — šablona šipku nevykreslí, ale ručně
// poslaný POST nesmí spadnout
Assert::same(['a', 'if', 'b'], $keys(StepTree::moveUp($workflow, StepPath::parse('w.json:steps[0]'))));
Assert::same(['a', 'if', 'b'], $keys(StepTree::moveDown($workflow, StepPath::parse('w.json:steps[2]'))));

// insert doprostřed posune ostatní
Assert::same(
	['a', 'novy', 'if', 'b'],
	$keys(StepTree::insert($workflow, StepPath::parse('w.json:steps[1]'), $set('novy'))),
);

// insert na konec
Assert::same(
	['a', 'if', 'b', 'novy'],
	$keys(StepTree::insert($workflow, StepPath::parse('w.json:steps[3]'), $set('novy'))),
);

// remove uzavře díru
Assert::same(['a', 'b'], $keys(StepTree::remove($workflow, StepPath::parse('w.json:steps[1]'))));

// smazání if vezme celou větev s sebou
Assert::count(2, StepTree::remove($workflow, StepPath::parse('w.json:steps[1]'))->steps);

// --- práce uvnitř větve ---

$vetev = StepTree::insert($workflow, StepPath::parse('w.json:steps[1].then[0]'), $set('t0'));
$if = $vetev->steps[1];
Assert::type(IfStep::class, $if);
Assert::same(['t0', 't1', 't2'], \array_map(fn($s): string => $s->key, $if->then));

// prázdná větev else — vložení do ní je jediná cesta, jak ji naplnit
$doElse = StepTree::insert($workflow, StepPath::parse('w.json:steps[1].else[0]'), $set('e0'));
Assert::same(['e0'], \array_map(fn($s): string => $s->key, $doElse->steps[1]->else));

// --- neplatné cesty ---

Assert::exception(
	fn() => StepTree::get($workflow, StepPath::parse('w.json:steps[9]')),
	OutOfRangeException::class,
);

// sestup do větve u kroku, který ji nemá
Assert::exception(
	fn() => StepTree::get($workflow, StepPath::parse('w.json:steps[0].then[0]')),
	OutOfRangeException::class,
);

// insert za konec seznamu je v pořádku, dál už ne
Assert::exception(
	fn() => StepTree::insert($workflow, StepPath::parse('w.json:steps[4]'), $set('x')),
	OutOfRangeException::class,
);

// --- hlavička workflow zůstane netknutá ---

$sHlavickou = new Workflow(
	name: 'w',
	inputs: ['a' => new Donut\Format\Input(name: 'a')],
	steps: [$set('a')],
	description: 'Popis',
);

$po = StepTree::remove($sHlavickou, StepPath::parse('w.json:steps[0]'));
Assert::same('w', $po->name);
Assert::same('Popis', $po->description);
Assert::same(['a'], \array_keys($po->inputs));
Assert::same([], $po->steps);
```

- [ ] **Step 2: Spusť test a ověř, že padá**

Run: `cd /home/honza/Dokumenty/Projekty/donut-org/donut/gui && vendor/bin/tester tests/StepTree.phpt -C`
Expected: FAIL — `Donut\Gui\StepTree` neexistuje.

- [ ] **Step 3: Napiš `StepTree`**

Vytvoř `gui/src/StepTree.php`:

```php
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

			[$steps[$i], $steps[$j]] = [$steps[$j], $steps[$i]];

			return $steps;
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
			self::transform($workflow->steps, $segments, $operation, $at),
			$workflow->description,
		);
	}


	/**
	 * @param  list<Step>                    $steps
	 * @param  list<array{string, int}>      $segments
	 * @param  callable(list<Step>, int): list<Step> $operation
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
			return $step->then;
		}

		if ($step instanceof IfStep && $property === 'else') {
			return $step->else;
		}

		if ($step instanceof ForeachStep && $property === 'steps') {
			return $step->steps;
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
```

- [ ] **Step 4: Spusť test a ověř, že prochází**

Run: `cd /home/honza/Dokumenty/Projekty/donut-org/donut/gui && vendor/bin/tester tests/StepTree.phpt -C`
Expected: PASS.

Kdyby invariant nad zátěží spadl, **neupravuj test, aby prošel** — vypiš si
cestu z hlášky, podívej se na ten krok v souboru a najdi, kde se navigace
rozešla.

- [ ] **Step 5: Ověř mutací**

Zaveď postupně tyhle čtyři chyby a po každé spusť test:

1. v `transform()` použij `\array_slice($segments, 0)` místo `1`
2. v `insert()` změň podmínku na `$i >= \count($steps)`
3. v `withChildren()` u větve `else` vrať `new IfStep($step->condition, $children, $step->else, $step->name)`
   (tedy zapiš děti do `then` místo do `else`)
4. ve `swap()` vynech kontrolu `if (!isset($steps[$j]))`

Expected: každá shodí `gui/tests/StepTree.phpt`.

Mutace 3 je nejzajímavější: chytit ji může jen průchod, který do `else`
doopravdy sestupuje. **Kdyby prošla, napiš to do reportu** — znamenalo by to,
že zátěž ani ruční případy `else` nepokrývají dost.

Po každé mutaci ji vrať a nakonec ověř `git status --porcelain gui/src/`.

- [ ] **Step 6: Spusť sadu GUI a PHPStan**

Run: `cd /home/honza/Dokumenty/Projekty/donut-org/donut/gui && vendor/bin/tester tests -C && vendor/bin/phpstan analyse`
Expected: 18 testů (bylo 17), PHPStan bez chyb.

- [ ] **Step 7: Commit**

```bash
cd /home/honza/Dokumenty/Projekty/donut-org/donut
git add gui/src/StepTree.php gui/tests/StepTree.phpt
git commit -m "GUI: StepTree — strukturální operace nad stromem kroků"
```

---

### Task 3: `StepMapper`

Obdoba `BlockMapperu` pro kroky. Čistá obousměrná konverze, nezná Nette ani HTTP.

**Files:**
- Create: `gui/src/StepMapper.php`, `gui/tests/StepMapper.phpt`

**Interfaces:**
- Produces:
  - `StepMapper::toStep(array $values): Step`
  - `StepMapper::toValues(Step $step): array`
  - `$values['type']` je `run` | `set` | `if` | `foreach` a rozhoduje, co se čte.

**Tvar hodnot** — kontrakt s formulářem v Tasku 6:

```php
// run
[
    'type' => 'run',
    'name' => '',                       // '' = nevyplněno
    'block' => 'jq',
    'in' => [                           // indexy můžou mít díry
        0 => ['key' => 'stdin', 'value' => '{%payload%}'],
        2 => ['key' => 'filter', 'value' => '.id'],
    ],
    'out' => [
        0 => ['channel' => 'result', 'value' => 'cardId'],
    ],
    'timeout' => '',                    // '' = nevyplněno
    'allowFailure' => 'inherit',        // 'inherit' | 'none' | 'any' | 'list'
    'allowFailureCodes' => '0, 1',
]

// set
['type' => 'set', 'name' => '', 'key' => 'branch', 'value' => 'feature/{%id%}']

// if
['type' => 'if', 'name' => '', 'left' => '{%repo%}', 'op' => 'eq', 'right' => '{%target%}']

// foreach
['type' => 'foreach', 'name' => '', 'over' => '{%cards%}', 'as' => 'card']
```

**`allowFailure` má u kroku čtyři stavy, ne tři.** `inherit` (= `null`,
převzít z kamene) je výchozí a je to rozdíl oproti kameni, kde `null` neexistuje.

- [ ] **Step 1: Napiš padající test**

Vytvoř `gui/tests/StepMapper.phpt`:

```php
<?php

declare(strict_types=1);

use Donut\Format\Condition;
use Donut\Format\ForeachStep;
use Donut\Format\IfStep;
use Donut\Format\RunStep;
use Donut\Format\SetStep;
use Donut\Gui\StepMapper;
use Donut\Parser\WorkflowParser;
use Donut\Template;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

// --- round-trip nad referenční zátěží ---
//
// 96 kroků: 75 run, 9 set, 5 if, 7 foreach. Kdyby mapper zahodil timeout,
// allow_failure nebo name, tohle to odhalí.

$parser = new WorkflowParser;
$files = \glob(__DIR__ . '/../../docs/workflows/donut/workflows/*.json');
Assert::count(4, $files === false ? [] : $files);

$checked = 0;

// IfStep a ForeachStep nesou vnořené kroky, které toValues() do hodnot
// formuláře nedává — stránka kroku větve needituje, ty se plní z přehledu.
// toStep() proto vrací krok s prázdnými větvemi a round-trip se u nich musí
// porovnávat proti kroku zbavenému dětí.
$bare = function (Donut\Format\Step $step): Donut\Format\Step {
	if ($step instanceof IfStep) {
		return new IfStep($step->condition, [], [], $step->name);
	}

	if ($step instanceof ForeachStep) {
		return new ForeachStep($step->over, $step->as, [], $step->name);
	}

	return $step;
};

$walk = function (array $steps) use (&$walk, &$checked, $bare): void {
	foreach ($steps as $step) {
		$checked++;

		Assert::same(
			\serialize($bare($step)),
			\serialize(StepMapper::toStep(StepMapper::toValues($step))),
			'round-trip ' . $step::class,
		);

		if ($step instanceof IfStep) {
			$walk($step->then);
			$walk($step->else);

		} elseif ($step instanceof ForeachStep) {
			$walk($step->steps);
		}
	}
};

foreach ($files === false ? [] : $files as $file) {
	$walk($parser->parseFile($file)->steps);
}

Assert::same(96, $checked, 'referenční zátěž má 96 kroků');

// --- run: všechna volitelná pole ---
$run = StepMapper::toStep([
	'type' => 'run',
	'name' => 'pojmenovaný',
	'block' => 'jq',
	// Díry v indexech schválně — JS řádky nepřečísluje.
	'in' => [
		0 => ['key' => 'stdin', 'value' => '{%payload%}'],
		2 => ['key' => 'filter', 'value' => '.id'],
	],
	'out' => [1 => ['channel' => 'result', 'value' => 'cardId']],
	'timeout' => '90',
	'allowFailure' => 'list',
	'allowFailureCodes' => '0, 1',
]);

Assert::type(RunStep::class, $run);
Assert::same('jq', $run->block);
Assert::same('pojmenovaný', $run->name);
Assert::same(['stdin', 'filter'], \array_keys($run->in));
Assert::same('{%payload%}', $run->in['stdin']->getSource());
Assert::same(['result' => 'cardId'], $run->out);
Assert::same(90, $run->timeout);
Assert::same([0, 1], $run->allowFailure);

// --- run: čtyři stavy allow_failure ---

$base = ['type' => 'run', 'name' => '', 'block' => 'echo', 'in' => [], 'out' => [],
	'timeout' => '', 'allowFailure' => 'inherit', 'allowFailureCodes' => ''];

Assert::null(StepMapper::toStep($base)->allowFailure);
Assert::false(StepMapper::toStep(['allowFailure' => 'none'] + $base)->allowFailure);
Assert::true(StepMapper::toStep(['allowFailure' => 'any'] + $base)->allowFailure);
Assert::same([2], StepMapper::toStep(['allowFailure' => 'list', 'allowFailureCodes' => '2, x'] + $base)->allowFailure);

// Prázdný výčet u 'list' spadne na inherit — pole [] by parser odmítl.
Assert::null(StepMapper::toStep(['allowFailure' => 'list'] + $base)->allowFailure);

// --- pořadí řádků drží ksort, ne pořadí zápisu ---
//
// Pořadí klíčů z POSTu není zaručené a u in i out na pořadí záleží. Bez
// sestupného případu by se vypuštění ksort() v rows() nijak neprojevilo —
// referenční zátěž má klíče vždycky vzestupně a ruční případy jen s dírami.

$reversed = StepMapper::toStep([
	'in' => [
		1 => ['key' => 'druhy', 'value' => 'b'],
		0 => ['key' => 'prvni', 'value' => 'a'],
	],
	'out' => [
		1 => ['channel' => 'stderr', 'value' => 'err'],
		0 => ['channel' => 'result', 'value' => 'res'],
	],
] + $base);

Assert::same(['prvni', 'druhy'], \array_keys($reversed->in));
Assert::same(['result' => 'res', 'stderr' => 'err'], $reversed->out);

// --- prázdné řádky vypadnou ---

$sPrazdnymi = StepMapper::toStep([
	'in' => [0 => ['key' => '', 'value' => 'nikam'], 1 => ['key' => 'a', 'value' => 'x']],
	'out' => [0 => ['channel' => 'result', 'value' => '']],
] + $base);

Assert::same(['a'], \array_keys($sPrazdnymi->in));
Assert::same([], $sPrazdnymi->out);

// --- '' znamená nevyplněno ---

Assert::null(StepMapper::toStep($base)->name);
Assert::null(StepMapper::toStep($base)->timeout);

// --- set ---

$set = StepMapper::toStep(['type' => 'set', 'name' => 'jméno', 'key' => 'branch', 'value' => 'f/{%id%}']);
Assert::type(SetStep::class, $set);
Assert::same('branch', $set->key);
Assert::same('f/{%id%}', $set->value->getSource());
Assert::same('jméno', $set->name);

// --- if, včetně unárního operátoru ---

$if = StepMapper::toStep(['type' => 'if', 'name' => '', 'left' => '{%a%}', 'op' => 'eq', 'right' => '{%b%}']);
Assert::type(IfStep::class, $if);
Assert::same('{%b%}', $if->condition->right?->getSource());
Assert::same([], $if->then);
Assert::same([], $if->else);

// U unárních operátorů se pravá strana zahodí, i když ve formuláři něco zůstalo.
$unarni = StepMapper::toStep(['type' => 'if', 'name' => '', 'left' => '{%a%}', 'op' => 'not_empty', 'right' => 'zbytek']);
Assert::null($unarni->condition->right);

// --- foreach ---

$foreach = StepMapper::toStep(['type' => 'foreach', 'name' => '', 'over' => '{%cards%}', 'as' => 'card']);
Assert::type(ForeachStep::class, $foreach);
Assert::same('{%cards%}', $foreach->over->getSource());
Assert::same('card', $foreach->as);
Assert::same([], $foreach->steps);

// --- neznámý typ ---

Assert::exception(
	fn() => StepMapper::toStep(['type' => 'nesmysl']),
	InvalidArgumentException::class,
);

// --- toValues dává tvar, který formulář očekává ---

$values = StepMapper::toValues(new RunStep(
	block: 'jq',
	in: ['stdin' => Template::parse('{%p%}')],
	out: ['result' => 'id'],
	timeout: 30,
	allowFailure: [0, 1],
	name: 'jméno',
));

Assert::same('run', $values['type']);
Assert::same('jq', $values['block']);
Assert::same([['key' => 'stdin', 'value' => '{%p%}']], $values['in']);
Assert::same([['channel' => 'result', 'value' => 'id']], $values['out']);
Assert::same('30', $values['timeout']);
Assert::same('list', $values['allowFailure']);
Assert::same('0, 1', $values['allowFailureCodes']);

// Nevyplněná pole vyjdou jako '' a inherit, ne jako null.
$holy = StepMapper::toValues(new RunStep(block: 'echo'));
Assert::same('', $holy['name']);
Assert::same('', $holy['timeout']);
Assert::same('inherit', $holy['allowFailure']);
```

- [ ] **Step 2: Spusť test a ověř, že padá**

Run: `cd /home/honza/Dokumenty/Projekty/donut-org/donut/gui && vendor/bin/tester tests/StepMapper.phpt -C`
Expected: FAIL — `Donut\Gui\StepMapper` neexistuje.

- [ ] **Step 3: Napiš `StepMapper`**

Vytvoř `gui/src/StepMapper.php`:

```php
<?php

declare(strict_types=1);

namespace Donut\Gui;

use Donut\Format\Condition;
use Donut\Format\ForeachStep;
use Donut\Format\IfStep;
use Donut\Format\RunStep;
use Donut\Format\SetStep;
use Donut\Format\Step;
use Donut\Template;


/**
 * Hodnoty formuláře ↔ Step. Čistá konverze, nezná Nette ani HTTP.
 *
 * Vnořené kroky (then, else, foreach.steps) se nepřenášejí — stránka kroku
 * větve needituje, ty se plní z přehledu. toStep() proto u if a foreach
 * vrací krok s prázdnými větvemi a volající si je doplní sám.
 *
 * Indexy v in a out můžou mít díry — JS řádky nikdy nepřečísluje. Srovnání
 * je tady, stejně jako v BlockMapperu.
 */
final class StepMapper
{
	/**
	 * @param  array<string, mixed> $values
	 * @throws \InvalidArgumentException na neznámý typ kroku
	 */
	public static function toStep(array $values): Step
	{
		$name = self::orNull($values['name'] ?? '');

		return match ((string) ($values['type'] ?? '')) {
			'run' => new RunStep(
				block: self::text($values['block'] ?? ''),
				in: self::toIn($values['in'] ?? []),
				out: self::toOut($values['out'] ?? []),
				timeout: ($t = self::text($values['timeout'] ?? '')) === '' ? null : (int) $t,
				allowFailure: self::toAllowFailure($values),
				name: $name,
			),

			'set' => new SetStep(
				key: self::text($values['key'] ?? ''),
				value: Template::parse(self::text($values['value'] ?? '')),
				name: $name,
			),

			'if' => new IfStep(
				condition: self::toCondition($values),
				name: $name,
			),

			'foreach' => new ForeachStep(
				over: Template::parse(self::text($values['over'] ?? '')),
				as: self::text($values['as'] ?? ''),
				name: $name,
			),

			default => throw new \InvalidArgumentException(
				'Neznámý typ kroku "' . (string) ($values['type'] ?? '') . '".'
			),
		};
	}


	/**
	 * @return array<string, mixed>
	 */
	public static function toValues(Step $step): array
	{
		if ($step instanceof RunStep) {
			$in = [];

			foreach ($step->in as $key => $template) {
				$in[] = ['key' => $key, 'value' => $template->getSource()];
			}

			$out = [];

			foreach ($step->out as $channel => $value) {
				$out[] = ['channel' => $channel, 'value' => $value];
			}

			return [
				'type' => 'run',
				'name' => $step->name ?? '',
				'block' => $step->block,
				'in' => $in,
				'out' => $out,
				'timeout' => $step->timeout === null ? '' : (string) $step->timeout,
				'allowFailure' => match (true) {
					$step->allowFailure === null => 'inherit',
					$step->allowFailure === false => 'none',
					$step->allowFailure === true => 'any',
					default => 'list',
				},
				'allowFailureCodes' => \is_array($step->allowFailure)
					? \implode(', ', $step->allowFailure)
					: '',
			];
		}

		if ($step instanceof SetStep) {
			return [
				'type' => 'set',
				'name' => $step->name ?? '',
				'key' => $step->key,
				'value' => $step->value->getSource(),
			];
		}

		if ($step instanceof IfStep) {
			return [
				'type' => 'if',
				'name' => $step->name ?? '',
				'left' => $step->condition->left->getSource(),
				'op' => $step->condition->op,
				'right' => $step->condition->right?->getSource() ?? '',
			];
		}

		if ($step instanceof ForeachStep) {
			return [
				'type' => 'foreach',
				'name' => $step->name ?? '',
				'over' => $step->over->getSource(),
				'as' => $step->as,
			];
		}

		// Nový typ kroku se nesmí tiše přeskočit — formulář by se otevřel
		// prázdný a uložením by se krok přepsal na něco jiného.
		throw new \InvalidArgumentException('Neznámý typ kroku ' . $step::class . '.');
	}


	/**
	 * @param  array<string, mixed> $values
	 */
	private static function toCondition(array $values): Condition
	{
		$op = self::text($values['op'] ?? '');
		$right = self::orNull($values['right'] ?? '');

		return new Condition(
			left: Template::parse(self::text($values['left'] ?? '')),
			op: $op,
			// Unární operátor pravou stranu ignoruje; kdyby ve formuláři
			// zbyla, zapsala by se do souboru a mátla by při čtení.
			right: \in_array($op, Condition::UnaryOperators, true) || $right === null
				? null
				: Template::parse($right),
		);
	}


	/**
	 * @param  mixed $raw
	 * @return array<string, Template>
	 */
	private static function toIn(mixed $raw): array
	{
		$in = [];

		foreach (self::rows($raw) as $row) {
			$key = self::text($row['key'] ?? '');

			if ($key !== '') {
				$in[$key] = Template::parse(self::text($row['value'] ?? ''));
			}
		}

		return $in;
	}


	/**
	 * @param  mixed $raw
	 * @return array<string, string>
	 */
	private static function toOut(mixed $raw): array
	{
		$out = [];

		foreach (self::rows($raw) as $row) {
			$channel = self::text($row['channel'] ?? '');
			$value = self::text($row['value'] ?? '');

			// Kanál bez klíče nikam nezapisuje — je to nedopsaný řádek.
			if ($channel !== '' && $value !== '') {
				$out[$channel] = $value;
			}
		}

		return $out;
	}


	/**
	 * Řádky seřazené podle indexu. Pořadí klíčů z POSTu není zaručené
	 * a u in i out na pořadí záleží.
	 *
	 * @param  mixed $raw
	 * @return list<array<string, mixed>>
	 */
	private static function rows(mixed $raw): array
	{
		if (!\is_array($raw)) {
			return [];
		}

		\ksort($raw);

		$rows = [];

		foreach ($raw as $row) {
			if (\is_array($row)) {
				$rows[] = $row;
			}
		}

		return $rows;
	}


	/**
	 * @param  array<string, mixed> $values
	 * @return bool|array<int, int>|null
	 */
	private static function toAllowFailure(array $values): bool|array|null
	{
		$mode = self::text($values['allowFailure'] ?? 'inherit');

		if ($mode === 'none') {
			return false;
		}

		if ($mode === 'any') {
			return true;
		}

		if ($mode !== 'list') {
			return null;
		}

		$codes = [];

		foreach (\explode(',', self::text($values['allowFailureCodes'] ?? '')) as $code) {
			$code = \trim($code);

			if (\ctype_digit($code)) {
				$codes[] = (int) $code;
			}
		}

		// Prázdný výčet by parser odmítl — je to totéž jako „nenastaveno".
		return $codes === [] ? null : $codes;
	}


	private static function text(mixed $value): string
	{
		return \is_scalar($value) ? \trim((string) $value) : '';
	}


	private static function orNull(mixed $value): ?string
	{
		$value = self::text($value);

		return $value === '' ? null : $value;
	}
}
```

- [ ] **Step 4: Spusť test a ověř, že prochází**

Run: `cd /home/honza/Dokumenty/Projekty/donut-org/donut/gui && vendor/bin/tester tests/StepMapper.phpt -C`
Expected: PASS.

Kdyby round-trip spadl na některém z 96 kroků, **neupravuj test, aby prošel** —
vypiš si rozdíl obou `serialize()` řetězců a najdi, které pole mapper nepřenáší.

- [ ] **Step 5: Ověř mutací**

Zaveď postupně tyhle čtyři chyby a po každé spusť test:

1. v `toAllowFailure()` vrať `false` místo `null`, když mód není znám
2. v `rows()` vynech `ksort()`
3. v `toCondition()` zruš kontrolu na unární operátor
4. v `toValues()` u `RunStep` vynech `timeout`

Expected: každá shodí `gui/tests/StepMapper.phpt`.

Po každé mutaci ji vrať a nakonec ověř `git status --porcelain gui/src/`.
**Co některá mutace projde, napiš do reportu.**

- [ ] **Step 6: Spusť sadu GUI a PHPStan**

Run: `cd /home/honza/Dokumenty/Projekty/donut-org/donut/gui && vendor/bin/tester tests -C && vendor/bin/phpstan analyse`
Expected: 19 testů (bylo 18), PHPStan bez chyb.

- [ ] **Step 7: Commit**

```bash
cd /home/honza/Dokumenty/Projekty/donut-org/donut
git add gui/src/StepMapper.php gui/tests/StepMapper.phpt
git commit -m "GUI: StepMapper — hodnoty formuláře na krok a zpátky"
```

---

### Task 4: `WorkflowStore`

Jediné místo, které ví, kde workflow bydlí.

**Files:**
- Create: `gui/src/WorkflowStore.php`, `gui/tests/WorkflowStore.phpt`

**Interfaces:**
- Produces:
  - `WorkflowStore::__construct(string $directory)` — hodí `ParseException` na neexistující adresář
  - `::path(string $name): string`
  - `::save(Workflow $workflow): void`

Menší než `BlockStore` schválně: `delete()` a zakládání patří do druhého
projektu, čtení už umí `WorkflowRepository`, který prezentér používá.
Vzniká proto, že skládat cestu `<projekt>/workflows/<jméno>.json` má jedno
místo — `WorkflowWriter` ji vědomě neodvozuje, jen ověřuje.

- [ ] **Step 1: Napiš padající test**

Vytvoř `gui/tests/WorkflowStore.phpt`:

```php
<?php

declare(strict_types=1);

use Donut\Format\SetStep;
use Donut\Format\Workflow;
use Donut\Gui\WorkflowStore;
use Donut\Parser\ParseException;
use Donut\Parser\WorkflowParser;
use Donut\Template;
use Nette\Utils\FileSystem;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

$dir = TEMP_DIR . '/workflows';
FileSystem::createDir($dir);

$store = new WorkflowStore($dir);

// Cesta se skládá na jednom místě, a je to tohle.
Assert::same($dir . '/card-dev.json', $store->path('card-dev'));

// Uložení založí soubor, který jde hned přečíst zpátky.
$store->save(new Workflow(
	name: 'w',
	steps: [new SetStep(key: 'a', value: Template::parse('1'))],
	description: 'Popis',
));

Assert::true(\is_file($dir . '/w.json'));

$loaded = (new WorkflowParser)->parseFile($dir . '/w.json');
Assert::same('w', $loaded->name);
Assert::same('Popis', $loaded->description);
Assert::count(1, $loaded->steps);

// Uložení podruhé přepíše.
$store->save(new Workflow(name: 'w'));
Assert::same([], (new WorkflowParser)->parseFile($dir . '/w.json')->steps);

// Chybějící adresář je jiná situace než prázdný.
Assert::exception(fn() => new WorkflowStore($dir . '/neni'), ParseException::class);

FileSystem::delete(TEMP_DIR);
```

- [ ] **Step 2: Spusť test a ověř, že padá**

Run: `cd /home/honza/Dokumenty/Projekty/donut-org/donut/gui && vendor/bin/tester tests/WorkflowStore.phpt -C`
Expected: FAIL — `Donut\Gui\WorkflowStore` neexistuje.

- [ ] **Step 3: Napiš `WorkflowStore`**

Vytvoř `gui/src/WorkflowStore.php`. Vzorem je `gui/src/BlockStore.php` —
přečti si ho, zejména jak obaluje `WriteException` a `IOException`.

```php
<?php

declare(strict_types=1);

namespace Donut\Gui;

use Donut\Format\Workflow;
use Donut\Parser\ParseException;
use Donut\Writer\WorkflowWriter;


/**
 * Zápis workflow na disk.
 *
 * Jediné místo, které ví, že workflow jménem "card-dev" bydlí
 * v <adresář>/card-dev.json. Zapisovač v donutu cestu vědomě neodvozuje ze
 * jména, jen ověřuje, že spolu sedí — složit ji musí někdo, a je to tohle.
 *
 * Čtení tady není: umí ho WorkflowRepository, který prezentér už používá.
 * Zakládání a mazání patří do druhého projektu editace workflow.
 */
final class WorkflowStore
{
	private readonly WorkflowWriter $writer;


	/**
	 * @throws ParseException když adresář neexistuje
	 */
	public function __construct(
		private readonly string $directory,
	) {
		if (!\is_dir($directory)) {
			throw new ParseException("Adresář s workflow '{$directory}' neexistuje.");
		}

		$this->writer = new WorkflowWriter;
	}


	public function path(string $name): string
	{
		return $this->directory . '/' . $name . '.json';
	}


	/**
	 * @throws \Donut\Writer\WriteException když soubor nejde zapsat
	 */
	public function save(Workflow $workflow): void
	{
		$this->writer->writeFile($workflow, $this->path($workflow->name));
	}
}
```

- [ ] **Step 4: Spusť test a ověř, že prochází**

Run: `cd /home/honza/Dokumenty/Projekty/donut-org/donut/gui && vendor/bin/tester tests/WorkflowStore.phpt -C`
Expected: PASS.

- [ ] **Step 5: Ověř mutací**

Zaveď postupně tyhle dvě chyby a po každé spusť test:

1. v `path()` vrať `$name . '.json'` bez adresáře
2. v konstruktoru vynech kontrolu `is_dir`

Expected: obě shodí `gui/tests/WorkflowStore.phpt`.

Po každé mutaci ji vrať. **Co některá mutace projde, napiš do reportu.**

- [ ] **Step 6: Spusť sadu GUI a PHPStan**

Run: `cd /home/honza/Dokumenty/Projekty/donut-org/donut/gui && vendor/bin/tester tests -C && vendor/bin/phpstan analyse`
Expected: 20 testů (bylo 19), PHPStan bez chyb.

- [ ] **Step 7: Commit**

```bash
cd /home/honza/Dokumenty/Projekty/donut-org/donut
git add gui/src/WorkflowStore.php gui/tests/WorkflowStore.phpt
git commit -m "GUI: WorkflowStore — zápis workflow na disk"
```

---

### Task 5: Ovládání v přehledu — přesun a mazání

Teprve tady je něco vidět.

**Files:**
- Modify: `gui/src/Presentation/Workflow/steps.latte`, `gui/src/Presentation/Workflow/detail.latte`, `gui/src/Presentation/Workflow/WorkflowPresenter.php`
- Create: `gui/tests/inc/workflowPresenter.php`, `gui/tests/WorkflowPresenter.controls.phpt`

**Interfaces:**
- Consumes: `StepPath::parse()` (Task 1), `StepTree::moveUp/moveDown/remove/get` (Task 2), `WorkflowStore::save()` (Task 4).
- Produces: signály `moveUp!`, `moveDown!`, `deleteStep!` na `Workflow:detail`, každý bere cestu z POSTu. Testovací továrna `runWorkflowPresenterIn($dir, $params, $post = [])`.

**Dvě věci, které musí být přesně takhle:**

1. **Ovládání jsou ručně psané `<form method=post>`, ne komponenty Nette Forms.**
   Komponenta formuláře vykreslená uvnitř `n:foreach` znamená jednu komponentu
   mnohokrát, což Nette neumí — narazilo se na to u mazání kamene. Tady jsou
   čtyři prvky na krok a až 37 kroků.
2. **`steps.latte` dnes vykresluje větev `then`/`else` jen když není prázdná**
   (`{if $step->then}`). Do prázdného `if` by tedy nešlo přidat první krok.
   Ta podmínka musí zmizet — větev se vykreslí vždycky, i prázdná.

- [ ] **Step 1: Vytvoř testovací továrnu**

Vytvoř `gui/tests/inc/workflowPresenter.php`. Je to obdoba
`gui/tests/inc/blockPresenter.php` — **přečti si ji a drž se jí**; liší se jen
třídou prezenteru, routerem a tím, že nepotřebuje `FormsExtension` (přehled
žádný formulář Nette nemá; stránka kroku v Tasku 6 ho mít bude, takže
extension registruj taky).

Musí obsahovat:

- `createWorkflowPresenter(array $post = []): WorkflowPresenter` — `injectPrimary()`
  s `HttpRequest`, kde `method` je `POST`, když `$post` není prázdné,
  a s hlavičkou `sec-fetch-site: same-origin` (Nette Forms dělá kontrolu
  Fetch-Metadata; bez ní by se formulář v Tasku 6 neodeslal),
  `autoCanonicalize = false`
- `runWorkflowPresenterIn(string $dir, array $params, array $post = []): array`
  — `chdir()` do fixtury, `$presenter->run(new Request('Workflow', …))`,
  vrací `[$response, $html]`, kde `$html` je `''` u redirectu

- [ ] **Step 2: Napiš padající test**

Vytvoř `gui/tests/WorkflowPresenter.controls.phpt`:

```php
<?php

declare(strict_types=1);

use Donut\Parser\WorkflowParser;
use Nette\Application\Responses\RedirectResponse;
use Nette\Utils\FileSystem;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/inc/workflowPresenter.php';

$project = TEMP_DIR . '/controls';
FileSystem::createDir($project . '/blocks');
FileSystem::createDir($project . '/workflows');

FileSystem::write($project . '/blocks/echo.json', json_encode([
	'name' => 'echo', 'command' => 'echo', 'args' => [],
]));

$write = fn(array $steps) => FileSystem::write(
	$project . '/workflows/w.json',
	json_encode(['name' => 'w', 'steps' => $steps]),
);

$steps = fn(): array => (new WorkflowParser)->parseFile($project . '/workflows/w.json')->steps;

// prázdný if — do jeho větví se dnes nedá nic přidat, protože se
// nevykreslují vůbec
$write([
	['type' => 'set', 'key' => 'a', 'value' => '1'],
	['type' => 'if', 'condition' => ['left' => '{%x%}', 'op' => 'not_empty'], 'then' => []],
	['type' => 'set', 'key' => 'b', 'value' => '2'],
]);

// --- přehled nabízí ovládání ---

[, $html] = runWorkflowPresenterIn($project, ['action' => 'detail', 'name' => 'w']);

Assert::contains('w.json:steps[0]', $html);

// Mazání jde přes POST, ne přes odkaz — GET, který mění soubor, si najde
// přednačítač v prohlížeči. Tvrdíme to na tvaru značkování, ne na tom, že
// v HTML nějaký řetězec chybí: prázdná stránka by takovou aserci splnila taky.
Assert::match('~<form[^>]+method=post[^>]*>\s*<input[^>]+name=at[^>]+value="w\.json:steps\[0]"~', $html);
Assert::notContains('<a href="?do=deleteStep', $html);

// U prvního kroku není šipka nahoru, u posledního dolů. Tři kroky → dvakrát
// každá.
Assert::same(2, substr_count($html, 'do=moveUp'));
Assert::same(2, substr_count($html, 'do=moveDown'));

// Prázdná větev then se vykreslí i tak — jinak by do ní v Tasku 6 nešlo
// přidat „+ krok". Cesta k ní se v HTML nikde neobjeví (prázdný seznam nemá
// žádné ovládání), takže se tvrdí na popisku větve.
Assert::contains('then:', $html);
Assert::contains('else:', $html);

// --- přesun dolů ---

[$response] = runWorkflowPresenterIn(
	$project,
	['action' => 'detail', 'name' => 'w', 'do' => 'moveDown'],
	['at' => 'w.json:steps[0]'],
);

Assert::type(RedirectResponse::class, $response);
Assert::same('if', $steps()[0] instanceof Donut\Format\IfStep ? 'if' : 'jiný');
Assert::same('a', $steps()[1]->key);

// --- přesun nahoru zpátky ---

runWorkflowPresenterIn(
	$project,
	['action' => 'detail', 'name' => 'w', 'do' => 'moveUp'],
	['at' => 'w.json:steps[1]'],
);

Assert::same('a', $steps()[0]->key);

// --- mazání ---

runWorkflowPresenterIn(
	$project,
	['action' => 'detail', 'name' => 'w', 'do' => 'deleteStep'],
	['at' => 'w.json:steps[0]'],
);

Assert::count(2, $steps());
Assert::type(Donut\Format\IfStep::class, $steps()[0]);

// --- neplatná cesta nespadne na HTTP 500 ---

[, $html] = runWorkflowPresenterIn(
	$project,
	['action' => 'detail', 'name' => 'w', 'do' => 'deleteStep'],
	['at' => 'w.json:steps[99]'],
);

Assert::count(2, $steps());

[, $html] = runWorkflowPresenterIn(
	$project,
	['action' => 'detail', 'name' => 'w', 'do' => 'deleteStep'],
	['at' => 'nesmysl'],
);

Assert::count(2, $steps());

// --- neplatné workflow se uloží i tak: validace neblokuje ---
//
// Krok run odkazuje na kámen, který neexistuje. Přesun ho nesmí odmítnout.

$write([
	['type' => 'run', 'block' => 'neni'],
	['type' => 'set', 'key' => 'a', 'value' => '1'],
]);

[$response] = runWorkflowPresenterIn(
	$project,
	['action' => 'detail', 'name' => 'w', 'do' => 'moveDown'],
	['at' => 'w.json:steps[0]'],
);

Assert::type(RedirectResponse::class, $response);
Assert::same('a', $steps()[0]->key);

FileSystem::delete(TEMP_DIR);
```

- [ ] **Step 3: Spusť test a ověř, že padá**

Run: `cd /home/honza/Dokumenty/Projekty/donut-org/donut/gui && vendor/bin/tester tests/WorkflowPresenter.controls.phpt -C`
Expected: FAIL — signály neexistují.

- [ ] **Step 4: Přidej signály do `WorkflowPresenter`**

Doplň `use` na `Donut\Gui\StepPath`, `Donut\Gui\StepTree`,
`Donut\Gui\WorkflowStore`, `Donut\Writer\WriteException`, `Nette\IOException`,
a přidej:

```php
	public function handleMoveUp(): void
	{
		$this->applyToStep(fn($workflow, $at) => StepTree::moveUp($workflow, $at));
	}


	public function handleMoveDown(): void
	{
		$this->applyToStep(fn($workflow, $at) => StepTree::moveDown($workflow, $at));
	}


	public function handleDeleteStep(): void
	{
		$this->applyToStep(fn($workflow, $at) => StepTree::remove($workflow, $at));
	}


	/**
	 * Společný obal pro všechny tři signály: vzít cestu z POSTu, načíst
	 * workflow, provést operaci, uložit, vrátit se na přehled.
	 *
	 * Validace se **nespouští** — u workflow neblokuje, protože mezistavy
	 * přerovnávání jsou skoro vždycky neplatné. Problémy se ukážou
	 * v přehledu, kam se vzápětí vracíme.
	 *
	 * Při chybě se **nepřesměrovává**: redirect() hodí AbortException a chyba
	 * by se nikam nedostala. Flash zprávy k dispozici nejsou (žádná session),
	 * takže se nechá doběhnout renderDetail(), který $stepError vykreslí.
	 *
	 * @param callable(Workflow, StepPath): Workflow $operation
	 */
	private function applyToStep(callable $operation): void
	{
		$name = (string) $this->getParameter('name');
		$raw = $this->getHttpRequest()->getPost('at');
		$dir = WorkflowRepository::projectDir() . '/workflows';

		try {
			$at = StepPath::parse(\is_string($raw) ? $raw : '');

			// Cesta nese jméno workflow; kdyby nesouhlasilo s adresou,
			// operace by sáhla do cizího souboru.
			if ($at->workflowName() !== $name) {
				throw new \InvalidArgumentException("Cesta \"{$at}\" nepatří workflow \"{$name}\".");
			}

			$workflow = (new WorkflowRepository($dir))->get($name);
			(new WorkflowStore($dir))->save($operation($workflow, $at));

		} catch (\InvalidArgumentException | \OutOfRangeException | ParseException | WriteException | IOException $e) {
			$this->stepError = $e->getMessage();

			return;
		}

		$this->redirect('detail', ['name' => $name]);
	}
```

K tomu vlastnost `private ?string $stepError = null;`.

`renderDetail()` doplň na konci o `$template->stepError = $this->stepError;`,
`WorkflowDetailTemplate` o `public ?string $stepError = null;` a `detail.latte`
hned za `<h1>` o:

```latte
	<p n:if="$stepError" class=error>{$stepError}</p>
```

- [ ] **Step 5: Doplň ovládání do `steps.latte`**

Blok `{define steps, …}` dostane parametr navíc — jméno workflow pro odkazy:

```latte
{define steps, array $steps, Donut\Gui\StepPath $path, Donut\Gui\ProblemMap $problems, Donut\Gui\KeyMap $keys, ?string $selected, string $name}
```

a všechna tři místa `{include steps, …}` (jedno v `detail.latte`, dvě v `steps.latte`)
doplň o `name: $name`.

Hned za `{var $at = $path->index($i)}` spočítej velikost podstromu — kolik
kroků zmizí spolu s tímhle. Stačí přímí potomci; potvrzení je orientační,
rekurze není potřeba:

```latte
			{var $subtree = $step instanceof Donut\Format\IfStep
				? count($step->then) + count($step->else)
				: ($step instanceof Donut\Format\ForeachStep ? count($step->steps) : 0)}
```

Do řádku kroku, hned za `<div n:class="…">`, přidej ovládání:

```latte
				<span class=controls>
					<a n:href="Workflow:step, name: $name, at: (string) $at">upravit</a>

					<form n:if="$i > 0" method=post action="{link moveUp!}" style="display:inline">
						<input type=hidden name=at value="{$at}">
						<button type=submit>↑</button>
					</form>

					<form n:if="$i < count($steps) - 1" method=post action="{link moveDown!}" style="display:inline">
						<input type=hidden name=at value="{$at}">
						<button type=submit>↓</button>
					</form>

					<form method=post action="{link deleteStep!}" style="display:inline">
						<input type=hidden name=at value="{$at}">
						<button type=submit n:attr="onclick => $subtree
							? 'return confirm(\'Smazat i ' . $subtree . ' vnořených kroků?\')'
							: null">×</button>
					</form>
				</span>
```

**Větve se vykreslují vždycky.** Nahraď `{if $step->then}` … `{/if}` za
bezpodmínečné vykreslení, aby prázdná větev měla kam přidat krok:

```latte
			{if $step instanceof Donut\Format\IfStep}
				then:
				{include steps, steps: $step->then, path: $at->child('then'), problems: $problems, keys: $keys, selected: $selected, name: $name}
				else:
				{include steps, steps: $step->else, path: $at->child('else'), problems: $problems, keys: $keys, selected: $selected, name: $name}
			{elseif $step instanceof Donut\Format\ForeachStep}
				{include steps, steps: $step->steps, path: $at->child('steps'), problems: $problems, keys: $keys, selected: $selected, name: $name}
			{/if}
```

- [ ] **Step 6: Spusť test a ověř, že prochází**

Run: `cd /home/honza/Dokumenty/Projekty/donut-org/donut/gui && vendor/bin/tester tests/WorkflowPresenter.controls.phpt tests/Latte.TemplatesCompile.phpt -C`
Expected: PASS obojí.

Ostatní testy prezenteru workflow (`WorkflowPresenter.detailRender.phpt`)
musí projít **beze změny** — kdybys je musel upravit, něco se rozešlo.
Jediná povolená úprava je doplnění parametru `name:` do `{include steps}`
v `detail.latte`.

- [ ] **Step 7: Vyzkoušej to v prohlížeči**

```bash
cd /home/honza/Dokumenty/Projekty/donut-org/donut/docs/workflows/donut
php -S 127.0.0.1:8000 -t . /home/honza/Dokumenty/Projekty/donut-org/donut/gui/www/index.php
```

Na `http://127.0.0.1:8000/?presenter=Workflow&action=detail&name=repo-check`:

1. Přesuň krok nahoru a dolů; ověř, že se pořadí v `repo-check.json` mění
   podle toho, co vidíš.
2. Zkus šipku u prvního a posledního kroku — nesmí tam být.
3. Smaž krok `if` s neprázdnou větví — musí se zeptat a smazat celý podstrom.

**Po zkoušce vrať `docs/workflows/donut/workflows/repo-check.json` do původního
stavu** (`git checkout -- docs/workflows/`) a ověř, že
`git status --porcelain docs/workflows/` je prázdný. Zapiš do reportu, co jsi
viděl.

- [ ] **Step 8: Spusť sadu GUI a PHPStan**

Run: `cd /home/honza/Dokumenty/Projekty/donut-org/donut/gui && vendor/bin/tester tests -C && vendor/bin/phpstan analyse`
Expected: 21 testů (bylo 20), PHPStan bez chyb.

- [ ] **Step 9: Commit**

```bash
cd /home/honza/Dokumenty/Projekty/donut-org/donut
git add gui/src/Presentation/Workflow/WorkflowPresenter.php \
	gui/src/Presentation/Workflow/WorkflowDetailTemplate.php \
	gui/src/Presentation/Workflow/steps.latte \
	gui/src/Presentation/Workflow/detail.latte \
	gui/tests/inc/workflowPresenter.php \
	gui/tests/WorkflowPresenter.controls.phpt
git commit -m "GUI: přesun a mazání kroků v přehledu workflow"
```

---

### Task 6: Stránka kroku — úprava a přidání

**Files:**
- Create: `gui/src/Presentation/Workflow/step.latte`, `gui/src/Presentation/Workflow/WorkflowStepTemplate.php`, `gui/src/Presentation/rows.latte`
- Modify: `gui/src/Presentation/Workflow/WorkflowPresenter.php`, `gui/src/Presentation/Workflow/steps.latte`, `gui/src/Presentation/Block/edit.latte`
- Create: `gui/tests/WorkflowPresenter.step.phpt`

**Interfaces:**
- Consumes: `StepPath::parse()`, `StepTree::get/replace/insert`, `StepMapper::toStep/toValues`, `WorkflowStore::save()`, továrna `runWorkflowPresenterIn()` z Tasku 5.
- Produces: akce `Workflow:step` s parametry `name`, `at` a volitelným `type`.
  `type` vyplněný znamená **nový krok** vkládaný na pozici `at`; prázdný
  znamená úpravu existujícího kroku na `at`.

- [ ] **Step 1: Vytáhni sdílený JS do `rows.latte`**

Ten JS bude potřetí (`args` u kamene, teď `in` a `out`). Kopírovat ho je
duplikace; externí `.js` se nedoručí. Řešení je Latte, stejně jako
u `steps.latte`.

Vytvoř `gui/src/Presentation/rows.latte`:

```latte
{*
	Sdílený JS pro opakující se řádky formuláře. Používá ho editace kamene
	(args, inputs) i stránka kroku (in, out).

	Značkování, které očekává:
	  <div id="args"> … <div class="row"> … <input name="args[0][0]"> … </div> </div>
	  <button type=button data-add="args">+ řádek</button>
	  <button type=button class="del-row">×</button>   (uvnitř .row)

	n:syntax="off" je povinné — bez něj Latte spadne na objektovém literálu
	(Unexpected tag {name}). Hlídá to tests/Latte.TemplatesCompile.phpt.
*}
{define rows}
<script n:syntax="off">
// Řádky se nikdy nepřečíslovávají: nový dostane index o jedna vyšší, než je
// současné maximum, a smazání nechá v číslování díru. Server pole srovná
// přes ksort()/array_values(). Přečíslovávání by mohlo tiše prohodit dvě
// hodnoty; takhle ta chyba nemá kde vzniknout.
const maxIndex = (nodes, re) => {
	let max = -1;
	nodes.forEach(n => {
		const m = re.exec(n.getAttribute('name') || '');
		if (m) max = Math.max(max, parseInt(m[1], 10));
	});
	return max;
};

const cloneRow = (row, rename) => {
	const copy = row.cloneNode(true);
	copy.querySelectorAll('input, select').forEach(i => {
		i.setAttribute('name', rename(i.getAttribute('name')));
		// cloneNode kopíruje i id — bez odebrání by měla dvě různá pole
		// stejné id (Nette ho odvozuje z původního jména).
		i.removeAttribute('id');
		if (i.type === 'checkbox') i.checked = false;
		else if (i.tagName === 'SELECT') i.selectedIndex = 0;
		else i.value = '';
	});
	return copy;
};

document.addEventListener('click', e => {
	const add = e.target.getAttribute && e.target.getAttribute('data-add');

	if (add) {
		const box = document.getElementById(add);
		const rows = box.querySelectorAll('.row');
		const prefix = new RegExp('^' + add + '\\[(\\d+)]');
		const next = maxIndex(box.querySelectorAll('input, select'), prefix) + 1;
		// Přejmenuje se jen indexová část; zbytek jména zůstane, aby řádek
		// s víc poli nedostal všechna pole pod jedním jménem.
		box.appendChild(cloneRow(
			rows[rows.length - 1],
			n => n.replace(new RegExp('^' + add + '\\[\\d+]'), add + '[' + next + ']')
		));
	}

	if (e.target.classList && e.target.classList.contains('del-row')) {
		const row = e.target.closest('.row');
		const box = row.parentElement;
		// Poslední řádek zůstane, jinak by nebylo co klonovat.
		if (box.querySelectorAll('.row').length > 1) row.remove();
	}
});
</script>
{/define}
```

Pak v `gui/src/Presentation/Block/edit.latte`:

1. smaž celý dnešní `<script n:syntax="off">…</script>`,
2. na začátek souboru přidej `{import '../rows.latte'}`,
3. na místo smazaného skriptu dej `{include rows}`,
4. značkování srovnej na to, co `rows.latte` očekává: kontejnery `args`
   a `inputs` musí mít `id`, jejich řádky třídu `row` (dnes `arg-group`
   a `input-row`), tlačítka pro přidání `data-add="args"` / `data-add="inputs"`
   místo `id="add-group"` / `id="add-input"`, a mazací tlačítka třídu
   `del-row`.

**Jedna věc se tím ztratí a je to v pořádku:** dnešní `add-arg` uměl přidat
další argument *uvnitř* skupiny. Sdílený mechanismus zvládá jen jednu úroveň.
Skupiny argumentů kamene jsou dvouúrovňové (`args[g][a]`), takže **`args`
zůstane na vlastním kódu** — přesuň do `rows.latte` jen `maxIndex` a `cloneRow`
a obecný posluchač, a v `edit.latte` nech vlastní posluchač pro `add-arg`,
který ty dvě funkce použije. Kontejner `inputs` přejde na sdílený mechanismus.

**Testy editace kamene musí projít beze změny** — hlavně
`BlockPresenter.edit.phpt`, který díru v číslování ověřuje end to end. Kdybys
je musel upravit, přechod něco rozbil; zastav a nahlas to.

- [ ] **Step 2: Napiš padající test**

Vytvoř `gui/tests/WorkflowPresenter.step.phpt`:

```php
<?php

declare(strict_types=1);

use Donut\Format\ForeachStep;
use Donut\Format\IfStep;
use Donut\Format\RunStep;
use Donut\Format\SetStep;
use Donut\Parser\WorkflowParser;
use Nette\Application\Responses\RedirectResponse;
use Nette\Utils\FileSystem;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/inc/workflowPresenter.php';

$project = TEMP_DIR . '/step';
FileSystem::createDir($project . '/blocks');
FileSystem::createDir($project . '/workflows');

FileSystem::write($project . '/blocks/jq.json', json_encode([
	'name' => 'jq', 'command' => 'jq', 'args' => [],
	'inputs' => ['filter' => ['required' => true]],
	'stdin' => ['required' => true],
]));

FileSystem::write($project . '/workflows/w.json', json_encode([
	'name' => 'w',
	'steps' => [
		['type' => 'run', 'block' => 'jq', 'in' => ['filter' => '.id'], 'out' => ['result' => 'id']],
		['type' => 'if', 'condition' => ['left' => '{%id%}', 'op' => 'not_empty'], 'then' => []],
	],
]));

$steps = fn(): array => (new WorkflowParser)->parseFile($project . '/workflows/w.json')->steps;

// --- úprava existujícího kroku: formulář je předvyplněný ---

[, $html] = runWorkflowPresenterIn($project, [
	'action' => 'step', 'name' => 'w', 'at' => 'w.json:steps[0]',
]);

Assert::contains('value="jq"', $html);
Assert::contains('.id', $html);
Assert::contains('<form', $html);

// --- uložení úpravy ---

[$response] = runWorkflowPresenterIn(
	$project,
	['action' => 'step', 'name' => 'w', 'at' => 'w.json:steps[0]', 'do' => 'stepForm-submit'],
	[
		'type' => 'run', 'name' => 'pojmenovaný', 'block' => 'jq',
		// díra v číslování schválně
		'in' => [0 => ['key' => 'filter', 'value' => '.title'], 2 => ['key' => 'stdin', 'value' => '{%x%}']],
		'out' => [0 => ['channel' => 'result', 'value' => 'title']],
		'timeout' => '', 'allowFailure' => 'inherit', 'allowFailureCodes' => '',
		'save' => 'Uložit',
	],
);

Assert::type(RedirectResponse::class, $response);

$run = $steps()[0];
Assert::type(RunStep::class, $run);
Assert::same('pojmenovaný', $run->name);
Assert::same(['filter', 'stdin'], array_keys($run->in));
Assert::same('.title', $run->in['filter']->getSource());
Assert::same(['result' => 'title'], $run->out);

// Zbytek workflow zůstal — úprava kroku nesmí sáhnout na sousedy.
Assert::count(2, $steps());
Assert::type(IfStep::class, $steps()[1]);

// --- nový krok se vloží na zadanou pozici ---

runWorkflowPresenterIn(
	$project,
	['action' => 'step', 'name' => 'w', 'at' => 'w.json:steps[1]', 'type' => 'set', 'do' => 'stepForm-submit'],
	['type' => 'set', 'name' => '', 'key' => 'branch', 'value' => 'f/{%id%}', 'save' => 'Uložit'],
);

Assert::count(3, $steps());
Assert::type(SetStep::class, $steps()[1]);
Assert::same('branch', $steps()[1]->key);
Assert::type(IfStep::class, $steps()[2]);

// --- nový krok do prázdné větve ---

runWorkflowPresenterIn(
	$project,
	['action' => 'step', 'name' => 'w', 'at' => 'w.json:steps[2].then[0]', 'type' => 'foreach', 'do' => 'stepForm-submit'],
	['type' => 'foreach', 'name' => '', 'over' => '{%list%}', 'as' => 'row', 'save' => 'Uložit'],
);

$if = $steps()[2];
Assert::type(IfStep::class, $if);
Assert::count(1, $if->then);
Assert::type(ForeachStep::class, $if->then[0]);
Assert::same('row', $if->then[0]->as);

// --- úprava if nesmí zahodit jeho větve ---

runWorkflowPresenterIn(
	$project,
	['action' => 'step', 'name' => 'w', 'at' => 'w.json:steps[2]', 'do' => 'stepForm-submit'],
	['type' => 'if', 'name' => 'přejmenovaná', 'left' => '{%id%}', 'op' => 'empty', 'right' => '', 'save' => 'Uložit'],
);

$if = $steps()[2];
Assert::same('přejmenovaná', $if->name);
Assert::same('empty', $if->condition->op);
Assert::count(1, $if->then, 'větev then se úpravou podmínky nesmí ztratit');

// --- neplatná cesta se ohlásí, nespadne ---

[, $html] = runWorkflowPresenterIn($project, [
	'action' => 'step', 'name' => 'w', 'at' => 'w.json:steps[99]',
]);

Assert::contains('neexistuje', $html);

// --- neplatné workflow se uloží i tak: validace neblokuje ---
//
// Kámen jq vyžaduje vstup filter i stdin; krok, který nevyplní ani jeden,
// je pro validátor chyba. Uložit se přesto musí.
//
// Pozn.: neplatnost se schválně nevyrábí neexistujícím jménem kamene —
// pole `block` je addSelect nad seznamem kamenů a Nette hodnotu mimo seznam
// odmítne dřív, než se k uložení vůbec dojde. Testovalo by se tím chování
// formuláře, ne to, že validace workflow neblokuje.

runWorkflowPresenterIn(
	$project,
	['action' => 'step', 'name' => 'w', 'at' => 'w.json:steps[0]', 'do' => 'stepForm-submit'],
	[
		'type' => 'run', 'name' => '', 'block' => 'jq',
		'in' => [], 'out' => [], 'timeout' => '',
		'allowFailure' => 'inherit', 'allowFailureCodes' => '', 'save' => 'Uložit',
	],
);

Assert::same([], $steps()[0]->in, 'krok bez povinných vstupů se uloží i tak');

FileSystem::delete(TEMP_DIR);
```

- [ ] **Step 3: Spusť test a ověř, že padá**

Run: `cd /home/honza/Dokumenty/Projekty/donut-org/donut/gui && vendor/bin/tester tests/WorkflowPresenter.step.phpt -C`
Expected: FAIL — akce `step` neexistuje.

- [ ] **Step 4: Přidej akci `step` do `WorkflowPresenter`**

Vzorem je `BlockPresenter::actionEdit()` + `createComponentBlockForm()` +
`blockFormSucceeded()` + `formShape()` — **přečti si je**, tohle je jejich
obdoba pro kroky.

Potřebuješ:

```php
	private ?Step $editedStep = null;

	private ?StepPath $stepAt = null;

	private string $stepType = '';


	public function actionStep(string $name, string $at, ?string $type = null): void
	{
		/** @var WorkflowStepTemplate $template */
		$template = $this->template;

		try {
			$this->stepAt = StepPath::parse($at);

			if ($this->stepAt->workflowName() !== $name) {
				throw new \InvalidArgumentException("Cesta \"{$at}\" nepatří workflow \"{$name}\".");
			}

			$repository = new WorkflowRepository(WorkflowRepository::projectDir() . '/workflows');
			$workflow = $repository->get($name);

			if ($type === null || $type === '') {
				// Úprava existujícího kroku.
				$this->editedStep = StepTree::get($workflow, $this->stepAt);
				$this->stepType = (string) StepMapper::toValues($this->editedStep)['type'];

			} else {
				// Nový krok — zatím nikde neuložený, jen typ a cílová pozice.
				$this->stepType = $type;
			}

		} catch (\InvalidArgumentException | \OutOfRangeException | ParseException $e) {
			$template->error = $e->getMessage();
		}
	}
```

```php
	public function renderStep(string $name, string $at): void
	{
		/** @var WorkflowStepTemplate $template */
		$template = $this->template;
		$template->name = $name;
		$template->at = $at;
		// Typ z adresy se nečte znovu — actionStep() ho už vyřešil, a u úpravy
		// existujícího kroku ho odvodil z kroku samotného, ne z adresy.
		$template->type = $this->stepType;
		$template->blocks = $this->blockNames();
	}


	/**
	 * Jména kamenů do rozbalovacího seznamu. Chybějící adresář kamenů není
	 * důvod stránku shodit — seznam prostě zůstane prázdný.
	 *
	 * @return array<string, string>
	 */
	private function blockNames(): array
	{
		try {
			$names = (new BlockRepository(WorkflowRepository::projectDir() . '/blocks'))->getNames();

		} catch (ParseException) {
			return [];
		}

		return \array_combine($names, $names);
	}


	protected function createComponentStepForm(): Form
	{
		$form = new Form;
		$form->addHidden('type')->setDefaultValue($this->stepType);
		$form->addText('name', 'Název kroku');

		if ($this->stepType === 'run') {
			$form->addSelect('block', 'Kámen', $this->blockNames())
				->setRequired('Vyber kámen.');

			$shape = $this->rowShape();

			$in = $form->addContainer('in');

			foreach ($shape['in'] as $i) {
				$row = $in->addContainer((string) $i);
				$row->addText('key');
				$row->addText('value');
			}

			$out = $form->addContainer('out');

			foreach ($shape['out'] as $i) {
				$row = $out->addContainer((string) $i);
				$row->addSelect('channel', null, \array_combine(RunStep::Channels, RunStep::Channels))
					->setPrompt('—');
				$row->addText('value');
			}

			$form->addText('timeout', 'Timeout (s)')
				->addCondition($form::Filled)
				->addRule($form::Integer, 'Timeout musí být celé číslo.')
				->addRule($form::Min, 'Timeout musí být kladný.', 1);

			$form->addRadioList('allowFailure', 'Povolené selhání', [
				'inherit' => 'převzít z kamene',
				'none' => 'jen exit 0',
				'any' => 'jakýkoliv exit kód',
				'list' => 'jen tyhle kódy:',
			])->setDefaultValue('inherit');

			$form->addText('allowFailureCodes')
				->addCondition($form::Filled)
				->addRule($form::Pattern, 'Kódy zadej jako čísla oddělená čárkou, třeba 0, 1.', '[0-9]+(\s*,\s*[0-9]+)*');

		} elseif ($this->stepType === 'set') {
			$form->addText('key', 'Klíč')->setRequired('Klíč je povinný.');
			$form->addText('value', 'Hodnota');

		} elseif ($this->stepType === 'if') {
			$form->addText('left', 'Vlevo');
			$form->addSelect('op', 'Operátor', \array_combine(Condition::Operators, Condition::Operators))
				->setRequired('Vyber operátor.');
			$form->addText('right', 'Vpravo');

		} elseif ($this->stepType === 'foreach') {
			$form->addText('over', 'Přes co')->setRequired('Vyplň, přes co se iteruje.');
			$form->addText('as', 'Pod jakým jménem')->setRequired('Vyplň jméno položky.');
		}

		$form->addSubmit('save', 'Uložit');
		$form->onSuccess[] = $this->stepFormSucceeded(...);

		if ($this->editedStep !== null && !$this->getRequest()->isMethod('POST')) {
			$form->setDefaults(StepMapper::toValues($this->editedStep));
		}

		return $form;
	}


	/**
	 * Kolik řádků mají kontejnery in a out.
	 *
	 * Při POSTu se odvodí z došlých dat — JS řádky nikdy nepřečísluje, takže
	 * indexy můžou mít díry a kontejnery musí vzniknout přesně pro klíče,
	 * které dorazily. Jiný signál než stepForm-submit se ignoruje, jinak by
	 * se formulář sestavil s nula řádky.
	 *
	 * Klíče se filtrují na číslice: jméno komponenty v Nette musí odpovídat
	 * [a-zA-Z0-9_]+ a nic jiného sem stejně nepatří.
	 *
	 * @return array{in: list<int>, out: list<int>}
	 */
	private function rowShape(): array
	{
		$post = $this->getParameter('do') === 'stepForm-submit'
			? $this->getHttpRequest()->getPost()
			: [];

		if (\is_array($post) && $post !== []) {
			return [
				'in' => self::rowIndexes($post['in'] ?? []),
				'out' => self::rowIndexes($post['out'] ?? []),
			];
		}

		$in = [];
		$out = [];

		if ($this->editedStep instanceof RunStep) {
			$in = \array_keys(\array_values($this->editedStep->in));
			$out = \array_keys(\array_values($this->editedStep->out));
		}

		// Jeden prázdný řádek navíc, aby měl uživatel kam psát i bez JS.
		$in[] = \count($in);
		$out[] = \count($out);

		return ['in' => $in, 'out' => $out];
	}


	/**
	 * @param  mixed $raw
	 * @return list<int>
	 */
	private static function rowIndexes(mixed $raw): array
	{
		if (!\is_array($raw)) {
			return [];
		}

		$keys = [];

		foreach (\array_keys($raw) as $key) {
			if (\ctype_digit((string) $key)) {
				$keys[] = (int) $key;
			}
		}

		\sort($keys);

		return $keys;
	}
```

Doplň `use` na `Donut\BlockRepository`, `Donut\Format\Condition`,
`Donut\Format\RunStep`, `Donut\Format\Step`, `Donut\Format\IfStep`,
`Donut\Format\ForeachStep`, `Donut\Gui\StepMapper` a `Nette\Application\UI\Form`.

Obsluha uložení:

```php
	public function stepFormSucceeded(Form $form): void
	{
		/** @var array<string, mixed> $values */
		$values = $form->getValues('array');
		$name = (string) $this->getParameter('name');

		/** @var WorkflowStepTemplate $template */
		$template = $this->template;

		try {
			$step = StepMapper::toStep($values);
			$at = $this->stepAt;

			if ($at === null) {
				throw new \InvalidArgumentException('Chybí cesta ke kroku.');
			}

			$repository = new WorkflowRepository(WorkflowRepository::projectDir() . '/workflows');
			$workflow = $repository->get($name);

			// Úprava if nebo foreach nesmí zahodit jejich větve — formulář
			// je needituje, takže se přenesou z původního kroku.
			if ($this->editedStep !== null) {
				$step = self::keepChildren($this->editedStep, $step);
				$workflow = StepTree::replace($workflow, $at, $step);

			} else {
				$workflow = StepTree::insert($workflow, $at, $step);
			}

			(new WorkflowStore(WorkflowRepository::projectDir() . '/workflows'))->save($workflow);

		} catch (\InvalidArgumentException | \OutOfRangeException | ParseException | WriteException | IOException $e) {
			$form->addError($e->getMessage());

			return;
		}

		$this->redirect('detail', ['name' => $name]);
	}


	/**
	 * Nový krok z formuláře nese prázdné větve; převezmi je z toho, který
	 * nahrazuje, aby úprava podmínky nesmazala celý podstrom.
	 */
	private static function keepChildren(Step $original, Step $updated): Step
	{
		if ($original instanceof IfStep && $updated instanceof IfStep) {
			return new IfStep($updated->condition, $original->then, $original->else, $updated->name);
		}

		if ($original instanceof ForeachStep && $updated instanceof ForeachStep) {
			return new ForeachStep($updated->over, $updated->as, $original->steps, $updated->name);
		}

		return $updated;
	}
```

**Žádná validace tady není a být nemá** — u workflow neblokuje.

- [ ] **Step 5: Napiš `WorkflowStepTemplate` a `step.latte`**

`WorkflowStepTemplate` podle vzoru `BlockEditTemplate`:

```php
	public ?string $error = null;

	public string $name = '';

	public string $at = '';

	public string $type = '';

	/** @var array<string, string> */
	public array $blocks = [];
```

`gui/src/Presentation/Workflow/step.latte`:

```latte
{import '../rows.latte'}

{block title}Krok — {$name} — Donut{/block}

{block content}
<p><a n:href="Workflow:detail, name: $name">← {$name}</a></p>

<h1>Krok {$type}</h1>
<p class=keys>{$at}</p>

<p n:if="$error" class=error>{$error}</p>

{if !$error}
	{form stepForm}
		<ul n:if="$form->getErrors()" class=error>
			<li n:foreach="$form->getErrors() as $err">{$err}</li>
		</ul>

		{input type}
		<p>{label name /} {input name}</p>

		{if $type === 'run'}
			<p>{label block /} {input block}</p>

			<h2>Vstupy kamene</h2>
			<div id=in>
				<div class=row n:foreach="$form['in']->getComponents() as $row">
					{input $row['key']} → {input $row['value']}
					<button type=button class=del-row>×</button>
				</div>
			</div>
			<button type=button data-add="in">+ vstup</button>

			<h2>Výstupy do mapy</h2>
			<div id=out>
				<div class=row n:foreach="$form['out']->getComponents() as $row">
					{input $row['channel']} → {input $row['value']}
					<button type=button class=del-row>×</button>
				</div>
			</div>
			<button type=button data-add="out">+ výstup</button>

			<h2>Ostatní</h2>
			<p>{label timeout /} {input timeout}</p>
			<p>{label allowFailure /} {input allowFailure} {input allowFailureCodes}</p>

		{elseif $type === 'set'}
			<p>{label key /} {input key}</p>
			<p>{label value /} {input value}</p>

		{elseif $type === 'if'}
			<p>{label left /} {input left} {input op} {input right}</p>
			<p class=keys>Operátory <code>empty</code> a <code>not_empty</code> pravou stranu ignorují.</p>

		{elseif $type === 'foreach'}
			<p>{label over /} {input over}</p>
			<p>{label as /} {input as}</p>
		{/if}

		<p>{input save}</p>
	{/form}

	{include rows}
{/if}
```

Blok `{if !$error}` obaluje celý formulář schválně: když se cesta nedá
rozebrat nebo krok neexistuje, není co editovat a `$form` by neměl z čeho
vzniknout.

- [ ] **Step 6: Doplň „+ krok" do `steps.latte`**

Na konec každého `<ol>` v bloku `{define steps}` přidej položku s odkazy na
čtyři typy, mířící na pozici za posledním prvkem:

```latte
	<li class=add>
		+ krok:
		{foreach ['run', 'set', 'if', 'foreach'] as $type}
			<a n:href="Workflow:step, name: $name, at: (string) $path->index(count($steps)), type: $type">{$type}</a>{sep} {/sep}
		{/foreach}
	</li>
```

Díky bezpodmínečnému vykreslení větví z Tasku 5 se tenhle odkaz objeví i
v prázdné větvi `then`, `else` a v prázdném `foreach`.

- [ ] **Step 7: Spusť test a ověř, že prochází**

Run: `cd /home/honza/Dokumenty/Projekty/donut-org/donut/gui && vendor/bin/tester tests/WorkflowPresenter.step.phpt tests/Latte.TemplatesCompile.phpt tests/BlockPresenter.edit.phpt -C`
Expected: PASS všechno.

`BlockPresenter.edit.phpt` je tam schválně: hlídá, že přechod na sdílený
`rows.latte` nerozbil editaci kamene.

- [ ] **Step 8: Vyzkoušej to v prohlížeči**

```bash
cd /tmp && rm -rf zkouska-wf && mkdir -p zkouska-wf && cd zkouska-wf
cp -r /home/honza/Dokumenty/Projekty/donut-org/donut/docs/workflows/donut/blocks .
mkdir workflows
cp /home/honza/Dokumenty/Projekty/donut-org/donut/docs/workflows/donut/workflows/repo-check.json workflows/
php -S 127.0.0.1:8000 -t . /home/honza/Dokumenty/Projekty/donut-org/donut/gui/www/index.php
```

Na `?presenter=Workflow&action=detail&name=repo-check`:

1. Přidej krok `run` do prázdné větve `else` — musí tam být „+ krok".
2. U kroku `run` přidej dva řádky `in`, prostřední smaž, ulož a zkontroluj
   pořadí v souboru.
3. Uprav `if` — změň podmínku a ověř, že jeho větev `then` zůstala.

**Kopie je v `/tmp`, referenční zátěž se nesmí změnit** — ověř
`git status --porcelain docs/workflows/`. Zapiš do reportu, co jsi viděl,
včetně obsahu uloženého souboru.

- [ ] **Step 9: Spusť obě sady a PHPStan**

Run:
```bash
cd /home/honza/Dokumenty/Projekty/donut-org/donut/gui && vendor/bin/tester tests -C && vendor/bin/phpstan analyse
cd /home/honza/Dokumenty/Projekty/donut-org/donut && vendor/bin/tester tests -C && vendor/bin/phpstan analyse
```
Expected: GUI 22 testů (bylo 21), donut 32 testů beze změny, PHPStan obojí čistý.

- [ ] **Step 10: Odškrtni v zadání**

V `docs/zadani.md` škrtni u editace workflow projekt „kroky", stejně jako je
škrtnutý serializér a editace kamene, a nech živý jen projekt „obálka".

- [ ] **Step 11: Commit**

```bash
cd /home/honza/Dokumenty/Projekty/donut-org/donut
git add gui/src/Presentation/Workflow/WorkflowPresenter.php \
	gui/src/Presentation/Workflow/WorkflowStepTemplate.php \
	gui/src/Presentation/Workflow/step.latte \
	gui/src/Presentation/Workflow/steps.latte \
	gui/src/Presentation/rows.latte \
	gui/src/Presentation/Block/edit.latte \
	gui/tests/WorkflowPresenter.step.phpt \
	docs/zadani.md
git commit -m "GUI: stránka kroku — úprava a přidání"
```
