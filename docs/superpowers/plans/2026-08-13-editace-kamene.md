# Editace kamene — implementační plán

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Zakládat, upravovat a mazat kameny v GUI.

**Architecture:** Donut dostane `BlockValidator` — přesun dvou kontrol, které
už má, jen jsou privátní. GUI dostane tři čisté jednotky (`BlockUsage`,
`BlockMapper`, `BlockStore`) a nad nimi formulář s trochou JS. Těžiště testů
je `BlockMapper`; formulář a šablona se ověří kompilací a renderem.

**Tech Stack:** PHP 8.1+ (donut) / 8.3+ (gui), nette/utils, nette/application,
nette/forms, latte, nette/tester, PHPStan level max.

**Spec:** `docs/superpowers/specs/2026-08-13-editace-kamene-design.md`

## Global Constraints

- **Dva balíčky, dvě sady.** Donut je kořen repozitáře, GUI je `gui/`. Každý
  má vlastní `composer.json`, `phpstan.neon` a `vendor/`. Po tasku, který sahá
  do donutu, musí projít `vendor/bin/tester tests -C` a `vendor/bin/phpstan
  analyse` **z kořene**; po tasku v GUI totéž **z `gui/`**.
- Stav před začátkem: donut 31 testů, GUI 10 testů, obojí PHPStan level max čistě.
- PHP: donut 8.1+, gui 8.3+. **Tabulátory** jako odsazení, `declare(strict_types=1);`
  v každém souboru, dvě prázdné řádky mezi metodami — přesně jako okolní kód.
- **Uživatelské texty a komentáře česky**, kód a identifikátory anglicky.
- **GUI nesmí sahat do `../src` ani `../vendor` relativní cestou.** Na donut
  závisí jako na balíčku, přes path repository v `gui/composer.json`.
- **`Nette\Utils\Process::runExecutable()`, nikdy `runCommand()`.** Tenhle
  plán nic nespouští, ale pravidlo platí.
- **Prázdný řetězec a „nevyplněno" jsou totéž** — sekce 6 specifikace formátu.
  Proto se `''` z formuláře mapuje na `null`, ne na `""`.
- **Inline `<script>` v Latte musí mít `n:syntax="off"`.** Bez něj Latte spadne
  na objektovém literálu (`{name: 'x'}` → *Unexpected tag {name}*). Ověřeno.
  `tests/Latte.TemplatesCompile.phpt` to chytí, kdyby se na to zapomnělo.
- **Externí `.js` soubor se nedoručí.** GUI se spouští z adresáře projektu
  s `gui/www/index.php` jako routerem, takže vestavěný server hledá statické
  soubory v projektu, ne v `gui/www`. JS proto patří inline do šablony —
  stejně jako CSS už je inline v `@layout.latte`.
- **Žádná CSRF ochrana ani session.** `Form::addProtection()` vyžaduje session,
  kterou GUI nemá a tenhle plán ji nezavádí; ze stejného důvodu se nepoužívají
  flash zprávy. Návrh GUI říká `127.0.0.1` a žádná autentizace — tohle je
  důsledek téhož rozhodnutí. **Nepřidávej `addProtection()`** ani session;
  kdyby to vypadalo potřeba, zastav a nahlas to.
- `git add` s konkrétními cestami, **nikdy** `git add -A` ani `git add .` —
  v pracovním stromu jsou čtyři nesledované položky
  (`.github/workflows/frontbot.yml`, `docs/logo.png`,
  `donut-org_donut.sublime-workspace`, `rss`), které do commitu nepatří.
- **Do `docs/workflows/` se nesmí zapisovat.** `git status --porcelain docs/workflows/`
  musí zůstat prázdný — je to referenční zátěž.

## Tvar hodnot formuláře

Kontrakt mezi `BlockMapper` (Task 3) a formulářem (Task 5). Přesně tohle
vrací `$form->getValues('array')`:

```php
[
    'name' => 'curl-get',
    'description' => '',              // '' = nevyplněno
    'command' => 'curl',
    'args' => [                       // indexy můžou mít díry
        0 => [0 => '-sS', 1 => '--fail'],
        3 => [0 => '{%url%}'],
    ],
    'inputs' => [                     // indexy můžou mít díry
        0 => ['name' => 'url', 'required' => true, 'default' => '', 'description' => 'Adresa'],
        2 => ['name' => 'curlrc', 'required' => false, 'default' => '', 'description' => ''],
    ],
    'hasStdin' => true,
    'stdinRequired' => false,
    'stdinDescription' => 'Tělo requestu',
    'timeout' => '',                  // '' = nevyplněno, jinak číslice
    'allowFailure' => 'none',         // 'none' | 'any' | 'list'
    'allowFailureCodes' => '0, 1',    // čte se jen při 'list'
]
```

## Struktura souborů

```
src/Validator/BlockValidator.php     donut: kontroly nad samotným kamenem
tests/Donut/BlockValidator.phpt

gui/src/BlockUsage.php               kdo který kámen používá
gui/src/BlockMapper.php              hodnoty formuláře ↔ Block
gui/src/BlockStore.php               jméno → cesta, uložit, smazat
gui/tests/BlockUsage.phpt
gui/tests/BlockMapper.phpt
gui/tests/BlockStore.phpt

gui/src/Presentation/Block/BlockPresenter.php   rozšíří se
gui/src/Presentation/Block/edit.latte           formulář + inline JS
gui/src/Presentation/Block/BlockEditTemplate.php
gui/tests/BlockPresenter.edit.phpt
```

---

### Task 1: `BlockValidator` v donutu

Přesun, ne přepis. Obě kontroly se přenesou doslova a `Validator` je začne
volat na novém místě. Že je to opravdu přesun, ověří fakt, že dnešní sada
donutu projde beze změny — `tests/Donut/Validator.steps.phpt`
a `tests/Donut/acceptance.negative.phpt` obě kontroly pokrývají.

**Files:**
- Create: `src/Validator/BlockValidator.php`, `tests/Donut/BlockValidator.phpt`
- Modify: `src/Validator/Validator.php`

**Interfaces:**
- Consumes: `Donut\Format\Block`, `Donut\Validator\Result`, `Donut\Validator\Problem`.
- Produces: `Donut\Validator\BlockValidator::validate(Block $block, ?string $location = null): Result`

- [ ] **Step 1: Napiš padající test**

Vytvoř `tests/Donut/BlockValidator.phpt`:

```php
<?php

declare(strict_types=1);

use Donut\Format\Block;
use Donut\Format\Input;
use Donut\Format\StdinSpec;
use Donut\Template;
use Donut\Validator\BlockValidator;
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';

$validator = new BlockValidator;

// Čistý kámen nemá co hlásit.
$cisty = new Block(
	name: 'cisty',
	command: 'curl',
	args: [[Template::parse('-sS')], [Template::parse('{%url%}')]],
	inputs: ['url' => new Input(name: 'url')],
);

Assert::same([], $validator->validate($cisty)->getProblems());

// {%STDIN%} v args je chyba — stdin se plní kanálem, ne šablonou.
$sStdin = new Block(
	name: 'sStdin',
	command: 'cat',
	args: [[Template::parse('{%STDIN%}')]],
	stdin: new StdinSpec,
);

$problemy = $validator->validate($sStdin)->getProblems();
Assert::count(1, $problemy);
Assert::same(Donut\Validator\Problem::Error, $problemy[0]->severity);
Assert::contains('{%STDIN%}', $problemy[0]->message);

// Nedeklarovaná proměnná v args je překlep, ne nevyplněná hodnota.
$neznamy = new Block(
	name: 'neznamy',
	command: 'curl',
	args: [[Template::parse('{%chybi%}')]],
);

$problemy = $validator->validate($neznamy)->getProblems();
Assert::count(1, $problemy);
Assert::contains('chybi', $problemy[0]->message);

// Výchozí location je soubor kamene.
Assert::same('neznamy.json', $problemy[0]->location);

// Předaná location přebije výchozí — takhle ji použije Validator u kroku.
Assert::same(
	'card-dev.json:steps[3]',
	$validator->validate($neznamy, 'card-dev.json:steps[3]')->getProblems()[0]->location,
);

// Každá nedeklarovaná proměnná se hlásí jednou, i když je v args víckrát.
$dvakrat = new Block(
	name: 'dvakrat',
	command: 'echo',
	args: [[Template::parse('{%chybi%}')], [Template::parse('{%chybi%}')]],
);

Assert::count(1, $validator->validate($dvakrat)->getProblems());
```

- [ ] **Step 2: Spusť test a ověř, že padá**

Run: `cd /home/honza/Dokumenty/Projekty/donut-org/donut && vendor/bin/tester tests/Donut/BlockValidator.phpt -C`
Expected: FAIL — `Donut\Validator\BlockValidator` neexistuje.

- [ ] **Step 3: Vytvoř `BlockValidator` s přesunutými kontrolami**

Vytvoř `src/Validator/BlockValidator.php`. Těla obou privátních metod
**zkopíruj doslova** z `src/Validator/Validator.php` (dnes na řádcích 187–228),
včetně komentářů:

```php
<?php

declare(strict_types=1);

namespace Donut\Validator;

use Donut\Format\Block;


/**
 * Kontroly, které se dívají jen na kámen samotný.
 *
 * Bydlí zvlášť, protože Validator drží BlockRepository kvůli dohledávání
 * kamenů podle jména — kontrola samotného kamene nepotřebuje nic než ten
 * kámen. Validator ji volá u kroku run, GUI při ukládání.
 */
final class BlockValidator
{
	/**
	 * @param  string|null $location kde se problém hlásí; výchozí je soubor
	 *                               kamene, Validator předává cestu ke kroku
	 */
	public function validate(Block $block, ?string $location = null): Result
	{
		$at = $location ?? $block->name . '.json';
		$result = new Result;

		$this->checkStdinNotInArgs($block, $at, $result);
		$this->checkArgsInputsDeclared($block, $at, $result);

		return $result;
	}


	// sem přijde checkStdinNotInArgs() doslova z Validator.php

	// sem přijde checkArgsInputsDeclared() doslova z Validator.php
}
```

- [ ] **Step 4: Nech `Validator` volat nové místo**

V `src/Validator/Validator.php`:

1. Přidej do konstruktoru vlastní instanci:

```php
	private readonly BlockValidator $blockValidator;


	public function __construct(
		private readonly BlockRepository $blocks,
	) {
		$this->blockValidator = new BlockValidator;
	}
```

2. V `checkRun()` nahraď dvojici volání:

```php
		$this->checkStdinNotInArgs($block, $at, $result);
		$this->checkArgsInputsDeclared($block, $at, $result);
```

za:

```php
		foreach ($this->blockValidator->validate($block, $at)->getProblems() as $problem) {
			$result->add($problem);
		}
```

3. Smaž obě privátní metody `checkStdinNotInArgs()` a `checkArgsInputsDeclared()`.

4. **Smaž `use Donut\Format\Block;`** — ověřeno, že po odstranění těch dvou
   metod ho nic ve `Validator.php` nepoužívá. Zkontroluj to
   (`grep -n 'Block' src/Validator/Validator.php`) a smaž jen tehdy, když to
   opravdu platí.

- [ ] **Step 5: Spusť celou sadu donutu**

Run: `cd /home/honza/Dokumenty/Projekty/donut-org/donut && vendor/bin/tester tests -C && vendor/bin/phpstan analyse`
Expected: 32 testů (bylo 31), PHPStan bez chyb.

**Že to byl přesun a ne přepis, dokazují `tests/Donut/Validator.steps.phpt`
a `tests/Donut/acceptance.negative.phpt` — obě hlídají tyhle hlášky přes
workflow a musí projít beze změny. Kdybys je musel upravit, něco se rozešlo;
zastav a nahlas to.**

- [ ] **Step 6: Ověř mutací, že nový test něco drží**

Zaveď do `src/Validator/BlockValidator.php` postupně tyhle dvě chyby a po každé
spusť `vendor/bin/tester tests/Donut/BlockValidator.phpt -C`:

1. ve `validate()` vynech volání `checkStdinNotInArgs()`
2. v `checkArgsInputsDeclared()` smaž řádek `$reported[$key] = true;`

Expected: obě shodí test (druhá na asserci „hlásí se jednou").

Po každé mutaci ji vrať a nakonec ověř `git status --porcelain src/`.
**Co některá mutace projde, napiš do reportu.**

- [ ] **Step 7: Commit**

```bash
cd /home/honza/Dokumenty/Projekty/donut-org/donut
git add src/Validator/BlockValidator.php src/Validator/Validator.php tests/Donut/BlockValidator.phpt
git commit -m "Validator: kontroly nad samotným kamenem do BlockValidatoru"
```

---

### Task 2: `BlockUsage` v GUI

Kdo který kámen používá. Slouží dvakrát: mazání to potřebuje jako kontrolu
a přehled kamenů jako informaci.

**Files:**
- Create: `gui/src/BlockUsage.php`, `gui/tests/BlockUsage.phpt`

**Interfaces:**
- Consumes: `Donut\Format\Workflow`, `RunStep`, `IfStep`, `ForeachStep`, `Step`.
- Produces: `Donut\Gui\BlockUsage::of(array $workflows): array` — bere
  `array<string, Workflow>` (jméno workflow → workflow) a vrací
  `array<string, list<string>>` (jméno kamene → seřazená jména workflow).

- [ ] **Step 1: Napiš padající test**

Vytvoř `gui/tests/BlockUsage.phpt`:

```php
<?php

declare(strict_types=1);

use Donut\Format\Condition;
use Donut\Format\ForeachStep;
use Donut\Format\IfStep;
use Donut\Format\RunStep;
use Donut\Format\SetStep;
use Donut\Format\Workflow;
use Donut\Gui\BlockUsage;
use Donut\Template;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

// Kameny schované ve všech třech úrovních vnoření: přímo v steps,
// ve větvi then, ve větvi else a uvnitř foreach.
$w1 = new Workflow(name: 'prvni', steps: [
	new RunStep(block: 'echo'),
	new IfStep(
		condition: new Condition(left: Template::parse('{%a%}'), op: 'not_empty'),
		then: [new RunStep(block: 'jq')],
		else: [new ForeachStep(
			over: Template::parse('{%seznam%}'),
			as: 'radek',
			steps: [new RunStep(block: 'curl-get')],
		)],
	),
	new SetStep(key: 'x', value: Template::parse('1')),
]);

$w2 = new Workflow(name: 'druhe', steps: [
	new RunStep(block: 'echo'),
	new RunStep(block: 'echo'),
]);

$usage = BlockUsage::of(['prvni' => $w1, 'druhe' => $w2]);

// Kámen v obou workflow je uvedený jednou za každé, ne za každý krok.
Assert::same(['druhe', 'prvni'], $usage['echo']);

// Kámen ve vnořené větvi se najde.
Assert::same(['prvni'], $usage['jq']);
Assert::same(['prvni'], $usage['curl-get']);

// Nepoužitý kámen v mapě vůbec není.
Assert::false(\array_key_exists('fail', $usage));

// Prázdný vstup dá prázdnou mapu, ne chybu.
Assert::same([], BlockUsage::of([]));

// Workflow bez jediného kroku run taky.
Assert::same([], BlockUsage::of(['x' => new Workflow(name: 'x')]));
```

- [ ] **Step 2: Spusť test a ověř, že padá**

Run: `cd /home/honza/Dokumenty/Projekty/donut-org/donut/gui && vendor/bin/tester tests/BlockUsage.phpt -C`
Expected: FAIL — `Donut\Gui\BlockUsage` neexistuje.

- [ ] **Step 3: Napiš `BlockUsage`**

Vytvoř `gui/src/BlockUsage.php`:

```php
<?php

declare(strict_types=1);

namespace Donut\Gui;

use Donut\Format\ForeachStep;
use Donut\Format\IfStep;
use Donut\Format\RunStep;
use Donut\Format\Step;
use Donut\Format\Workflow;


/**
 * Kdo který kámen používá.
 *
 * Průchod stromem kroků kopíruje KeyMap::walk() — vnořené steps mají
 * if (then i else) a foreach. Kámen, který v mapě není, nepoužívá nikdo.
 */
final class BlockUsage
{
	/**
	 * @param  array<string, Workflow> $workflows jméno workflow => workflow
	 * @return array<string, list<string>> jméno kamene => jména workflow
	 */
	public static function of(array $workflows): array
	{
		/** @var array<string, array<string, true>> $usage */
		$usage = [];

		foreach ($workflows as $name => $workflow) {
			// Klíčem je jméno workflow, ne index — kámen použitý ve dvou
			// krocích téhož workflow se má uvést jednou.
			self::walk($workflow->steps, (string) $name, $usage);
		}

		$result = [];

		foreach ($usage as $block => $names) {
			$names = \array_keys($names);

			// Seřadit i vnitřní seznam: bez toho by pořadí určilo pořadí
			// souborů v adresáři a výpis „používá: …" by se měnil bez
			// zjevného důvodu.
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
			}

			// SetStep na žádný kámen neodkazuje. Neznámý typ se tu vědomě
			// přeskakuje: BlockUsage je informativní, ne autoritativní —
			// na rozdíl od zapisovače, kde by tichý přeskok znamenal ztrátu dat.
		}
	}
}
```

- [ ] **Step 4: Spusť test a ověř, že prochází**

Run: `cd /home/honza/Dokumenty/Projekty/donut-org/donut/gui && vendor/bin/tester tests/BlockUsage.phpt -C`
Expected: PASS.

- [ ] **Step 5: Ověř mutací**

Zaveď postupně tyhle tři chyby a po každé spusť test:

1. ve `walk()` vynech větev `IfStep`
2. ve `walk()` u `IfStep` projdi jen `then`, ne `else`
3. místo `$usage[$step->block][$workflow] = true;` použij `$usage[$step->block][] = $workflow;`
   (třetí mutace má shodit asserci „jednou za workflow" u `echo`)

Expected: každá shodí `gui/tests/BlockUsage.phpt`.

Po každé mutaci ji vrať. **Co některá mutace projde, napiš do reportu.**

- [ ] **Step 6: Spusť sadu GUI a PHPStan**

Run: `cd /home/honza/Dokumenty/Projekty/donut-org/donut/gui && vendor/bin/tester tests -C && vendor/bin/phpstan analyse`
Expected: 11 testů (bylo 10), PHPStan bez chyb.

- [ ] **Step 7: Commit**

```bash
cd /home/honza/Dokumenty/Projekty/donut-org/donut
git add gui/src/BlockUsage.php gui/tests/BlockUsage.phpt
git commit -m "GUI: BlockUsage — kdo který kámen používá"
```

---

### Task 3: `BlockMapper` v GUI

Jádro projektu. Čistá obousměrná konverze mezi hodnotami formuláře a `Block`.
Nezná Nette ani HTTP, takže se dá celá otestovat bez prohlížeče.

**Files:**
- Create: `gui/src/BlockMapper.php`, `gui/tests/BlockMapper.phpt`

**Interfaces:**
- Consumes: `Donut\Format\{Block, Input, StdinSpec}`, `Donut\Template`.
- Produces:
  - `Donut\Gui\BlockMapper::toBlock(array $values): Block`
  - `Donut\Gui\BlockMapper::toValues(Block $block): array`
  - Tvar `$values` je popsaný v sekci „Tvar hodnot formuláře" nahoře.

- [ ] **Step 1: Napiš padající test**

Vytvoř `gui/tests/BlockMapper.phpt`:

```php
<?php

declare(strict_types=1);

use Donut\Format\Block;
use Donut\Format\Input;
use Donut\Format\StdinSpec;
use Donut\Gui\BlockMapper;
use Donut\Parser\BlockParser;
use Donut\Template;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

$mapper = new BlockMapper;

// --- round-trip nad referenční zátěží ---
//
// Patnáct skutečných kamenů projde Block → values → Block. Kdyby mapper
// zahodil timeout, default u vstupu nebo allow_failure, tohle to chytí.
// Porovnává se přes serialize(): typově přesné a odolné vůči hloubce,
// stejně jako v round-tripu serializéru.

$parser = new BlockParser;
$blocks = \glob(__DIR__ . '/../../docs/workflows/donut/blocks/*.json');
Assert::count(15, $blocks === false ? [] : $blocks);

foreach ($blocks === false ? [] : $blocks as $path) {
	$puvodni = $parser->parseFile($path);
	$znovu = $mapper->toBlock($mapper->toValues($puvodni));

	Assert::same(
		\serialize($puvodni),
		\serialize($znovu),
		'round-trip kamene ' . \basename($path),
	);
}

// --- díry v indexech se srovnají ---
//
// JS řádky nikdy nepřečísluje: přidá index o jedna vyšší než maximum
// a smazání nechá díru. Srovnání je úkol mapperu.

$sDirou = $mapper->toBlock([
	'name' => 'dira',
	'description' => '',
	'command' => 'curl',
	'args' => [
		0 => [0 => '-sS', 2 => '--fail'],
		3 => [1 => '{%url%}'],
	],
	'inputs' => [
		1 => ['name' => 'url', 'required' => true, 'default' => '', 'description' => ''],
	],
	'hasStdin' => false,
	'stdinRequired' => false,
	'stdinDescription' => '',
	'timeout' => '',
	'allowFailure' => 'none',
	'allowFailureCodes' => '',
]);

Assert::same(
	[['-sS', '--fail'], ['{%url%}']],
	\array_map(
		fn(array $g): array => \array_map(fn(Template $t): string => $t->getSource(), $g),
		$sDirou->args,
	),
);
Assert::same(['url'], \array_keys($sDirou->inputs));

// --- prázdné řádky a prázdné skupiny vypadnou ---

$sPrazdnymi = $mapper->toBlock([
	'name' => 'prazdne',
	'description' => '',
	'command' => 'echo',
	'args' => [
		0 => [0 => 'ahoj', 1 => ''],
		1 => [0 => '', 1 => ''],
		2 => [0 => 'svete'],
	],
	'inputs' => [
		0 => ['name' => '', 'required' => true, 'default' => '', 'description' => 'nikdo'],
		1 => ['name' => 'kdo', 'required' => true, 'default' => '', 'description' => ''],
	],
	'hasStdin' => false,
	'stdinRequired' => false,
	'stdinDescription' => '',
	'timeout' => '',
	'allowFailure' => 'none',
	'allowFailureCodes' => '',
]);

// Skupina 1 byla celá prázdná — zmizela, nezůstala po ní prázdná skupina.
Assert::count(2, $sPrazdnymi->args);
Assert::same('ahoj', $sPrazdnymi->args[0][0]->getSource());
Assert::count(1, $sPrazdnymi->args[0]);
Assert::same('svete', $sPrazdnymi->args[1][0]->getSource());

// Řádek vstupu bez jména se zahodí i s vyplněným popisem.
Assert::same(['kdo'], \array_keys($sPrazdnymi->inputs));

// --- '' znamená nevyplněno, ne prázdný řetězec ---
// Sekce 6 specifikace formátu: nevyplněno a "" je totéž.

Assert::null($sPrazdnymi->description);
Assert::null($sPrazdnymi->timeout);
Assert::null($sPrazdnymi->inputs['kdo']->default);
Assert::null($sPrazdnymi->inputs['kdo']->description);

// --- stdin se objeví a zmizí podle zaškrtávátka ---

$zaklad = [
	'name' => 'x', 'description' => '', 'command' => 'cat',
	'args' => [], 'inputs' => [],
	'hasStdin' => true, 'stdinRequired' => false, 'stdinDescription' => 'Tělo',
	'timeout' => '', 'allowFailure' => 'none', 'allowFailureCodes' => '',
];

$sStdin = $mapper->toBlock($zaklad);
Assert::type(StdinSpec::class, $sStdin->stdin);
Assert::false($sStdin->stdin->required);
Assert::same('Tělo', $sStdin->stdin->description);

// Nezaškrtnuté = objekt není, i když popis zůstal vyplněný ve formuláři.
Assert::null($mapper->toBlock(['hasStdin' => false] + $zaklad)->stdin);

// --- allow_failure má tři stavy ---

Assert::false($mapper->toBlock($zaklad)->allowFailure);
Assert::true($mapper->toBlock(['allowFailure' => 'any'] + $zaklad)->allowFailure);
Assert::same(
	[0, 1],
	$mapper->toBlock(['allowFailure' => 'list', 'allowFailureCodes' => '0, 1'] + $zaklad)->allowFailure,
);

// Nečíselný kód se ignoruje — formulář ho odmítne dřív, mapper nesmí spadnout.
Assert::same(
	[2],
	$mapper->toBlock(['allowFailure' => 'list', 'allowFailureCodes' => '2, x, '] + $zaklad)->allowFailure,
);

// Prázdný seznam u 'list' spadne zpátky na false — pole [] by parser odmítl.
Assert::false($mapper->toBlock(['allowFailure' => 'list', 'allowFailureCodes' => ''] + $zaklad)->allowFailure);

// --- timeout se převede na int ---

Assert::same(30, $mapper->toBlock(['timeout' => '30'] + $zaklad)->timeout);

// --- toValues() dává tvar, který formulář očekává ---

$values = $mapper->toValues(new Block(
	name: 'plny',
	command: 'curl',
	args: [[Template::parse('-sS'), Template::parse('--fail')]],
	inputs: ['url' => new Input(name: 'url', required: false, default: '/tmp/x', description: 'Adresa')],
	stdin: new StdinSpec(required: true, description: 'Tělo'),
	timeout: 30,
	allowFailure: [0, 1],
	description: 'Popis',
));

Assert::same('plny', $values['name']);
Assert::same('Popis', $values['description']);
Assert::same([['-sS', '--fail']], $values['args']);
Assert::same(
	[['name' => 'url', 'required' => false, 'default' => '/tmp/x', 'description' => 'Adresa']],
	$values['inputs'],
);
Assert::true($values['hasStdin']);
Assert::true($values['stdinRequired']);
Assert::same('Tělo', $values['stdinDescription']);
Assert::same('30', $values['timeout']);
Assert::same('list', $values['allowFailure']);
Assert::same('0, 1', $values['allowFailureCodes']);

// Nevyplněná pole vyjdou jako '', ne jako null — formulář chce řetězce.
$holy = $mapper->toValues(new Block(name: 'holy', command: 'echo', args: []));
Assert::same('', $holy['description']);
Assert::same('', $holy['timeout']);
Assert::false($holy['hasStdin']);
Assert::same('none', $holy['allowFailure']);
```

- [ ] **Step 2: Spusť test a ověř, že padá**

Run: `cd /home/honza/Dokumenty/Projekty/donut-org/donut/gui && vendor/bin/tester tests/BlockMapper.phpt -C`
Expected: FAIL — `Donut\Gui\BlockMapper` neexistuje.

- [ ] **Step 3: Napiš `BlockMapper`**

Vytvoř `gui/src/BlockMapper.php`:

```php
<?php

declare(strict_types=1);

namespace Donut\Gui;

use Donut\Format\Block;
use Donut\Format\Input;
use Donut\Format\StdinSpec;
use Donut\Template;


/**
 * Hodnoty formuláře ↔ Block. Čistá konverze, nezná Nette ani HTTP.
 *
 * Indexy v args a inputs můžou mít díry — JS řádky nikdy nepřečísluje, jen
 * je přidává a ubírá, takže po smazání prostředního řádku zůstane v číslování
 * mezera. Srovnání je tady.
 *
 * Prázdný řetězec znamená nevyplněno, ne "" — sekce 6 specifikace formátu.
 */
final class BlockMapper
{
	/**
	 * @param array<string, mixed> $values
	 */
	public function toBlock(array $values): Block
	{
		return new Block(
			name: \trim((string) ($values['name'] ?? '')),
			command: \trim((string) ($values['command'] ?? '')),
			args: $this->toArgs($values['args'] ?? []),
			inputs: $this->toInputs($values['inputs'] ?? []),
			stdin: ($values['hasStdin'] ?? false)
				? new StdinSpec(
					required: (bool) ($values['stdinRequired'] ?? false),
					description: self::orNull($values['stdinDescription'] ?? ''),
				)
				: null,
			timeout: ($t = \trim((string) ($values['timeout'] ?? ''))) === '' ? null : (int) $t,
			allowFailure: $this->toAllowFailure($values),
			description: self::orNull($values['description'] ?? ''),
		);
	}


	/**
	 * @return array<string, mixed>
	 */
	public function toValues(Block $block): array
	{
		$args = [];

		foreach ($block->args as $group) {
			$args[] = \array_values(\array_map(
				fn(Template $template): string => $template->getSource(),
				$group,
			));
		}

		$inputs = [];

		foreach ($block->inputs as $name => $input) {
			$inputs[] = [
				'name' => $name,
				'required' => $input->required,
				'default' => $input->default ?? '',
				'description' => $input->description ?? '',
			];
		}

		return [
			'name' => $block->name,
			'description' => $block->description ?? '',
			'command' => $block->command,
			'args' => $args,
			'inputs' => $inputs,
			'hasStdin' => $block->stdin !== null,
			'stdinRequired' => $block->stdin?->required ?? true,
			'stdinDescription' => $block->stdin?->description ?? '',
			'timeout' => $block->timeout === null ? '' : (string) $block->timeout,
			'allowFailure' => match (true) {
				$block->allowFailure === false => 'none',
				$block->allowFailure === true => 'any',
				default => 'list',
			},
			'allowFailureCodes' => \is_array($block->allowFailure)
				? \implode(', ', $block->allowFailure)
				: '',
		];
	}


	/**
	 * @param  mixed $raw
	 * @return array<int, array<int, Template>>
	 */
	private function toArgs(mixed $raw): array
	{
		if (!\is_array($raw)) {
			return [];
		}

		$groups = [];

		// ksort, protože pořadí klíčů z POSTu není zaručené a na pořadí
		// argumentů záleží.
		\ksort($raw);

		foreach ($raw as $group) {
			if (!\is_array($group)) {
				continue;
			}

			\ksort($group);
			$args = [];

			foreach ($group as $arg) {
				$arg = \trim((string) $arg);

				if ($arg !== '') {
					$args[] = Template::parse($arg);
				}
			}

			// Skupina, ve které nezbyl argument, do souboru nepatří.
			if ($args !== []) {
				$groups[] = $args;
			}
		}

		return $groups;
	}


	/**
	 * @param  mixed $raw
	 * @return array<string, Input>
	 */
	private function toInputs(mixed $raw): array
	{
		if (!\is_array($raw)) {
			return [];
		}

		$inputs = [];
		\ksort($raw);

		foreach ($raw as $row) {
			if (!\is_array($row)) {
				continue;
			}

			$name = \trim((string) ($row['name'] ?? ''));

			// Řádek bez jména je nedopsaný řádek, ne vstup.
			if ($name === '') {
				continue;
			}

			$inputs[$name] = new Input(
				name: $name,
				required: (bool) ($row['required'] ?? false),
				default: self::orNull($row['default'] ?? ''),
				description: self::orNull($row['description'] ?? ''),
			);
		}

		return $inputs;
	}


	/**
	 * @param  array<string, mixed> $values
	 * @return bool|array<int, int>
	 */
	private function toAllowFailure(array $values): bool|array
	{
		$mode = (string) ($values['allowFailure'] ?? 'none');

		if ($mode === 'any') {
			return true;
		}

		if ($mode !== 'list') {
			return false;
		}

		$codes = [];

		foreach (\explode(',', (string) ($values['allowFailureCodes'] ?? '')) as $code) {
			$code = \trim($code);

			if (\ctype_digit($code)) {
				$codes[] = (int) $code;
			}
		}

		// Prázdný výčet by parser odmítl — je to totéž jako "nepovoluj nic".
		return $codes === [] ? false : $codes;
	}


	private static function orNull(mixed $value): ?string
	{
		$value = \trim((string) $value);

		return $value === '' ? null : $value;
	}
}
```

- [ ] **Step 4: Spusť test a ověř, že prochází**

Run: `cd /home/honza/Dokumenty/Projekty/donut-org/donut/gui && vendor/bin/tester tests/BlockMapper.phpt -C`
Expected: PASS.

Kdyby round-trip spadl na některém z patnácti kamenů, **neupravuj test, aby
prošel** — znamená to, že mapper některé pole nepřenáší. Vypiš si rozdíl mezi
oběma `serialize()` řetězci, najdi chybějící pole a oprav mapper.

- [ ] **Step 5: Ověř mutací, že round-trip něco drží**

Zaveď postupně tyhle čtyři chyby a po každé spusť test:

1. v `toValues()` vynech `'default' => $input->default ?? ''`
   (nahraď za `'default' => ''`)
2. v `toArgs()` vynech obě volání `ksort()`
3. v `toInputs()` vynech podmínku na prázdné jméno
4. v `toAllowFailure()` vrať `true` místo `$codes`

Expected: každá shodí `gui/tests/BlockMapper.phpt`.

Po každé mutaci ji vrať a nakonec ověř `git status --porcelain gui/src/`.
**Co některá mutace projde, napiš do reportu** — mutace 1 a 2 jsou zajímavé
tím, že je chytá jen round-trip nad zátěží, ne jednotkové aserce.

- [ ] **Step 6: Spusť sadu GUI a PHPStan**

Run: `cd /home/honza/Dokumenty/Projekty/donut-org/donut/gui && vendor/bin/tester tests -C && vendor/bin/phpstan analyse`
Expected: 12 testů (bylo 11), PHPStan bez chyb.

- [ ] **Step 7: Commit**

```bash
cd /home/honza/Dokumenty/Projekty/donut-org/donut
git add gui/src/BlockMapper.php gui/tests/BlockMapper.phpt
git commit -m "GUI: BlockMapper — hodnoty formuláře na Block a zpátky"
```

---

### Task 4: `BlockStore` v GUI

Jediné místo, které ví, kde kámen bydlí.

**Files:**
- Create: `gui/src/BlockStore.php`, `gui/tests/BlockStore.phpt`

**Interfaces:**
- Consumes: `Donut\BlockRepository`, `Donut\Writer\BlockWriter`, `Donut\Format\Block`, `Donut\Parser\ParseException`.
- Produces:
  - `Donut\Gui\BlockStore::__construct(string $directory)`
  - `::path(string $name): string`
  - `::exists(string $name): bool`
  - `::get(string $name): Block`
  - `::names(): array<int, string>`
  - `::loadAll(): array<string, Block|string>`
  - `::save(Block $block): void`
  - `::delete(string $name): void`

- [ ] **Step 1: Napiš padající test**

Vytvoř `gui/tests/BlockStore.phpt`:

```php
<?php

declare(strict_types=1);

use Donut\Format\Block;
use Donut\Gui\BlockStore;
use Donut\Parser\ParseException;
use Donut\Template;
use Nette\Utils\FileSystem;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

$dir = TEMP_DIR . '/blocks';
FileSystem::createDir($dir);

$store = new BlockStore($dir);

// Cesta se skládá na jednom místě, a je to tohle.
Assert::same($dir . '/curl-get.json', $store->path('curl-get'));

// Prázdný adresář není chyba — do prázdného projektu se musí dát psát.
Assert::same([], $store->names());
Assert::false($store->exists('echo'));

// Uložení založí soubor, který jde hned přečíst zpátky.
$store->save(new Block(
	name: 'echo',
	command: 'echo',
	args: [[Template::parse('{%text%}')]],
	description: 'Vypíše text',
));

Assert::true(\is_file($dir . '/echo.json'));
Assert::true($store->exists('echo'));
Assert::same(['echo'], $store->names());
Assert::same('Vypíše text', $store->get('echo')->description);

// Uložení podruhé přepíše.
$store->save(new Block(name: 'echo', command: 'printf', args: []));
Assert::same('printf', $store->get('echo')->command);

// Neexistující kámen.
Assert::exception(fn() => $store->get('nope'), ParseException::class);

// Smazání odstraní soubor.
$store->delete('echo');
Assert::false(\is_file($dir . '/echo.json'));
Assert::false($store->exists('echo'));

// Smazání neexistujícího je chyba, ne ticho — jinak by GUI hlásilo úspěch
// nad něčím, co se nestalo.
Assert::exception(fn() => $store->delete('nope'), ParseException::class);

// Vadný soubor nezastíní ostatní — stejné pravidlo jako u `donut --list`.
FileSystem::write($dir . '/dobry.json', json_encode(['name' => 'dobry', 'command' => 'ls', 'args' => []]));
FileSystem::write($dir . '/vadny.json', '{ neplatny json');

$store = new BlockStore($dir);
$loaded = $store->loadAll();

Assert::same(['dobry', 'vadny'], array_keys($loaded));
Assert::type(Block::class, $loaded['dobry']);
Assert::type('string', $loaded['vadny']);

// Chybějící adresář je jiná situace než prázdný.
Assert::exception(fn() => new BlockStore($dir . '/neni'), ParseException::class);

FileSystem::delete(TEMP_DIR);
```

- [ ] **Step 2: Spusť test a ověř, že padá**

Run: `cd /home/honza/Dokumenty/Projekty/donut-org/donut/gui && vendor/bin/tester tests/BlockStore.phpt -C`
Expected: FAIL — `Donut\Gui\BlockStore` neexistuje.

- [ ] **Step 3: Napiš `BlockStore`**

Vytvoř `gui/src/BlockStore.php`:

```php
<?php

declare(strict_types=1);

namespace Donut\Gui;

use Donut\BlockRepository;
use Donut\Format\Block;
use Donut\Parser\ParseException;
use Donut\Writer\BlockWriter;
use Nette\Utils\FileSystem;


/**
 * Kameny na disku: čtení, zápis, mazání.
 *
 * Jediné místo, které ví, že kámen jménem "curl-get" bydlí
 * v <adresář>/curl-get.json. Zapisovač v donutu cestu vědomě neodvozuje ze
 * jména, jen ověřuje, že spolu sedí — složit ji musí někdo, a je to tohle.
 *
 * Repository se po každém zápisu zahodí: GUI nemá cache a při každém
 * requestu čte znovu, takže by drželo neaktuální seznam souborů.
 */
final class BlockStore
{
	private readonly BlockWriter $writer;

	private ?BlockRepository $repository = null;


	/**
	 * @throws ParseException když adresář neexistuje
	 */
	public function __construct(
		private readonly string $directory,
	) {
		$this->writer = new BlockWriter;

		if (!\is_dir($directory)) {
			throw new ParseException("Adresář s kameny '{$directory}' neexistuje.");
		}
	}


	public function path(string $name): string
	{
		return $this->directory . '/' . $name . '.json';
	}


	public function exists(string $name): bool
	{
		return $this->repository()->has($name);
	}


	/**
	 * @throws ParseException
	 */
	public function get(string $name): Block
	{
		return $this->repository()->get($name);
	}


	/** @return array<int, string> */
	public function names(): array
	{
		return $this->repository()->getNames();
	}


	/**
	 * Vadný soubor nesmí schovat ostatní — stejné pravidlo jako
	 * u `donut --list` a WorkflowRepository::loadAll().
	 *
	 * @return array<string, Block|string> jméno => kámen, nebo hláška o chybě
	 */
	public function loadAll(): array
	{
		$loaded = [];

		foreach ($this->names() as $name) {
			try {
				$loaded[$name] = $this->get($name);

			} catch (ParseException $e) {
				$loaded[$name] = $e->getMessage();
			}
		}

		return $loaded;
	}


	public function save(Block $block): void
	{
		$this->writer->writeFile($block, $this->path($block->name));
		$this->repository = null;
	}


	/**
	 * @throws ParseException když kámen neexistuje
	 */
	public function delete(string $name): void
	{
		if (!$this->exists($name)) {
			throw new ParseException("Kámen \"{$name}\" neexistuje. Hledal jsem v: {$this->directory}");
		}

		FileSystem::delete($this->path($name));
		$this->repository = null;
	}


	private function repository(): BlockRepository
	{
		return $this->repository ??= new BlockRepository($this->directory);
	}
}
```

- [ ] **Step 4: Spusť test a ověř, že prochází**

Run: `cd /home/honza/Dokumenty/Projekty/donut-org/donut/gui && vendor/bin/tester tests/BlockStore.phpt -C`
Expected: PASS.

- [ ] **Step 5: Ověř mutací**

Zaveď postupně tyhle dvě chyby a po každé spusť test:

1. v `save()` smaž řádek `$this->repository = null;`
   (bez něj `exists()` po uložení pořád vrací starý seznam)
2. v `delete()` vynech kontrolu existence

Expected: obě shodí `gui/tests/BlockStore.phpt`.

Po každé mutaci ji vrať. **Co některá mutace projde, napiš do reportu.**

- [ ] **Step 6: Spusť sadu GUI a PHPStan**

Run: `cd /home/honza/Dokumenty/Projekty/donut-org/donut/gui && vendor/bin/tester tests -C && vendor/bin/phpstan analyse`
Expected: 13 testů (bylo 12), PHPStan bez chyb.

- [ ] **Step 7: Commit**

```bash
cd /home/honza/Dokumenty/Projekty/donut-org/donut
git add gui/src/BlockStore.php gui/tests/BlockStore.phpt
git commit -m "GUI: BlockStore — kameny na disku"
```

---

### Task 5: Formulář, prezenter, šablona, JS

Teprve tady je něco vidět v prohlížeči.

**Files:**
- Modify: `gui/composer.json` (přibude `nette/forms`)
- Create: `gui/src/Presentation/Block/BlockEditTemplate.php`, `gui/src/Presentation/Block/edit.latte`
- Modify: `gui/src/Presentation/Block/BlockPresenter.php`
- Create: `gui/tests/BlockPresenter.edit.phpt`

**Interfaces:**
- Consumes: `BlockMapper::toBlock()` / `toValues()` (Task 3), `BlockStore` (Task 4), `Donut\Validator\BlockValidator::validate()` (Task 1).
- Produces: akce `Block:edit` (parametr `name`, prázdný = nový kámen) a signál `blockForm-submit`.

- [ ] **Step 1: Přidej `nette/forms` a zaregistruj jeho Latte rozšíření**

```bash
cd /home/honza/Dokumenty/Projekty/donut-org/donut/gui
composer require nette/forms:^3.2
```

**`Latte.TemplatesCompile.phpt` musí dostat `FormsExtension`**, jinak nová
šablona s `{form}` a `n:name` neprojde kompilací a test spadne z důvodu,
který nemá nic společného s chybou v šabloně. Do smyčky, hned za
`$engine->addExtension(new UIExtension(null));`, přidej:

```php
	// {form} a n:name pocházejí z nette/forms; bez téhle extension by
	// edit.latte neprošlo kompilací.
	$engine->addExtension(new Nette\Bridges\FormsLatte\FormsExtension);
```

Ověř, že třída opravdu takhle existuje
(`ls vendor/nette/forms/src/Bridges/FormsLatte/`), a když ne, použij tu, co
tam je, a napiš to do reportu.

Pak ověř, že `vendor/bin/tester tests -C` pořád projde (13 testů).

- [ ] **Step 2: Vytvoř sdílenou továrnu na prezenter pro testy**

Testy prezenteru v tomhle projektu **nestartují HTTP server** — sestaví
prezenter v procesu přes `injectPrimary()` a `chdir()` do fixtury. Přečti si
`gui/tests/WorkflowPresenter.detailRender.phpt`; tenhle soubor je jeho obdoba
pro `BlockPresenter` a potřebují ho dva testy, takže bydlí zvlášť.

Vytvoř `gui/tests/inc/blockPresenter.php`:

```php
<?php

declare(strict_types=1);

use Donut\Gui\Presentation\Block\BlockPresenter;
use Latte\Engine;
use Nette\Application\IPresenter;
use Nette\Application\IPresenterFactory;
use Nette\Application\Request;
use Nette\Application\Responses\TextResponse;
use Nette\Application\Routers\SimpleRouter;
use Nette\Application\UI\Control;
use Nette\Bridges\ApplicationLatte\LatteFactory;
use Nette\Bridges\ApplicationLatte\Template;
use Nette\Bridges\ApplicationLatte\TemplateFactory;
use Nette\Bridges\ApplicationLatte\UIExtension;
use Nette\Bridges\FormsLatte\FormsExtension;
use Nette\Http\Request as HttpRequest;
use Nette\Http\Response as HttpResponse;
use Nette\Http\UrlScript;
use Tester\Assert;


/**
 * BlockPresenter mimo DI kontejner. Kopíruje uspořádání
 * z WorkflowPresenter.detailRender.phpt; navíc registruje FormsExtension,
 * bez které by se edit.latte nezkompilovala.
 *
 * @param array<string, mixed> $post
 */
function createBlockPresenter(array $post = []): BlockPresenter
{
	$latteFactory = new class implements LatteFactory {
		public function create(?Control $control = null): Engine
		{
			// Bez setTempDirectory() Latte zkompilovaný kód jen eval()uje do
			// paměti. Cache adresář pod gui/tests/ by PHPStan (paths: [src,
			// tests]) sebral k analýze při dalším běhu.
			$engine = new Engine;
			$engine->addExtension(new UIExtension($control));
			$engine->addExtension(new FormsExtension);

			return $engine;
		}
	};

	$presenterFactory = new class implements IPresenterFactory {
		public function getPresenterClass(string &$name): string
		{
			return BlockPresenter::class;
		}


		public function createPresenter(string $name): IPresenter
		{
			throw new \LogicException('nepoužito — LinkGenerator jen skládá adresy');
		}
	};

	$presenter = new BlockPresenter;
	$presenter->injectPrimary(
		new HttpRequest(
			new UrlScript('http://localhost/'),
			post: $post,
			method: $post === [] ? 'GET' : 'POST',
		),
		new HttpResponse,
		presenterFactory: $presenterFactory,
		router: new SimpleRouter('Block:default'),
		templateFactory: new TemplateFactory($latteFactory),
	);

	// Bez tohohle by autoCanonicalize po run() vracelo RedirectResponse
	// místo šablony — Request je sestavený ručně, ne skutečným routováním.
	$presenter->autoCanonicalize = false;

	return $presenter;
}


/**
 * Prezenter čte pracovní adresář (WorkflowRepository::projectDir()), fixtura
 * tedy musí být aktuálním adresářem po dobu volání.
 *
 * @param  array<string, mixed> $params
 * @param  array<string, mixed> $post
 * @return array{0: mixed, 1: string} odpověď a vyrenderované HTML ('' u redirectu)
 */
function runBlockPresenterIn(string $dir, array $params, array $post = []): array
{
	$cwd = \getcwd();
	\chdir($dir);

	try {
		$presenter = createBlockPresenter($post);
		$request = new Request('Block', $post === [] ? 'GET' : 'POST', $params, $post);

		$response = null;
		Assert::noError(function () use ($presenter, $request, &$response) {
			$response = $presenter->run($request);
		});

		if (!$response instanceof TextResponse) {
			return [$response, ''];
		}

		$source = $response->getSource();
		Assert::type(Template::class, $source);

		return [$response, $source->renderToString()];

	} finally {
		\chdir((string) $cwd);
	}
}
```

- [ ] **Step 3: Napiš padající test na editaci**

Vytvoř `gui/tests/BlockPresenter.edit.phpt`:

```php
<?php

declare(strict_types=1);

use Donut\Parser\BlockParser;
use Nette\Application\Responses\RedirectResponse;
use Nette\Utils\FileSystem;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/inc/blockPresenter.php';

$projekt = TEMP_DIR . '/edit';
FileSystem::createDir($projekt . '/blocks');
FileSystem::createDir($projekt . '/workflows');

FileSystem::write($projekt . '/blocks/echo.json', json_encode([
	'name' => 'echo',
	'description' => 'Vypíše text',
	'command' => 'echo',
	'args' => [['{%text%}']],
	'inputs' => ['text' => ['required' => true]],
	'timeout' => 5,
]));

// --- editace existujícího: formulář je předvyplněný ---

[, $html] = runBlockPresenterIn($projekt, ['action' => 'edit', 'name' => 'echo']);

Assert::contains('value="echo"', $html);
Assert::contains('Vypíše text', $html);
Assert::contains('{%text%}', $html);
Assert::contains('value="5"', $html);

// --- zakládání nového: prázdný formulář, žádný pád ---

[, $novy] = runBlockPresenterIn($projekt, ['action' => 'edit']);

Assert::contains('<form', $novy);
Assert::notContains('Vypíše text', $novy);

// --- neexistující kámen se ohlásí, nespadne ---

[, $chybi] = runBlockPresenterIn($projekt, ['action' => 'edit', 'name' => 'neni']);
Assert::contains('neexistuje', $chybi);

// --- uložení: platný kámen projde a vznikne soubor ---

$post = [
	'name' => 'novy',
	'description' => 'Popis',
	'command' => 'curl',
	'args' => [
		// Díra v číslování schválně — JS řádky nepřečísluje.
		0 => [0 => '-sS'],
		2 => [0 => '{%url%}'],
	],
	'inputs' => [
		1 => ['name' => 'url', 'required' => '1', 'default' => '', 'description' => 'Adresa'],
	],
	'timeout' => '',
	'allowFailure' => 'none',
	'allowFailureCodes' => '',
	'save' => 'Uložit',
];

[$response] = runBlockPresenterIn(
	$projekt,
	['action' => 'edit', 'do' => 'blockForm-submit'],
	$post,
);

// Úspěch končí přesměrováním na editaci uloženého kamene.
Assert::type(RedirectResponse::class, $response);

$ulozeny = (new BlockParser)->parseFile($projekt . '/blocks/novy.json');
Assert::same('novy', $ulozeny->name);
Assert::same('curl', $ulozeny->command);

// Díra v indexech se srovnala a pořadí zůstalo.
Assert::same('-sS', $ulozeny->args[0][0]->getSource());
Assert::same('{%url%}', $ulozeny->args[1][0]->getSource());
Assert::same(['url'], array_keys($ulozeny->inputs));

// --- uložení: nedeklarovaná proměnná v args se odmítne ---

$vadny = ['name' => 'vadny', 'args' => [0 => [0 => '{%chybi%}']], 'inputs' => []] + $post;

[$response, $html] = runBlockPresenterIn(
	$projekt,
	['action' => 'edit', 'do' => 'blockForm-submit'],
	$vadny,
);

// Žádné přesměrování — formulář se vrátil s chybou.
Assert::false($response instanceof RedirectResponse);
Assert::contains('chybi', $html);
Assert::false(is_file($projekt . '/blocks/vadny.json'));

FileSystem::delete(TEMP_DIR);
```

- [ ] **Step 4: Spusť test a ověř, že padá**

Run: `cd /home/honza/Dokumenty/Projekty/donut-org/donut/gui && vendor/bin/tester tests/BlockPresenter.edit.phpt -C`
Expected: FAIL — akce `edit` neexistuje.

- [ ] **Step 5: Napiš šablonovou třídu**

Vytvoř `gui/src/Presentation/Block/BlockEditTemplate.php` podle vzoru
`BlockDefaultTemplate.php` (přečti si ho):

```php
<?php

declare(strict_types=1);

namespace Donut\Gui\Presentation\Block;

use Nette\Bridges\ApplicationLatte\Template;


final class BlockEditTemplate extends Template
{
	public ?string $name = null;

	public ?string $error = null;

	/** @var array<int, string> */
	public array $problems = [];
}
```

- [ ] **Step 6: Rozšiř `BlockPresenter`**

Do `gui/src/Presentation/Block/BlockPresenter.php` přidej. Ponech
`renderDefault()` beze změny.

```php
	private ?Block $editovany = null;


	public function actionEdit(?string $name = null): void
	{
		if ($name === null || $name === '') {
			return;
		}

		try {
			$this->editovany = $this->store()->get($name);

		} catch (ParseException $e) {
			/** @var BlockEditTemplate $template */
			$template = $this->template;
			$template->error = $e->getMessage();
		}
	}


	public function renderEdit(?string $name = null): void
	{
		/** @var BlockEditTemplate $template */
		$template = $this->template;
		$template->name = $name === '' ? null : $name;
	}


	protected function createComponentBlockForm(): Form
	{
		$form = new Form;
		$shape = $this->formShape();

		$form->addText('name', 'Jméno')
			->setRequired('Jméno je povinné.')
			->addRule($form::Pattern, 'Jméno smí obsahovat jen písmena, číslice, pomlčku a podtržítko.', '[A-Za-z0-9_-]+');

		$form->addText('description', 'Popis');
		$form->addText('command', 'Příkaz')->setRequired('Příkaz je povinný.');

		$args = $form->addContainer('args');

		foreach ($shape['args'] as $g => $indexes) {
			$group = $args->addContainer((string) $g);

			foreach ($indexes as $a) {
				$group->addText((string) $a);
			}
		}

		$inputs = $form->addContainer('inputs');

		foreach ($shape['inputs'] as $i) {
			$row = $inputs->addContainer((string) $i);
			$row->addText('name');
			$row->addCheckbox('required');
			$row->addText('default');
			$row->addText('description');
		}

		$form->addCheckbox('hasStdin', 'Kámen čte stdin');
		$form->addCheckbox('stdinRequired', 'stdin je povinný');
		$form->addText('stdinDescription', 'Popis stdin');

		$form->addText('timeout', 'Timeout (s)')
			->addCondition($form::Filled)
			->addRule($form::Integer, 'Timeout musí být celé číslo.')
			->addRule($form::Min, 'Timeout musí být kladný.', 1);

		$form->addRadioList('allowFailure', 'Povolené selhání', [
			'none' => 'jen exit 0',
			'any' => 'jakýkoliv exit kód',
			'list' => 'jen tyhle kódy:',
		])->setDefaultValue('none');

		$form->addText('allowFailureCodes')
			->addCondition($form::Filled)
			->addRule($form::Pattern, 'Kódy zadej jako čísla oddělená čárkou, třeba 0, 1.', '[0-9]+(\s*,\s*[0-9]+)*');

		$form->addSubmit('save', 'Uložit');
		$form->onSuccess[] = $this->blockFormSucceeded(...);

		if ($this->editovany !== null && !$this->getRequest()?->isMethod('POST')) {
			$form->setDefaults((new BlockMapper)->toValues($this->editovany));
		}

		return $form;
	}


	public function blockFormSucceeded(Form $form): void
	{
		/** @var array<string, mixed> $values */
		$values = $form->getValues('array');

		$block = (new BlockMapper)->toBlock($values);
		$result = (new BlockValidator)->validate($block);

		/** @var BlockEditTemplate $template */
		$template = $this->template;
		$template->problems = \array_map(
			fn($problem): string => $problem->message,
			$result->getProblems(),
		);

		// Chyba blokuje, varování ne.
		if ($result->hasErrors()) {
			$form->addError('Kámen se neuložil — oprav chyby níž.');

			return;
		}

		$this->store()->save($block);
		$this->redirect('edit', ['name' => $block->name]);
	}


	/**
	 * Kolik řádků formulář má.
	 *
	 * Při POSTu se odvodí z došlých dat — JS řádky nikdy nepřečísluje, takže
	 * indexy můžou mít díry a kontejnery musí vzniknout přesně pro ty klíče,
	 * které dorazily. Při GETu se vezmou z načteného kamene, plus jeden
	 * prázdný řádek navíc, aby bylo kam psát.
	 *
	 * Klíče z POSTu se filtrují na číslice: jméno komponenty v Nette musí
	 * odpovídat [a-zA-Z0-9_]+ a nic jiného sem stejně nepatří.
	 *
	 * @return array{args: array<int, array<int, int>>, inputs: array<int, int>}
	 */
	private function formShape(): array
	{
		$post = $this->getHttpRequest()->getPost();

		if ($post !== []) {
			return [
				'args' => self::nestedIndexes($post['args'] ?? []),
				'inputs' => self::indexes($post['inputs'] ?? []),
			];
		}

		$args = [];
		$inputs = [];

		if ($this->editovany !== null) {
			foreach (\array_values($this->editovany->args) as $g => $group) {
				$args[$g] = \array_keys(\array_values($group));
			}

			$inputs = \array_keys(\array_values($this->editovany->inputs));
		}

		// Jeden prázdný řádek navíc, aby měl uživatel kam psát i bez JS.
		$args[] = [0];
		$inputs[] = \count($inputs);

		return ['args' => $args, 'inputs' => $inputs];
	}


	/**
	 * @param  mixed $raw
	 * @return array<int, int>
	 */
	private static function indexes(mixed $raw): array
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


	/**
	 * @param  mixed $raw
	 * @return array<int, array<int, int>>
	 */
	private static function nestedIndexes(mixed $raw): array
	{
		if (!\is_array($raw)) {
			return [];
		}

		$shape = [];

		foreach (self::indexes($raw) as $key) {
			$shape[$key] = self::indexes($raw[$key]);
		}

		return $shape;
	}


	private function store(): BlockStore
	{
		return new BlockStore(WorkflowRepository::projectDir() . '/blocks');
	}
```

Doplň `use` na začátek souboru: `Donut\Format\Block`, `Donut\Gui\BlockMapper`,
`Donut\Gui\BlockStore`, `Donut\Validator\BlockValidator`,
`Nette\Application\UI\Form`.

- [ ] **Step 7: Napiš šablonu s inline JS**

Vytvoř `gui/src/Presentation/Block/edit.latte`.

**`<script>` musí mít `n:syntax="off"`.** Bez něj Latte spadne na objektovém
literálu — ověřeno, hláška je *Unexpected tag {name}*.
`tests/Latte.TemplatesCompile.phpt` to chytí.

```latte
{block title}{$name ?? 'Nový kámen'} — Donut{/block}

{block content}
<p><a n:href="Block:default">← kameny</a></p>

<h1>{$name ?? 'Nový kámen'}</h1>

<p n:if="$error" class=error>{$error}</p>

{if !$error}
	<ul n:if="$problems" class=warning>
		<li n:foreach="$problems as $problem">{$problem}</li>
	</ul>

	{form blockForm}
		<ul n:if="$form->getErrors()" class=error>
			<li n:foreach="$form->getErrors() as $chyba">{$chyba}</li>
		</ul>

		<p>{label name /} {input name}</p>
		<p>{label description /} {input description}</p>
		<p>{label command /} {input command}</p>

		<h2>Argumenty</h2>
		<p class=keys>Skupina, ve které se proměnná vyhodnotí na prázdno, vypadne celá.</p>

		<div id=args>
			<div class=arg-group n:foreach="$form['args']->getComponents() as $group">
				<span n:foreach="$group->getComponents() as $arg">{input $arg}</span>
				<button type=button class=add-arg>+ argument</button>
				<button type=button class=del-group>× skupina</button>
			</div>
		</div>

		<button type=button id=add-group>+ skupina</button>

		<h2>Vstupy</h2>

		<div id=inputs>
			<div class=input-row n:foreach="$form['inputs']->getComponents() as $row">
				{input $row['name']}
				{input $row['required']} povinný
				{input $row['default']}
				{input $row['description']}
				<button type=button class=del-input>×</button>
			</div>
		</div>

		<button type=button id=add-input>+ vstup</button>

		<h2>Stdin</h2>
		<p>{input hasStdin} {label hasStdin /}</p>
		<p>{input stdinRequired} {label stdinRequired /}</p>
		<p>{label stdinDescription /} {input stdinDescription}</p>

		<h2>Ostatní</h2>
		<p>{label timeout /} {input timeout}</p>
		<p>{label allowFailure /} {input allowFailure} {input allowFailureCodes}</p>

		<p>{input save}</p>
	{/form}

	<script n:syntax="off">
	// Řádky se nikdy nepřečíslovávají: nový dostane index o jedna vyšší, než
	// je současné maximum, a smazání nechá v číslování díru. Server pole
	// srovná přes array_values() v BlockMapperu. Přečíslovávání by mohlo
	// tiše prohodit dva argumenty; takhle ta chyba nemá kde vzniknout.
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
		copy.querySelectorAll('input').forEach(i => {
			i.setAttribute('name', rename(i.getAttribute('name')));
			if (i.type === 'checkbox') i.checked = false; else i.value = '';
		});
		return copy;
	};

	document.getElementById('add-group').addEventListener('click', () => {
		const box = document.getElementById('args');
		const rows = box.querySelectorAll('.arg-group');
		const next = maxIndex(box.querySelectorAll('input'), /^args\[(\d+)]/) + 1;
		box.appendChild(cloneRow(
			rows[rows.length - 1],
			n => n.replace(/^args\[\d+]\[\d+]/, 'args[' + next + '][0]')
		));
	});

	document.getElementById('add-input').addEventListener('click', () => {
		const box = document.getElementById('inputs');
		const rows = box.querySelectorAll('.input-row');
		const next = maxIndex(box.querySelectorAll('input'), /^inputs\[(\d+)]/) + 1;
		box.appendChild(cloneRow(
			rows[rows.length - 1],
			n => n.replace(/^inputs\[\d+]/, 'inputs[' + next + ']')
		));
	});

	document.addEventListener('click', e => {
		if (e.target.classList.contains('add-arg')) {
			const group = e.target.closest('.arg-group');
			const first = group.querySelector('span');
			const g = /^args\[(\d+)]/.exec(first.querySelector('input').getAttribute('name'))[1];
			const next = maxIndex(group.querySelectorAll('input'), /^args\[\d+]\[(\d+)]/) + 1;
			group.insertBefore(
				cloneRow(first, n => 'args[' + g + '][' + next + ']'),
				e.target
			);
		}

		if (e.target.classList.contains('del-group')) {
			const box = document.getElementById('args');
			if (box.querySelectorAll('.arg-group').length > 1) e.target.closest('.arg-group').remove();
		}

		if (e.target.classList.contains('del-input')) {
			const box = document.getElementById('inputs');
			if (box.querySelectorAll('.input-row').length > 1) e.target.closest('.input-row').remove();
		}
	});
	</script>
{/if}
```

**Tenhle tvar je ověřený.** Šablona s `{input $arg}` nad `$group->getComponents()`
a `{input $row['name']}` nad `$form['inputs']->getComponents()` se doopravdy
vyrenderovala proti formuláři s kontejnery `[0 => [0, 2], 3 => [1]]` a vyrobila
přesně tyhle `name` atributy:

```
args[0][0]
args[0][2]
args[3][1]
inputs[1][name]
inputs[1][required]
```

Děravé indexy tedy přežijí až do HTML — což je přesně to, na čem stojí JS
i `formShape()`. Kdyby ti Latte přesto cokoliv hlásilo, oprav to a napiš do
reportu co.

- [ ] **Step 8: Přidej odkaz na editaci do přehledu kamenů**

Do `gui/src/Presentation/Block/default.latte` přidej pod `<h1>Kameny</h1>`:

```latte
<p><a n:href="Block:edit">+ nový kámen</a></p>
```

a do bloku každého kamene, hned za `<h2>{$name}</h2>`:

```latte
	<p><a n:href="Block:edit, name: $name">upravit</a></p>
```

- [ ] **Step 9: Spusť test a ověř, že prochází**

Run: `cd /home/honza/Dokumenty/Projekty/donut-org/donut/gui && vendor/bin/tester tests/BlockPresenter.edit.phpt tests/Latte.TemplatesCompile.phpt -C`
Expected: PASS obojí.

- [ ] **Step 10: Vyzkoušej to v prohlížeči a ulož skutečný kámen**

Tohle je jediný krok, který ověří, že celý řetěz formulář → mapper →
validátor → zapisovač drží pohromadě.

```bash
cd /tmp && rm -rf zkouska && mkdir -p zkouska/blocks zkouska/workflows && cd zkouska
php -S 127.0.0.1:8000 -t . /home/honza/Dokumenty/Projekty/donut-org/donut/gui/www/index.php
```

V prohlížeči na `http://127.0.0.1:8000/?presenter=Block&action=edit`:

1. Založ kámen se dvěma skupinami argumentů a dvěma vstupy.
2. Přidej třetí skupinu, pak **smaž prostřední** — díra v číslování je přesně
   ten případ, na kterém návrh stojí.
3. Ulož a zkontroluj `cat /tmp/zkouska/blocks/<jméno>.json` — pořadí argumentů
   musí odpovídat tomu, co jsi viděl ve formuláři.
4. Zkus uložit kámen, který v args používá `{%neco%}` bez deklarovaného vstupu
   — musí to **odmítnout** a vypsat chybu.

**Zapiš do reportu, co jsi viděl, včetně obsahu uloženého souboru.** Kdyby
cokoliv nesedělo, oprav to a napiš co.

- [ ] **Step 11: Spusť sadu GUI a PHPStan**

Run:
```bash
cd /home/honza/Dokumenty/Projekty/donut-org/donut/gui && vendor/bin/tester tests -C && vendor/bin/phpstan analyse
```
Expected: 14 testů (bylo 13), PHPStan bez chyb.

- [ ] **Step 12: Commit**

```bash
cd /home/honza/Dokumenty/Projekty/donut-org/donut
git add gui/composer.json gui/composer.lock \
	gui/src/Presentation/Block/BlockPresenter.php \
	gui/src/Presentation/Block/BlockEditTemplate.php \
	gui/src/Presentation/Block/edit.latte \
	gui/src/Presentation/Block/default.latte \
	gui/tests/inc/blockPresenter.php \
	gui/tests/BlockPresenter.edit.phpt \
	gui/tests/Latte.TemplatesCompile.phpt
git commit -m "GUI: formulář na editaci kamene"
```

---

### Task 6: Mazání a výpis použití

**Mazání bydlí na stránce editace, ne v přehledu.** V přehledu by formulář
musel být uvnitř `n:foreach`, což znamená jednu komponentu formuláře
vykreslenou mnohokrát — to Nette neumí a skončilo by to duplicitními ID
a nefunkčním odesláním. Na stránce editace je jeden kámen, tedy jeden
formulář. Přehled ukazuje jen, kdo který kámen používá.

**Žádné flash zprávy.** `Presenter::flashMessage()` sahá na session, kterou
tenhle test sestavuje ručně a GUI ji jinak nepotřebuje. Odmítnutí se ukáže
jako chyba formuláře, úspěch skončí přesměrováním do přehledu.

**Files:**
- Modify: `gui/src/Presentation/Block/BlockPresenter.php`, `gui/src/Presentation/Block/BlockDefaultTemplate.php`, `gui/src/Presentation/Block/BlockEditTemplate.php`, `gui/src/Presentation/Block/default.latte`, `gui/src/Presentation/Block/edit.latte`
- Create: `gui/tests/BlockPresenter.delete.phpt`

**Interfaces:**
- Consumes: `BlockUsage::of()` (Task 2), `BlockStore::delete()` (Task 4), `Donut\Gui\WorkflowRepository::loadAll()`, `runBlockPresenterIn()` z `gui/tests/inc/blockPresenter.php` (Task 5).
- Produces: formulář `deleteForm` na akci `Block:edit`.

- [ ] **Step 1: Napiš padající test**

Vytvoř `gui/tests/BlockPresenter.delete.phpt`:

```php
<?php

declare(strict_types=1);

use Nette\Application\Responses\RedirectResponse;
use Nette\Utils\FileSystem;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/inc/blockPresenter.php';

$projekt = TEMP_DIR . '/delete';
FileSystem::createDir($projekt . '/blocks');
FileSystem::createDir($projekt . '/workflows');

// pouzity je v workflow, volny ne.
foreach (['pouzity', 'volny'] as $jmeno) {
	FileSystem::write($projekt . "/blocks/{$jmeno}.json", json_encode([
		'name' => $jmeno, 'command' => 'echo', 'args' => [],
	]));
}

FileSystem::write($projekt . '/workflows/w.json', json_encode([
	'name' => 'w',
	'steps' => [['type' => 'run', 'block' => 'pouzity']],
]));

// --- přehled ukazuje, kdo který kámen používá ---

[, $html] = runBlockPresenterIn($projekt, ['action' => 'default']);

Assert::contains('používá', $html);
Assert::contains('w', $html);

// --- editace volného kamene nabídne mazání ---

[, $html] = runBlockPresenterIn($projekt, ['action' => 'edit', 'name' => 'volny']);
Assert::contains('Smazat', $html);

// --- editace použitého kamene mazání nenabídne a řekne proč ---

[, $html] = runBlockPresenterIn($projekt, ['action' => 'edit', 'name' => 'pouzity']);
Assert::notContains('Smazat', $html);
Assert::contains('používá', $html);
Assert::contains('w', $html);

// --- POST na použitý kámen se odmítne, i když tlačítko v HTML nebylo ---
// Šablona tlačítko schová, prezenter to ohlídá. Obojí schválně.

[$response, $html] = runBlockPresenterIn(
	$projekt,
	['action' => 'edit', 'name' => 'pouzity', 'do' => 'deleteForm-submit'],
	['name' => 'pouzity', 'delete' => 'Smazat'],
);

Assert::false($response instanceof RedirectResponse);
Assert::true(is_file($projekt . '/blocks/pouzity.json'));

// --- volný kámen se smaže a přesměruje se do přehledu ---

[$response] = runBlockPresenterIn(
	$projekt,
	['action' => 'edit', 'name' => 'volny', 'do' => 'deleteForm-submit'],
	['name' => 'volny', 'delete' => 'Smazat'],
);

Assert::type(RedirectResponse::class, $response);
Assert::false(is_file($projekt . '/blocks/volny.json'));

FileSystem::delete(TEMP_DIR);
```

- [ ] **Step 2: Spusť test a ověř, že padá**

Run: `cd /home/honza/Dokumenty/Projekty/donut-org/donut/gui && vendor/bin/tester tests/BlockPresenter.delete.phpt -C`
Expected: FAIL — přehled kamenů použití nevypisuje.

- [ ] **Step 3: Doplň použití do `renderDefault()`**

Do `BlockDefaultTemplate.php` přidej:

```php
	/** @var array<string, list<string>> */
	public array $usage = [];
```

V `BlockPresenter::renderDefault()` na konci, těsně před `$template->dir = $dir;`:

```php
		$workflows = [];

		try {
			$repository = new WorkflowRepository(WorkflowRepository::projectDir() . '/workflows');

			foreach ($repository->loadAll() as $name => $workflow) {
				// Vadné workflow nesmí shodit přehled kamenů — o použití
				// kamene neřekne nic, ale zbytek stránky má fungovat.
				if (!\is_string($workflow)) {
					$workflows[$name] = $workflow;
				}
			}

		} catch (ParseException) {
			// Bez adresáře workflows se použití prostě nezobrazí.
		}

		$template->usage = BlockUsage::of($workflows);
```

- [ ] **Step 4: Přidej mazání do prezenteru**

```php
	protected function createComponentDeleteForm(): Form
	{
		$form = new Form;
		$form->addHidden('name');
		$form->addSubmit('delete', 'Smazat');
		$form->onSuccess[] = $this->deleteFormSucceeded(...);

		return $form;
	}


	public function deleteFormSucceeded(Form $form): void
	{
		/** @var array{name: string} $values */
		$values = $form->getValues('array');
		$name = $values['name'];

		$usage = BlockUsage::of($this->loadWorkflows());

		// Chyba blokuje, stejně jako u ukládání. Smazat kámen, na který se
		// odkazuje workflow, není varování — je to rozbití něčeho, co běželo.
		// Šablona tlačítko v takovém případě nevykreslí; tohle je druhá
		// pojistka pro ručně poslaný POST.
		if (isset($usage[$name])) {
			$form->addError(
				"Kámen \"{$name}\" nejde smazat — používá ho: " . \implode(', ', $usage[$name]) . '.'
			);

			return;
		}

		try {
			$this->store()->delete($name);

		} catch (ParseException $e) {
			$form->addError($e->getMessage());

			return;
		}

		$this->redirect('default');
	}
```

Průchod workflow vytáhni ze Stepu 3 do soukromé metody, ať existuje jednou:

```php
	/** @return array<string, Workflow> */
	private function loadWorkflows(): array
	{
		$workflows = [];

		try {
			$repository = new WorkflowRepository(WorkflowRepository::projectDir() . '/workflows');

			foreach ($repository->loadAll() as $name => $workflow) {
				// Vadné workflow nesmí shodit stránku — o použití kamene
				// neřekne nic, ale zbytek má fungovat.
				if (!\is_string($workflow)) {
					$workflows[$name] = $workflow;
				}
			}

		} catch (ParseException) {
			// Bez adresáře workflows se použití prostě nezobrazí.
		}

		return $workflows;
	}
```

a v `renderDefault()` volej `BlockUsage::of($this->loadWorkflows())`.

V `renderEdit()` doplň, kdo kámen používá — šablona podle toho ukáže nebo
schová mazání:

```php
		$template->usedBy = $template->name === null
			? []
			: (BlockUsage::of($this->loadWorkflows())[$template->name] ?? []);
```

Do `BlockEditTemplate.php` k tomu:

```php
	/** @var list<string> */
	public array $usedBy = [];
```

Doplň `use Donut\Format\Workflow;` a `use Donut\Gui\BlockUsage;`.

- [ ] **Step 5: Doplň šablony**

Do `gui/src/Presentation/Block/default.latte`, do bloku každého kamene za
odkaz „upravit":

```latte
	<p n:if="isset($usage[$name])" class=keys>
		používá: {implode(', ', $usage[$name])}
	</p>
```

Do `gui/src/Presentation/Block/edit.latte` na konec bloku `{if !$error}`,
za `{/form}` uzavírající `blockForm`:

```latte
	{if $name !== null}
		<h2>Smazat</h2>

		{if $usedBy}
			<p class=keys>
				Nejde smazat — používá ho: {implode(', ', $usedBy)}.
			</p>
		{else}
			{form deleteForm}
				<ul n:if="$form->getErrors()" class=error>
					<li n:foreach="$form->getErrors() as $chyba">{$chyba}</li>
				</ul>

				<input type=hidden n:name=name value="{$name}">
				<button type=submit n:name=delete onclick="return confirm('Opravdu smazat {$name}?')">Smazat</button>
			{/form}
		{/if}
	{/if}
```

Mazání se nabízí jen u existujícího kamene (u zakládání není co mazat) a jen
u nepoužitého. Šablona tlačítko schová, prezenter ručně poslaný POST odmítne
— obojí schválně.

- [ ] **Step 6: Spusť test a ověř, že prochází**

Run: `cd /home/honza/Dokumenty/Projekty/donut-org/donut/gui && vendor/bin/tester tests/BlockPresenter.delete.phpt tests/Latte.TemplatesCompile.phpt -C`
Expected: PASS obojí.

- [ ] **Step 7: Vyzkoušej mazání v prohlížeči**

Ve stejném projektu jako v Tasku 5, Step 9:

1. Založ kámen, který nikdo nepoužívá — na jeho stránce editace musí být
   tlačítko Smazat a musí fungovat.
2. Napiš workflow, které jiný kámen používá, a otevři jeho editaci — tlačítko
   tam nesmí být a musí být vidět, kdo ho drží.
3. Pošli ten POST ručně a ověř, že prezenter mazání odmítne a soubor zůstane:
   ```bash
   curl -s -X POST -d 'name=<jméno>&delete=Smazat' \
     'http://127.0.0.1:8000/?presenter=Block&action=edit&name=<jméno>&do=deleteForm-submit' \
     | grep -i 'nejde smazat'
   ls /tmp/zkouska/blocks/
   ```

**Zapiš do reportu, co jsi viděl.**

- [ ] **Step 8: Spusť obě sady a PHPStan**

Run:
```bash
cd /home/honza/Dokumenty/Projekty/donut-org/donut/gui && vendor/bin/tester tests -C && vendor/bin/phpstan analyse
cd /home/honza/Dokumenty/Projekty/donut-org/donut && vendor/bin/tester tests -C && vendor/bin/phpstan analyse
```
Expected: GUI 15 testů (bylo 14), donut 32 testů, PHPStan obojí bez chyb.

- [ ] **Step 9: Odškrtni v zadání**

V `docs/zadani.md` škrtni editaci kamene v seznamu vrstvy 3, stejně jako je
škrtnutý serializér:

```
     ~~editace kamene~~ (`superpowers/specs/2026-08-13-editace-kamene-design.md`) — hotovo,
     editace workflow
```

- [ ] **Step 10: Commit**

```bash
cd /home/honza/Dokumenty/Projekty/donut-org/donut
git add gui/src/Presentation/Block/BlockPresenter.php \
	gui/src/Presentation/Block/BlockDefaultTemplate.php \
	gui/src/Presentation/Block/BlockEditTemplate.php \
	gui/src/Presentation/Block/default.latte \
	gui/src/Presentation/Block/edit.latte \
	gui/tests/BlockPresenter.delete.phpt \
	docs/zadani.md
git commit -m "GUI: mazání kamene a výpis použití"
```
