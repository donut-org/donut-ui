# Obálka workflow — implementační plán

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Založit a smazat workflow a upravit jeho jméno, popis a vstupy.

**Architecture:** Nejdřív úklid, který si vyžádala závěrečná revize minulého
projektu — z `WorkflowPresenteru` (449 řádků, největší soubor v GUI) se vytáhne
strom kroků do Controlu a tři kusy logiky do vlastních jednotek. Pak obálka
sama, jako opis editace kamene včetně dvou lekcí, které tam stály Critical.

**Tech Stack:** PHP 8.3+, nette/application, nette/forms, latte, nette/tester,
PHPStan level max.

**Spec:** `docs/superpowers/specs/2026-08-15-obalka-workflow-design.md`

## Global Constraints

- **Všechno je v `gui/`.** Donut (kořen repozitáře) se nemění ani jedním
  řádkem. Testy a PHPStan se pouštějí **z `gui/`**; donutovu sadu spusť jednou
  na konci jako pojistku.
- Stav před začátkem: GUI 23 testů, donut 32 testů, PHPStan level max čistý
  u obojího.
- PHP 8.3+, **tabulátory**, `declare(strict_types=1);` v každém souboru, dvě
  prázdné řádky mezi metodami — přesně jako okolní kód v `gui/src/`.
- **Uživatelské texty a komentáře česky, kód a identifikátory anglicky.**
  V `gui/` je to dodržené bez výjimky — nepiš `$krok` ani `$cesta`.
- **Prázdný řetězec a „nevyplněno" jsou totéž** — sekce 6 specifikace formátu.
- **Validace neblokuje uložení workflow.** Ani u hlavičky. Žádná `hasErrors()`
  brána.
- **Žádná CSRF ochrana ani session**, žádné flash zprávy.
- **Žádný GET nesmí nic měnit.**
- **Inline `<script>` v Latte musí mít `n:syntax="off"`.**
- **GUI nesmí sahat do `../src` ani `../vendor` relativní cestou** pro kód.
- `git add` s konkrétními cestami, **nikdy** `git add -A` ani `git add .` —
  v pracovním stromu jsou čtyři nesledované položky
  (`.github/workflows/frontbot.yml`, `docs/logo.png`,
  `donut-org_donut.sublime-workspace`, `rss`), které do commitu nepatří.
- **Do `docs/workflows/` se nesmí zapisovat** — `git status --porcelain docs/workflows/`
  musí zůstat prázdný.

## Tři pasti PHPStanu, na které se v tomhle projektu opakovaně naráží

Kód níž je jich zbavený; kdybys psal vlastní, hlídej si je:

1. **`(string) $mixed` je `cast.string`.** Typicky u `$this->getParameter(…)`.
   Používej `is_string()` guard nebo pomocnou metodu s `is_scalar()`.
2. **`Donut\Format` typuje kolekce jako `array<int, Step>`, ne `list<Step>`.**
   Anotace `list<…>` neprojde; dej `array_values()` na hranici.
3. **`$form::Filled` neprojde, `Form::Filled` ano.**

A jedno o testech: `phpstan.neon` má `paths: [src, tests]`, ale PHPStan
analyzuje jen `.php` — **`.phpt` soubory neanalyzuje vůbec**.

## Struktura souborů

```
Úklid (Tasky 1–3, žádná změna chování):
gui/src/InputMapper.php                     vstupy ↔ hodnoty, sdílené
gui/src/RowShape.php                        počet řádků opakujícího se kontejneru
gui/src/Presentation/Workflow/StepTreeControl.php
gui/src/Presentation/Workflow/stepTree.latte
gui/tests/InputMapper.phpt
gui/tests/RowShape.phpt

Obálka (Tasky 4–5):
gui/src/WorkflowMapper.php                  hlavička ↔ hodnoty
gui/src/Presentation/Workflow/WorkflowEditTemplate.php
gui/src/Presentation/Workflow/edit.latte
gui/tests/WorkflowMapper.phpt
gui/tests/WorkflowPresenter.envelope.phpt
```

---

### Task 1: `InputMapper`

Vstupy kamene a vstupy workflow mají tentýž tvar. `BlockMapper::toInputs()` je
umí převádět, ale je privátní — vytáhne se, aby ho mohl volat i `WorkflowMapper`
z Tasku 4.

**Files:**
- Create: `gui/src/InputMapper.php`, `gui/tests/InputMapper.phpt`
- Modify: `gui/src/BlockMapper.php`

**Interfaces:**
- Produces, obojí statické:
  - `InputMapper::toInputs(mixed $raw): array<string, Input>`
  - `InputMapper::toValues(array<string, Input> $inputs): array<int, array<string, mixed>>`
- Tvar jednoho řádku: `['name' => string, 'required' => bool, 'default' => string, 'description' => string]`, kde `''` znamená nevyplněno.

**Beze změny chování.** `gui/tests/BlockMapper.phpt` a
`gui/tests/BlockPresenter.edit.phpt` musí projít **beze změny** — to je důkaz,
že extrakce nic nepřenesla. Kdybys je musel upravit, zastav a nahlas to.

- [ ] **Step 1: Napiš padající test**

Vytvoř `gui/tests/InputMapper.phpt`:

```php
<?php

declare(strict_types=1);

use Donut\Format\Input;
use Donut\Gui\InputMapper;
use Donut\Parser\BlockParser;
use Donut\Parser\WorkflowParser;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

// --- round-trip nad všemi vstupy referenční zátěže ---
//
// 57 vstupů: 36 u kamenů, 21 u workflow. Je to bohatší zátěž, než jakou
// mělo toInputs() uvnitř BlockMapperu k dispozici — teď zahrnuje i vstupy
// workflow.

$checked = 0;

$roundTrip = function (array $inputs) use (&$checked): void {
	if ($inputs === []) {
		return;
	}

	$again = InputMapper::toInputs(InputMapper::toValues($inputs));

	Assert::same(\serialize($inputs), \serialize($again));
	$checked += \count($inputs);
};

$blocks = \glob(__DIR__ . '/../../docs/workflows/donut/blocks/*.json');
Assert::count(15, $blocks === false ? [] : $blocks);

foreach ($blocks === false ? [] : $blocks as $file) {
	$roundTrip((new BlockParser)->parseFile($file)->inputs);
}

$workflows = \glob(__DIR__ . '/../../docs/workflows/donut/workflows/*.json');
Assert::count(4, $workflows === false ? [] : $workflows);

foreach ($workflows === false ? [] : $workflows as $file) {
	$roundTrip((new WorkflowParser)->parseFile($file)->inputs);
}

Assert::same(57, $checked, 'referenční zátěž má 57 vstupů');

// --- díry v indexech se srovnají, pořadí drží ksort ---
//
// JS řádky nikdy nepřečísluje a pořadí klíčů z POSTu není zaručené.

$reversed = InputMapper::toInputs([
	3 => ['name' => 'zet', 'required' => true, 'default' => '', 'description' => ''],
	0 => ['name' => 'alfa', 'required' => true, 'default' => '', 'description' => ''],
]);

Assert::same(['alfa', 'zet'], \array_keys($reversed));

// --- řádek bez jména je nedopsaný řádek, ne vstup ---

$sPrazdnym = InputMapper::toInputs([
	0 => ['name' => '', 'required' => true, 'default' => '', 'description' => 'nikdo'],
	1 => ['name' => 'kdo', 'required' => true, 'default' => '', 'description' => ''],
]);

Assert::same(['kdo'], \array_keys($sPrazdnym));

// --- '' znamená nevyplněno ---

Assert::null($sPrazdnym['kdo']->default);
Assert::null($sPrazdnym['kdo']->description);

// --- required se čte jako bool; nezaškrtnuté políčko se v POSTu neobjeví ---

$bez = InputMapper::toInputs([0 => ['name' => 'a']]);
Assert::false($bez['a']->required);

// --- toValues dává tvar, který formulář očekává ---

Assert::same(
	[
		['name' => 'url', 'required' => true, 'default' => '', 'description' => 'Adresa'],
		['name' => 'flag', 'required' => false, 'default' => 'x', 'description' => ''],
	],
	InputMapper::toValues([
		'url' => new Input(name: 'url', description: 'Adresa'),
		'flag' => new Input(name: 'flag', required: false, default: 'x'),
	]),
);

// Prázdná mapa dá prázdné pole, ne řádek s prázdnými hodnotami.
Assert::same([], InputMapper::toValues([]));
```

- [ ] **Step 2: Spusť test a ověř, že padá**

Run: `cd /home/honza/Dokumenty/Projekty/donut-org/donut/gui && vendor/bin/tester tests/InputMapper.phpt -C`
Expected: FAIL — `Donut\Gui\InputMapper` neexistuje.

- [ ] **Step 3: Napiš `InputMapper`**

Vytvoř `gui/src/InputMapper.php`. Těla přenes z `BlockMapper::toInputs()`
a z té části `BlockMapper::toValues()`, která staví `inputs`:

```php
<?php

declare(strict_types=1);

namespace Donut\Gui;

use Donut\Format\Input;


/**
 * Vstupy ↔ hodnoty formuláře.
 *
 * Vstupy kamene a vstupy workflow mají tentýž tvar, takže převod má jedno
 * místo — stejný důvod, proč v donutu existuje InputWriter pro serializér.
 *
 * Indexy řádků můžou mít díry a jejich pořadí z POSTu není zaručené: JS
 * řádky nikdy nepřečísluje. Srovnání je tady.
 */
final class InputMapper
{
	/**
	 * @param  mixed $raw řádky z formuláře
	 * @return array<string, Input>
	 */
	public static function toInputs(mixed $raw): array
	{
		if (!\is_array($raw)) {
			return [];
		}

		\ksort($raw);
		$inputs = [];

		foreach ($raw as $row) {
			if (!\is_array($row)) {
				continue;
			}

			$name = self::text($row['name'] ?? '');

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
	 * @param  array<string, Input> $inputs
	 * @return array<int, array<string, mixed>>
	 */
	public static function toValues(array $inputs): array
	{
		$rows = [];

		foreach ($inputs as $name => $input) {
			$rows[] = [
				'name' => $name,
				'required' => $input->required,
				'default' => $input->default ?? '',
				'description' => $input->description ?? '',
			];
		}

		return $rows;
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

- [ ] **Step 4: Nech `BlockMapper` volat nové místo**

V `gui/src/BlockMapper.php`:

1. `toBlock()`: nahraď `inputs: $this->toInputs($values['inputs'] ?? []),`
   za `inputs: InputMapper::toInputs($values['inputs'] ?? []),`
2. `toValues()`: nahraď smyčku, která staví `$inputs`, za
   `$inputs = InputMapper::toValues($block->inputs);`
3. Smaž privátní `toInputs()`.
4. **Žádný `use` nepřidávej** — `BlockMapper` i `InputMapper` jsou v `Donut\Gui`.
5. **`toStr()` ani `orNull()` v `BlockMapperu` nemaž** — používá je i `toBlock()`
   na jméno, příkaz a timeout. Ověř si to grepem, ať to platí i po tvé úpravě.

- [ ] **Step 5: Spusť test a ověř, že prochází**

Run: `cd /home/honza/Dokumenty/Projekty/donut-org/donut/gui && vendor/bin/tester tests/InputMapper.phpt tests/BlockMapper.phpt tests/BlockPresenter.edit.phpt -C`
Expected: PASS všechny tři.

`BlockMapper.phpt` a `BlockPresenter.edit.phpt` musí projít **beze změny.**

- [ ] **Step 6: Ověř mutací**

Zaveď postupně tyhle tři chyby a po každé spusť `tests/InputMapper.phpt`:

1. v `toInputs()` vynech `ksort()`
2. v `toInputs()` vynech kontrolu na prázdné jméno
3. v `toValues()` vypisuj `'default' => ''` natvrdo

Expected: každá shodí test.

Po každé mutaci ji vrať a nakonec ověř `git status --porcelain gui/src/`.
**Co některá mutace projde, napiš do reportu.**

- [ ] **Step 7: Spusť sadu GUI a PHPStan**

Run: `cd /home/honza/Dokumenty/Projekty/donut-org/donut/gui && vendor/bin/tester tests -C && vendor/bin/phpstan analyse`
Expected: 24 testů (bylo 23), PHPStan bez chyb.

- [ ] **Step 8: Commit**

```bash
cd /home/honza/Dokumenty/Projekty/donut-org/donut
git add gui/src/InputMapper.php gui/src/BlockMapper.php gui/tests/InputMapper.phpt
git commit -m "GUI: InputMapper — sdílený převod vstupů"
```

---

### Task 2: `RowShape` a přesun `keepChildren()`

Dva kusy, které v prezentéru nemají co dělat. `RowShape` bude potřebovat
i formulář hlavičky z Tasku 5; `keepChildren()` je znalost formátu a v
prezentéru se dá testovat jen přes HTTP-tvar testu.

**Files:**
- Create: `gui/src/RowShape.php`, `gui/tests/RowShape.phpt`
- Modify: `gui/src/StepMapper.php`, `gui/tests/StepMapper.phpt`, `gui/src/Presentation/Workflow/WorkflowPresenter.php`

**Interfaces:**
- Produces:
  - `RowShape::of(mixed $post, int $existing): array<int, int>` — indexy řádků kontejneru
  - `StepMapper::keepChildren(Step $original, Step $updated): Step` — **veřejná statická**

`$post` je podpole POSTu pro ten kontejner, nebo `null`, když tenhle POST
formuláři nepatří. **Rozhodnutí, jestli POST patří tomuhle formuláři, zůstává
na volajícím** — jen on zná jméno svého signálu.

**Beze změny chování.** `gui/tests/WorkflowPresenter.step.phpt` musí projít
**beze změny**.

- [ ] **Step 1: Napiš padající test na `RowShape`**

Vytvoř `gui/tests/RowShape.phpt`:

```php
<?php

declare(strict_types=1);

use Donut\Gui\RowShape;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

// --- bez POSTu: řádky podle načteného objektu, plus jeden prázdný navíc ---
//
// Ten prázdný je proto, aby měl uživatel kam psát i bez JS.

Assert::same([0], RowShape::of(null, 0));
Assert::same([0, 1], RowShape::of(null, 1));
Assert::same([0, 1, 2, 3], RowShape::of(null, 3));

// --- s POSTem: přesně ty klíče, které dorazily ---
//
// JS řádky nepřečísluje, takže v číslování můžou být díry a kontejnery musí
// vzniknout pro ně, ne pro souvislou řadu.

Assert::same([0, 2, 5], RowShape::of([0 => [], 2 => [], 5 => []], 99));

// Pořadí klíčů z POSTu není zaručené.
Assert::same([0, 1], RowShape::of([1 => [], 0 => []], 99));

// --- klíče se filtrují na číslice ---
//
// Jméno komponenty v Nette musí odpovídat [a-zA-Z0-9_]+ a nic jiného sem
// stejně nepatří.

Assert::same([1], RowShape::of([1 => [], 'x' => [], '../y' => []], 99));

// --- prázdný POST se chová jako žádný ---

Assert::same([0, 1], RowShape::of([], 1));

// --- co polem není, se chová jako žádný POST ---

Assert::same([0], RowShape::of('nesmysl', 0));
Assert::same([0], RowShape::of(null, 0));
```

- [ ] **Step 2: Spusť test a ověř, že padá**

Run: `cd /home/honza/Dokumenty/Projekty/donut-org/donut/gui && vendor/bin/tester tests/RowShape.phpt -C`
Expected: FAIL — `Donut\Gui\RowShape` neexistuje.

- [ ] **Step 3: Napiš `RowShape`**

Vytvoř `gui/src/RowShape.php`:

```php
<?php

declare(strict_types=1);

namespace Donut\Gui;


/**
 * Kolik řádků má opakující se kontejner formuláře a s jakými indexy.
 *
 * Při POSTu se odvodí z došlých dat — JS řádky nikdy nepřečísluje, takže
 * indexy můžou mít díry a kontejnery musí vzniknout přesně pro ty klíče,
 * které dorazily. Jinak se vezmou z načteného objektu, plus jeden prázdný
 * řádek navíc, aby měl uživatel kam psát i bez JS.
 *
 * Jestli POST patří zrovna tomuhle formuláři, rozhoduje volající — jen on
 * zná jméno svého signálu. Formulář sestavený z cizího POSTu by se vykreslil
 * prázdný, i když objekt za ním prázdný není.
 */
final class RowShape
{
	/**
	 * @param  mixed $post     podpole POSTu pro tenhle kontejner, nebo null
	 * @param  int   $existing kolik řádků má načtený objekt
	 * @return array<int, int>
	 */
	public static function of(mixed $post, int $existing): array
	{
		if (\is_array($post) && $post !== []) {
			$keys = [];

			foreach (\array_keys($post) as $key) {
				// Jméno komponenty v Nette musí odpovídat [a-zA-Z0-9_]+.
				if (\ctype_digit((string) $key)) {
					$keys[] = (int) $key;
				}
			}

			\sort($keys);

			return $keys;
		}

		return \range(0, $existing);
	}
}
```

- [ ] **Step 4: Spusť test a ověř, že prochází**

Run: `cd /home/honza/Dokumenty/Projekty/donut-org/donut/gui && vendor/bin/tester tests/RowShape.phpt -C`
Expected: PASS.

- [ ] **Step 5: Přesuň `keepChildren()` do `StepMapperu`**

Přenes metodu z `WorkflowPresenter` do `gui/src/StepMapper.php` **doslova**,
jen ji udělej veřejnou a doplň docblock:

```php
	/**
	 * Nový krok z formuláře nese prázdné větve, protože je formulář needituje.
	 * Při nahrazení existujícího kroku se proto musí převzít z původního —
	 * jinak by úprava podmínky smazala celý podstrom.
	 *
	 * Když se typ neshoduje, vrací se nový krok beze změny: přenášet větve
	 * mezi různými typy nedává smysl a přes formulář se typ změnit nedá.
	 */
	public static function keepChildren(Step $original, Step $updated): Step
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

V `WorkflowPresenter::stepFormSucceeded()` nahraď `self::keepChildren(…)`
za `StepMapper::keepChildren(…)` a privátní metodu smaž.

- [ ] **Step 6: Doplň přímý test na `keepChildren()`**

Dnes jde vyzkoušet jen přes HTTP-tvar testu. Po přesunu je to čistá funkce —
závěrečná revize minulého projektu na to výslovně ukázala.

Přidej na konec `gui/tests/StepMapper.phpt`:

```php
// --- keepChildren: úprava nesmí smazat podstrom ---

$vetve = new IfStep(
	condition: new Condition(left: Template::parse('{%a%}'), op: 'not_empty'),
	then: [new SetStep(key: 't', value: Template::parse('1'))],
	else: [new SetStep(key: 'e', value: Template::parse('2'))],
	name: 'původní',
);

$upraveny = StepMapper::keepChildren(
	$vetve,
	new IfStep(
		condition: new Condition(left: Template::parse('{%b%}'), op: 'empty'),
		name: 'nový',
	),
);

Assert::type(IfStep::class, $upraveny);
Assert::same('{%b%}', $upraveny->condition->left->getSource(), 'podmínka se má převzít z nového');
Assert::same('nový', $upraveny->name);
Assert::count(1, $upraveny->then, 'větev then se nesmí ztratit');
Assert::count(1, $upraveny->else, 'větev else se nesmí ztratit');
Assert::same('t', $upraveny->then[0]->key);

// Totéž pro foreach.
$telo = new ForeachStep(
	over: Template::parse('{%x%}'),
	as: 'a',
	steps: [new SetStep(key: 's', value: Template::parse('1'))],
);

$upravenyForeach = StepMapper::keepChildren(
	$telo,
	new ForeachStep(over: Template::parse('{%y%}'), as: 'b'),
);

Assert::same('{%y%}', $upravenyForeach->over->getSource());
Assert::same('b', $upravenyForeach->as);
Assert::count(1, $upravenyForeach->steps, 'tělo foreach se nesmí ztratit');

// Neshodný typ: nový krok se vrátí beze změny, větve se nepřenášejí.
$jiny = StepMapper::keepChildren($vetve, new SetStep(key: 'k', value: Template::parse('1')));
Assert::type(SetStep::class, $jiny);
```

Doplň nahoře `use` na `Donut\Format\Condition`, `IfStep`, `ForeachStep`,
`SetStep` a `Donut\Template`, pokud tam ještě nejsou.

- [ ] **Step 7: Nech prezentér volat `RowShape`**

V `WorkflowPresenter::rowShape()` nahraď tělo tak, aby počítání delegovalo,
a gate na vlastní signál zůstal tady:

```php
	/**
	 * @return array{in: array<int, int>, out: array<int, int>}
	 */
	private function rowShape(): array
	{
		// Jiný signál nenese in/out vůbec — bez téhle podmínky by se formulář
		// sestavil s nula řádky a stránka by ukázala prázdný krok, který ve
		// skutečnosti prázdný není.
		$post = $this->getParameter('do') === 'stepForm-submit'
			? $this->getHttpRequest()->getPost()
			: null;

		$in = \is_array($post) ? ($post['in'] ?? null) : null;
		$out = \is_array($post) ? ($post['out'] ?? null) : null;

		$step = $this->editedStep;

		return [
			'in' => RowShape::of($in, $step instanceof RunStep ? \count($step->in) : 0),
			'out' => RowShape::of($out, $step instanceof RunStep ? \count($step->out) : 0),
		];
	}
```

Smaž privátní `rowIndexes()` a doplň `use Donut\Gui\RowShape;`.

- [ ] **Step 8: Spusť dotčené testy**

Run: `cd /home/honza/Dokumenty/Projekty/donut-org/donut/gui && vendor/bin/tester tests/RowShape.phpt tests/StepMapper.phpt tests/WorkflowPresenter.step.phpt -C`
Expected: PASS všechny tři.

`WorkflowPresenter.step.phpt` musí projít **beze změny** — je to důkaz, že
přesun nic nepřenesl. Kdybys ho musel upravit, zastav a nahlas to.

- [ ] **Step 9: Ověř mutací**

Zaveď postupně tyhle tři chyby a po každé spusť dotčený test:

1. v `RowShape::of()` vrať `range(0, $existing - 1)` (tedy bez prázdného řádku navíc)
2. v `RowShape::of()` vynech `sort()`
3. v `StepMapper::keepChildren()` u `ForeachStep` předej `$updated->steps` místo `$original->steps`

Expected: 1 a 2 shodí `RowShape.phpt`, 3 shodí `StepMapper.phpt`.

Po každé mutaci ji vrať. **Co některá mutace projde, napiš do reportu.**

- [ ] **Step 10: Spusť sadu GUI a PHPStan**

Run: `cd /home/honza/Dokumenty/Projekty/donut-org/donut/gui && vendor/bin/tester tests -C && vendor/bin/phpstan analyse`
Expected: 25 testů (bylo 24), PHPStan bez chyb.

- [ ] **Step 11: Commit**

```bash
cd /home/honza/Dokumenty/Projekty/donut-org/donut
git add gui/src/RowShape.php gui/src/StepMapper.php \
	gui/src/Presentation/Workflow/WorkflowPresenter.php \
	gui/tests/RowShape.phpt gui/tests/StepMapper.phpt
git commit -m "GUI: RowShape a keepChildren ven z prezentéru"
```

---

### Task 3: `StepTreeControl`

Největší kus úklidu. Strom kroků má vlastní šablonu, vlastní tři signály
a vlastní stav — jako komponenta si je odnese s sebou.

**Files:**
- Create: `gui/src/Presentation/Workflow/StepTreeControl.php`, `gui/src/Presentation/Workflow/stepTree.latte`
- Modify: `gui/src/Presentation/Workflow/WorkflowPresenter.php`, `gui/src/Presentation/Workflow/detail.latte`, `gui/src/Presentation/Workflow/WorkflowDetailTemplate.php`, `gui/tests/WorkflowPresenter.controls.phpt`

**Interfaces:**
- Consumes: `StepPath::parse()`, `StepTree::moveUp/moveDown/remove`, `WorkflowStore::save()`, `WorkflowRepository::get()`.
- Produces: komponenta `stepTree` na `Workflow:detail`; signály `stepTree-moveUp`, `stepTree-moveDown`, `stepTree-deleteStep`.

**Adresy signálů se změní** z `?do=moveUp` na `?do=stepTree-moveUp`. Skládá je
`{link}`, takže navenek se nic nerozbije, ale
`gui/tests/WorkflowPresenter.controls.phpt` se musí upravit — **v řetězcích,
ne v tvrzeních**. Kdyby bylo potřeba změnit, *co* test tvrdí, zastav a nahlas to.

- [ ] **Step 1: Napiš `StepTreeControl`**

Vytvoř `gui/src/Presentation/Workflow/StepTreeControl.php`. Těla signálů
a `applyToStep()` přenes z `WorkflowPresenteru` doslova; liší se jen tím, že
jméno workflow a adresář má komponenta z konstruktoru, ne z parametrů adresy.

```php
<?php

declare(strict_types=1);

namespace Donut\Gui\Presentation\Workflow;

use Donut\Format\Workflow;
use Donut\Gui\KeyMap;
use Donut\Gui\ProblemMap;
use Donut\Gui\StepPath;
use Donut\Gui\StepTree;
use Donut\Gui\WorkflowRepository;
use Donut\Gui\WorkflowStore;
use Donut\Parser\ParseException;
use Donut\Writer\WriteException;
use Nette\Application\Attributes\Requires;
use Nette\Application\UI\Control;
use Nette\IOException;


/**
 * Strom kroků workflow i s ovládáním.
 *
 * Jako komponenta proto, že má vlastní šablonu, vlastní signály a vlastní
 * stav. Prezentéru tím zůstane seznam, detail a formuláře.
 *
 * Jméno workflow a adresář dostává z konstruktoru, ne z adresy: signál běží
 * dřív než render, takže se na parametry akce spolehnout nedá, a jméno má
 * takhle jediné místo, kde se ověřuje.
 */
final class StepTreeControl extends Control
{
	private ?string $error = null;


	public function __construct(
		private readonly string $directory,
		private readonly string $name,
	) {
	}


	public function render(
		Workflow $workflow,
		ProblemMap $problems,
		KeyMap $keys,
		?string $selected,
	): void {
		$this->template->setFile(__DIR__ . '/stepTree.latte');
		$this->template->steps = $workflow->steps;
		$this->template->path = StepPath::root($workflow->name);
		$this->template->problems = $problems;
		$this->template->keys = $keys;
		$this->template->selected = $selected;
		$this->template->name = $this->name;
		$this->template->error = $this->error;
		$this->template->render();
	}


	#[Requires(methods: 'POST')]
	public function handleMoveUp(): void
	{
		$this->applyToStep(fn(Workflow $w, StepPath $at): Workflow => StepTree::moveUp($w, $at));
	}


	#[Requires(methods: 'POST')]
	public function handleMoveDown(): void
	{
		$this->applyToStep(fn(Workflow $w, StepPath $at): Workflow => StepTree::moveDown($w, $at));
	}


	#[Requires(methods: 'POST')]
	public function handleDeleteStep(): void
	{
		$this->applyToStep(fn(Workflow $w, StepPath $at): Workflow => StepTree::remove($w, $at));
	}


	/**
	 * Vzít cestu z POSTu, načíst workflow, provést operaci, uložit, vrátit se
	 * na přehled.
	 *
	 * Validace se **nespouští** — u workflow neblokuje, protože mezistavy
	 * přerovnávání jsou skoro vždycky neplatné. Problémy se ukážou v přehledu,
	 * kam se vzápětí vracíme.
	 *
	 * Při chybě se **nepřesměrovává**: redirect() hodí AbortException a chyba
	 * by se nikam nedostala. Flash zprávy k dispozici nejsou (žádná session),
	 * takže se nechá doběhnout render, který $error vykreslí.
	 *
	 * @param callable(Workflow, StepPath): Workflow $operation
	 */
	private function applyToStep(callable $operation): void
	{
		$raw = $this->getPresenter()->getHttpRequest()->getPost('at');

		try {
			$at = StepPath::parse(\is_string($raw) ? $raw : '');

			// Cesta nese jméno workflow; kdyby nesouhlasilo s tím, nad kterým
			// komponenta stojí, operace by sáhla do cizího souboru.
			if ($at->workflowName() !== $this->name) {
				throw new \InvalidArgumentException(
					"Cesta \"{$at}\" nepatří workflow \"{$this->name}\"."
				);
			}

			$workflow = (new WorkflowRepository($this->directory))->get($this->name);
			(new WorkflowStore($this->directory))->save($operation($workflow, $at));

		} catch (\InvalidArgumentException | \OutOfRangeException | ParseException | WriteException | IOException $e) {
			$this->error = $e->getMessage();

			return;
		}

		$this->getPresenter()->redirect('detail', ['name' => $this->name]);
	}
}
```

- [ ] **Step 2: Napiš šablonu komponenty**

Vytvoř `gui/src/Presentation/Workflow/stepTree.latte`. Je tenká schválně —
rekurzivní `{define steps}` zůstává ve `steps.latte` **beze změny**:

```latte
{import 'steps.latte'}

<p n:if="$error" class=error>{$error}</p>

{include steps, steps: $steps, path: $path, problems: $problems, keys: $keys, selected: $selected, name: $name}
```

- [ ] **Step 3: Zapoj komponentu do prezentéru**

Do `WorkflowPresenter`:

```php
	protected function createComponentStepTree(): StepTreeControl
	{
		$raw = $this->getParameter('name');

		return new StepTreeControl(
			WorkflowRepository::projectDir() . '/workflows',
			// basename() stejně jako v renderDetail(): jméno je z query
			// stringu a tohle je jediné místo, kde ho komponenta dostane.
			\basename(\is_string($raw) ? $raw : ''),
		);
	}
```

Pak z prezentéru **smaž**: `handleMoveUp()`, `handleMoveDown()`,
`handleDeleteStep()`, `applyToStep()`, vlastnost `$stepError` a řádek
`$template->stepError = $this->stepError;` v `renderDetail()`. Z
`WorkflowDetailTemplate` smaž `public ?string $stepError`. Ze `use` odeber, co
zbude nepoužité — **zkontroluj grepem, nemaž naslepo.**

- [ ] **Step 4: Přepni `detail.latte` na komponentu**

V `gui/src/Presentation/Workflow/detail.latte`:

1. smaž řádek `<p n:if="$stepError" class=error>{$stepError}</p>`
2. nahraď `{include steps, …}` za
   `{control stepTree, $workflow, $problems, $keys, $selectedKey}`

`{import 'steps.latte'}` na začátku **nech** — `detail.latte` používá
`{define problem}` pro problémy celého workflow.

- [ ] **Step 5: Uprav řetězce v testu ovládání**

V `gui/tests/WorkflowPresenter.controls.phpt` změň:

- v asercích nad HTML `do=moveUp` → `do=stepTree-moveUp`, `do=moveDown` →
  `do=stepTree-moveDown`
- v parametrech POSTu `'do' => 'moveUp'` → `'do' => 'stepTree-moveUp'` a
  stejně pro `moveDown` a `deleteStep`
- v aserci `Assert::notContains('<a href="?do=deleteStep', …)` → totéž
  s `stepTree-deleteStep`

**Nic jiného v tom souboru neměň.** Počty, tvrzení o pořadí kroků, o tom, že
se soubor neuložil při neplatné cestě, i o tom, že se prázdné větve vykreslí,
zůstávají slovo od slova.

- [ ] **Step 6: Spusť dotčené testy**

Run: `cd /home/honza/Dokumenty/Projekty/donut-org/donut/gui && vendor/bin/tester tests/WorkflowPresenter.controls.phpt tests/WorkflowPresenter.detailRender.phpt tests/Latte.TemplatesCompile.phpt -C`
Expected: PASS všechny tři.

`WorkflowPresenter.detailRender.phpt` musí projít **beze změny** — vykresluje
detail a je to důkaz, že komponenta vykreslí totéž co dřív include.

- [ ] **Step 7: Vyzkoušej to v prohlížeči**

```bash
cd /home/honza/Dokumenty/Projekty/donut-org/donut/docs/workflows/donut
php -S 127.0.0.1:8000 -t . /home/honza/Dokumenty/Projekty/donut-org/donut/gui/www/index.php
```

Na `?presenter=Workflow&action=detail&name=repo-check` ověř, že přesun nahoru
i dolů pořád funguje a že v adrese formuláře je `do=stepTree-moveUp`.

**Pak vrať zátěž do původního stavu** (`git checkout -- docs/workflows/`)
a ověř, že `git status --porcelain docs/workflows/` je prázdný. Zapiš do
reportu, co jsi viděl.

- [ ] **Step 8: Spusť sadu GUI a PHPStan**

Run: `cd /home/honza/Dokumenty/Projekty/donut-org/donut/gui && vendor/bin/tester tests -C && vendor/bin/phpstan analyse`
Expected: 25 testů (beze změny — nepřibyl testovací soubor), PHPStan bez chyb.

- [ ] **Step 9: Commit**

```bash
cd /home/honza/Dokumenty/Projekty/donut-org/donut
git add gui/src/Presentation/Workflow/StepTreeControl.php \
	gui/src/Presentation/Workflow/stepTree.latte \
	gui/src/Presentation/Workflow/WorkflowPresenter.php \
	gui/src/Presentation/Workflow/WorkflowDetailTemplate.php \
	gui/src/Presentation/Workflow/detail.latte \
	gui/tests/WorkflowPresenter.controls.phpt
git commit -m "GUI: strom kroků jako komponenta"
```

---

### Task 4: `WorkflowMapper` a doplnění `WorkflowStore`

**Files:**
- Create: `gui/src/WorkflowMapper.php`, `gui/tests/WorkflowMapper.phpt`
- Modify: `gui/src/WorkflowStore.php`, `gui/tests/WorkflowStore.phpt`

**Interfaces:**
- Consumes: `InputMapper::toInputs()` / `toValues()` z Tasku 1.
- Produces:
  - `WorkflowMapper::toWorkflow(array $values, Workflow $original = null): Workflow`
  - `WorkflowMapper::toValues(Workflow $workflow): array`
  - `WorkflowStore::exists(string $name): bool`
  - `WorkflowStore::delete(string $name): void` — hodí `ParseException`, když workflow neexistuje

**Tvar hodnot** — kontrakt s formulářem z Tasku 5:

```php
[
    'name' => 'card-dev',
    'description' => '',            // '' = nevyplněno
    'inputs' => [                   // indexy můžou mít díry
        0 => ['name' => 'repo', 'required' => true, 'default' => '', 'description' => 'Repozitář'],
    ],
]
```

**Kroky se nepřevádějí ani jedním směrem.** Formulář je needituje, takže
`toWorkflow()` je bere z `$original` — jinak by úprava hlavičky smazala celý
strom. Je to táž past, kterou u kroku řeší `keepChildren()`, jen ničivější.

- [ ] **Step 1: Napiš padající test**

Vytvoř `gui/tests/WorkflowMapper.phpt`:

```php
<?php

declare(strict_types=1);

use Donut\Format\Input;
use Donut\Format\SetStep;
use Donut\Format\Workflow;
use Donut\Gui\WorkflowMapper;
use Donut\Parser\WorkflowParser;
use Donut\Template;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

// --- round-trip nad referenční zátěží, na hlavičce a vstupech ---
//
// Kroky formulář needituje, takže se do porovnání neberou — toWorkflow()
// je dostane z původního workflow.

$files = \glob(__DIR__ . '/../../docs/workflows/donut/workflows/*.json');
Assert::count(4, $files === false ? [] : $files);

foreach ($files === false ? [] : $files as $file) {
	$original = (new WorkflowParser)->parseFile($file);
	$again = WorkflowMapper::toWorkflow(WorkflowMapper::toValues($original), $original);

	Assert::same($original->name, $again->name, \basename($file));
	Assert::same($original->description, $again->description, \basename($file));
	Assert::same(\serialize($original->inputs), \serialize($again->inputs), \basename($file));
}

// --- kroky se převezmou z původního workflow, ne z hodnot ---
//
// Tohle je ta past: bez $original by úprava hlavičky smazala celý strom.

$sKroky = new Workflow(
	name: 'w',
	steps: [new SetStep(key: 'a', value: Template::parse('1'))],
	description: 'Původní popis',
);

$poUprave = WorkflowMapper::toWorkflow(
	['name' => 'w', 'description' => 'Nový popis', 'inputs' => []],
	$sKroky,
);

Assert::same('Nový popis', $poUprave->description);
Assert::count(1, $poUprave->steps, 'kroky se úpravou hlavičky nesmí ztratit');
Assert::same('a', $poUprave->steps[0]->key);

// --- bez původního workflow (zakládání) vzniká prázdné ---

$nove = WorkflowMapper::toWorkflow(['name' => 'nove', 'description' => '', 'inputs' => []]);

Assert::same('nove', $nove->name);
Assert::null($nove->description);
Assert::same([], $nove->steps);
Assert::same([], $nove->inputs);

// --- vstupy jdou přes InputMapper: díry a prázdné řádky ---

$sVstupy = WorkflowMapper::toWorkflow([
	'name' => 'w',
	'description' => '',
	'inputs' => [
		3 => ['name' => 'zet', 'required' => true, 'default' => '', 'description' => ''],
		0 => ['name' => 'alfa', 'required' => false, 'default' => 'x', 'description' => 'Áčko'],
		1 => ['name' => '', 'required' => true, 'default' => '', 'description' => 'nikdo'],
	],
]);

Assert::same(['alfa', 'zet'], \array_keys($sVstupy->inputs));
Assert::false($sVstupy->inputs['alfa']->required);
Assert::same('x', $sVstupy->inputs['alfa']->default);

// --- toValues dává tvar, který formulář očekává ---

$values = WorkflowMapper::toValues(new Workflow(
	name: 'plne',
	inputs: ['a' => new Input(name: 'a', description: 'Áčko')],
	steps: [new SetStep(key: 'x', value: Template::parse('1'))],
	description: 'Popis',
));

Assert::same('plne', $values['name']);
Assert::same('Popis', $values['description']);
Assert::same(
	[['name' => 'a', 'required' => true, 'default' => '', 'description' => 'Áčko']],
	$values['inputs'],
);
Assert::false(\array_key_exists('steps', $values), 'kroky do formuláře nepatří');

// Nevyplněný popis vyjde jako '', ne jako null — formulář chce řetězce.
Assert::same('', WorkflowMapper::toValues(new Workflow(name: 'holy'))['description']);
```

- [ ] **Step 2: Spusť test a ověř, že padá**

Run: `cd /home/honza/Dokumenty/Projekty/donut-org/donut/gui && vendor/bin/tester tests/WorkflowMapper.phpt -C`
Expected: FAIL — `Donut\Gui\WorkflowMapper` neexistuje.

- [ ] **Step 3: Napiš `WorkflowMapper`**

Vytvoř `gui/src/WorkflowMapper.php`:

```php
<?php

declare(strict_types=1);

namespace Donut\Gui;

use Donut\Format\Workflow;


/**
 * Hlavička workflow ↔ hodnoty formuláře. Jméno, popis, vstupy.
 *
 * **Kroky se nepřevádějí ani jedním směrem.** Formulář hlavičky je needituje,
 * takže toWorkflow() je bere z původního workflow — jinak by úprava popisu
 * smazala celý strom kroků. Je to táž past, kterou u kroku řeší
 * StepMapper::keepChildren(), jen ničivější.
 */
final class WorkflowMapper
{
	/**
	 * @param array<string, mixed> $values
	 * @param Workflow|null        $original při úpravě; při zakládání null
	 */
	public static function toWorkflow(array $values, ?Workflow $original = null): Workflow
	{
		return new Workflow(
			name: self::text($values['name'] ?? ''),
			inputs: InputMapper::toInputs($values['inputs'] ?? []),
			steps: $original?->steps ?? [],
			description: self::orNull($values['description'] ?? ''),
		);
	}


	/**
	 * @return array<string, mixed>
	 */
	public static function toValues(Workflow $workflow): array
	{
		return [
			'name' => $workflow->name,
			'description' => $workflow->description ?? '',
			'inputs' => InputMapper::toValues($workflow->inputs),
		];
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

- [ ] **Step 4: Doplň `WorkflowStore`**

Do `gui/src/WorkflowStore.php` přidej. Vzorem je `gui/src/BlockStore.php` —
přečti si, jak řeší totéž pro kameny.

```php
	public function exists(string $name): bool
	{
		return \is_file($this->path($name));
	}


	/**
	 * @throws ParseException když workflow neexistuje
	 */
	public function delete(string $name): void
	{
		if (!$this->exists($name)) {
			throw new ParseException("Workflow \"{$name}\" neexistuje. Hledal jsem v: {$this->directory}");
		}

		FileSystem::delete($this->path($name));
	}
```

Doplň `use Nette\Utils\FileSystem;`.

**Pozn.:** `BlockStore::exists()` se ptá repository, protože ji stejně drží
kvůli čtení. `WorkflowStore` repository nemá — čtení dělá
`WorkflowRepository` u volajícího — takže se ptá souboru přímo. Je to méně
kódu a odpověď je stejná.

- [ ] **Step 5: Doplň testy `WorkflowStore`**

Přidej na konec `gui/tests/WorkflowStore.phpt`, **před** závěrečné
`FileSystem::delete(TEMP_DIR);`:

```php
// --- exists a delete ---

Assert::true($store->exists('w'));
Assert::false($store->exists('neni'));

$store->delete('w');

Assert::false(\is_file($dir . '/w.json'));
Assert::false($store->exists('w'));

// Smazání neexistujícího je chyba, ne ticho — jinak by GUI hlásilo úspěch
// nad něčím, co se nestalo.
Assert::exception(fn() => $store->delete('neni'), ParseException::class);
```

- [ ] **Step 6: Spusť testy a ověř, že prochází**

Run: `cd /home/honza/Dokumenty/Projekty/donut-org/donut/gui && vendor/bin/tester tests/WorkflowMapper.phpt tests/WorkflowStore.phpt -C`
Expected: PASS obojí.

- [ ] **Step 7: Ověř mutací**

Zaveď postupně tyhle tři chyby a po každé spusť dotčený test:

1. v `toWorkflow()` použij `steps: []` natvrdo místo `$original?->steps ?? []`
2. v `toValues()` vrať `'description' => $workflow->description` bez `?? ''`
3. v `delete()` vynech kontrolu existence

Expected: 1 a 2 shodí `WorkflowMapper.phpt`, 3 shodí `WorkflowStore.phpt`.

Mutace 1 je ta důležitá — je to ztráta celého stromu kroků.

Po každé mutaci ji vrať. **Co některá mutace projde, napiš do reportu.**

- [ ] **Step 8: Spusť sadu GUI a PHPStan**

Run: `cd /home/honza/Dokumenty/Projekty/donut-org/donut/gui && vendor/bin/tester tests -C && vendor/bin/phpstan analyse`
Expected: 26 testů (bylo 25), PHPStan bez chyb.

- [ ] **Step 9: Commit**

```bash
cd /home/honza/Dokumenty/Projekty/donut-org/donut
git add gui/src/WorkflowMapper.php gui/src/WorkflowStore.php \
	gui/tests/WorkflowMapper.phpt gui/tests/WorkflowStore.phpt
git commit -m "GUI: WorkflowMapper a doplnění WorkflowStore"
```

---

### Task 5: Formulář hlavičky — zakládání, úprava, mazání

Poslední task celé vrstvy 3.

**Files:**
- Create: `gui/src/Presentation/Workflow/WorkflowEditTemplate.php`, `gui/src/Presentation/Workflow/edit.latte`
- Modify: `gui/src/Presentation/Workflow/WorkflowPresenter.php`, `gui/src/Presentation/Workflow/default.latte`, `gui/src/Presentation/Workflow/detail.latte`
- Create: `gui/tests/WorkflowPresenter.envelope.phpt`

**Interfaces:**
- Consumes: `WorkflowMapper::toWorkflow()/toValues()`, `WorkflowStore::exists()/delete()/save()` (Task 4), `RowShape::of()` (Task 2), `InputMapper` nepřímo přes mapper.
- Produces: akce `Workflow:edit` s volitelným `name`; formuláře `headerForm` a `deleteWorkflowForm`.

**Dvě lekce z editace kamene, které tam stály Critical a Important:**

1. **Zakládání nesmí přepsat existující workflow.** `WorkflowWriter` přepisuje
   bez ptaní; kontrola `exists()` musí stát před uložením, jinak vypadá
   zničení souboru jako úspěch.
2. **Jméno je editovatelné jen při zakládání.** Při úpravě je pole vyplněné,
   zakázané a `setOmitted(false)`. **Pořadí volání je závazné:**
   `setDisabled()` maže hodnotu, takže musí předcházet `setDefaultValue()`,
   a bez `setOmitted(false)` se zakázané pole z `getValues()` tiše vynechá.

- [ ] **Step 1: Napiš padající test**

Vytvoř `gui/tests/WorkflowPresenter.envelope.phpt`:

```php
<?php

declare(strict_types=1);

use Donut\Parser\WorkflowParser;
use Nette\Application\Responses\RedirectResponse;
use Nette\Utils\FileSystem;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/inc/workflowPresenter.php';

$project = TEMP_DIR . '/envelope';
FileSystem::createDir($project . '/blocks');
FileSystem::createDir($project . '/workflows');

FileSystem::write($project . '/workflows/w.json', json_encode([
	'name' => 'w',
	'description' => 'Popis',
	'inputs' => ['repo' => ['required' => true, 'description' => 'Repozitář']],
	'steps' => [['type' => 'set', 'key' => 'a', 'value' => '1']],
]));

$load = fn(string $name) => (new WorkflowParser)->parseFile($project . "/workflows/{$name}.json");

// --- úprava: formulář je předvyplněný ---

[, $html] = runWorkflowPresenterIn($project, ['action' => 'edit', 'name' => 'w']);

Assert::contains('value="w"', $html);
Assert::contains('Popis', $html);
Assert::contains('repo', $html);

// --- zakládání: prázdný formulář, žádný pád ---

[, $novy] = runWorkflowPresenterIn($project, ['action' => 'edit']);

Assert::contains('<form', $novy);
Assert::notContains('Repozitář', $novy);

// --- zakládání uloží prázdné workflow ---

[$response] = runWorkflowPresenterIn(
	$project,
	['action' => 'edit', 'do' => 'headerForm-submit'],
	[
		'name' => 'nove',
		'description' => 'Nové',
		'inputs' => [0 => ['name' => 'x', 'required' => '1', 'default' => '', 'description' => '']],
		'save' => 'Uložit',
	],
);

Assert::type(RedirectResponse::class, $response);

$nove = $load('nove');
Assert::same('nove', $nove->name);
Assert::same('Nové', $nove->description);
Assert::same(['x'], array_keys($nove->inputs));
Assert::same([], $nove->steps, 'nové workflow vzniká prázdné');

// --- zakládání přes existující jméno NEPŘEPÍŠE ---
//
// Lekce z editace kamene, kde to byl Critical: writeFile() přepisuje bez
// ptaní a přesměrování vypadá jako úspěch.

$before = FileSystem::read($project . '/workflows/w.json');

[$response, $html] = runWorkflowPresenterIn(
	$project,
	['action' => 'edit', 'do' => 'headerForm-submit'],
	['name' => 'w', 'description' => 'Přepis', 'inputs' => [], 'save' => 'Uložit'],
);

Assert::false($response instanceof RedirectResponse, 'přepis se nesmí tvářit jako úspěch');
Assert::contains('existuje', $html);
Assert::same($before, FileSystem::read($project . '/workflows/w.json'), 'původní soubor musí zůstat bajt po bajtu stejný');

// --- úprava nesmí ztratit kroky ---

runWorkflowPresenterIn(
	$project,
	['action' => 'edit', 'name' => 'w', 'do' => 'headerForm-submit'],
	['name' => 'w', 'description' => 'Jiný popis', 'inputs' => [], 'save' => 'Uložit'],
);

$upravene = $load('w');
Assert::same('Jiný popis', $upravene->description);
Assert::count(1, $upravene->steps, 'kroky se úpravou hlavičky nesmí ztratit');

// --- odeslání změněného jména při úpravě zapíše původní ---
//
// Lekce z editace kamene, kde to byl Important: „přejmenování" jinak objekt
// rozdvojí.

runWorkflowPresenterIn(
	$project,
	['action' => 'edit', 'name' => 'w', 'do' => 'headerForm-submit'],
	['name' => 'prejmenovane', 'description' => 'X', 'inputs' => [], 'save' => 'Uložit'],
);

Assert::true(\is_file($project . '/workflows/w.json'));
Assert::false(\is_file($project . '/workflows/prejmenovane.json'), 'nesmí vzniknout druhý soubor');

// --- mazání ---

[$response] = runWorkflowPresenterIn(
	$project,
	['action' => 'edit', 'name' => 'nove', 'do' => 'deleteWorkflowForm-submit'],
	['save' => 'Smazat'],
);

Assert::type(RedirectResponse::class, $response);
Assert::false(\is_file($project . '/workflows/nove.json'));

// --- mazání se nenabízí u zakládání ---

[, $novy] = runWorkflowPresenterIn($project, ['action' => 'edit']);
Assert::notContains('Smazat', $novy);

// --- seznam nabízí zakládání ---

[, $seznam] = runWorkflowPresenterIn($project, ['action' => 'default']);
Assert::contains('nové workflow', $seznam);

FileSystem::delete(TEMP_DIR);
```

- [ ] **Step 2: Spusť test a ověř, že padá**

Run: `cd /home/honza/Dokumenty/Projekty/donut-org/donut/gui && vendor/bin/tester tests/WorkflowPresenter.envelope.phpt -C`
Expected: FAIL — akce `edit` neexistuje.

- [ ] **Step 3: Napiš šablonovou třídu**

Vytvoř `gui/src/Presentation/Workflow/WorkflowEditTemplate.php`:

```php
<?php

declare(strict_types=1);

namespace Donut\Gui\Presentation\Workflow;

use Nette\Bridges\ApplicationLatte\Template;


final class WorkflowEditTemplate extends Template
{
	public ?string $name = null;

	public ?string $error = null;
}
```

- [ ] **Step 4: Rozšiř prezentér**

Vzorem je `gui/src/Presentation/Block/BlockPresenter.php` — **přečti si
`actionEdit()`, `createComponentBlockForm()`, `blockFormSucceeded()`
a `createComponentDeleteForm()`**, tohle je jejich obdoba pro workflow.

```php
	private ?Workflow $editedWorkflow = null;


	public function actionEdit(?string $name = null): void
	{
		if ($name === null || $name === '') {
			return;
		}

		/** @var WorkflowEditTemplate $template */
		$template = $this->template;

		try {
			$this->editedWorkflow = (new WorkflowRepository($this->workflowDir()))->get(\basename($name));

		} catch (ParseException $e) {
			$template->error = $e->getMessage();
		}
	}


	public function renderEdit(?string $name = null): void
	{
		/** @var WorkflowEditTemplate $template */
		$template = $this->template;
		$template->name = ($name ?? '') === '' ? null : \basename((string) $name);
	}


	protected function createComponentHeaderForm(): Form
	{
		$form = new Form;

		$nameInput = $form->addText('name', 'Jméno')
			->setRequired('Jméno je povinné.')
			->addRule(Form::Pattern, 'Jméno smí obsahovat jen písmena, číslice, pomlčku a podtržítko.', '[A-Za-z0-9_-]+');

		if ($this->editedWorkflow !== null) {
			// Přejmenování GUI neumí — workflow se spouští jménem z cronu
			// a z CLI. Pořadí je závazné: setDisabled() maže hodnotu, takže
			// musí předcházet setDefaultValue(), a bez setOmitted(false) by
			// se zakázané pole z getValues() tiše vynechalo.
			$nameInput->setDisabled()
				->setDefaultValue($this->editedWorkflow->name)
				->setOmitted(false);
		}

		$form->addText('description', 'Popis');

		$post = $this->getParameter('do') === 'headerForm-submit'
			? $this->getHttpRequest()->getPost()
			: null;

		$inputs = $form->addContainer('inputs');

		foreach (RowShape::of(\is_array($post) ? ($post['inputs'] ?? null) : null, \count($this->editedWorkflow?->inputs ?? [])) as $i) {
			$row = $inputs->addContainer((string) $i);
			$row->addText('name');
			$row->addCheckbox('required');
			$row->addText('default');
			$row->addText('description');
		}

		$form->addSubmit('save', 'Uložit');
		$form->onSuccess[] = $this->headerFormSucceeded(...);

		if ($this->editedWorkflow !== null && !$this->getRequest()->isMethod('POST')) {
			$form->setDefaults(WorkflowMapper::toValues($this->editedWorkflow));
		}

		return $form;
	}


	public function headerFormSucceeded(Form $form): void
	{
		/** @var array<string, mixed> $values */
		$values = $form->getValues('array');

		$workflow = WorkflowMapper::toWorkflow($values, $this->editedWorkflow);
		$store = new WorkflowStore($this->workflowDir());

		// Zakládání nesmí přepsat workflow, které už existuje — writeFile()
		// přepisuje bez ptaní a uživatel by o obsah přišel bez jediné hlášky.
		if ($this->editedWorkflow === null && $store->exists($workflow->name)) {
			$form->addError("Workflow \"{$workflow->name}\" už existuje. Uprav ho, nebo zvol jiné jméno.");

			return;
		}

		try {
			$store->save($workflow);

		} catch (WriteException | IOException $e) {
			$form->addError($e->getMessage());

			return;
		}

		$this->redirect('detail', ['name' => $workflow->name]);
	}


	protected function createComponentDeleteWorkflowForm(): Form
	{
		$form = new Form;
		$form->addSubmit('save', 'Smazat');
		$form->onSuccess[] = $this->deleteWorkflowFormSucceeded(...);

		return $form;
	}


	public function deleteWorkflowFormSucceeded(Form $form): void
	{
		if ($this->editedWorkflow === null) {
			$form->addError('Není co mazat.');

			return;
		}

		try {
			(new WorkflowStore($this->workflowDir()))->delete($this->editedWorkflow->name);

		} catch (ParseException | IOException $e) {
			$form->addError($e->getMessage());

			return;
		}

		$this->redirect('default');
	}


	private function workflowDir(): string
	{
		return WorkflowRepository::projectDir() . '/workflows';
	}
```

Doplň `use` na `Donut\Format\Workflow`, `Donut\Gui\RowShape`,
`Donut\Gui\WorkflowMapper`, `Donut\Gui\WorkflowStore`,
`Donut\Writer\WriteException`, `Nette\IOException`. Kde už `workflowDir()`
nahrazuje opakované skládání cesty, použij ho.

- [ ] **Step 5: Napiš `edit.latte`**

Vytvoř `gui/src/Presentation/Workflow/edit.latte`:

```latte
{import '../rows.latte'}

{block title}{$name ?? 'Nové workflow'} — Donut{/block}

{block content}
<p><a n:href="Workflow:default">← workflow</a></p>

<h1>{$name ?? 'Nové workflow'}</h1>

<p n:if="$error" class=error>{$error}</p>

{if !$error}
	{form headerForm}
		<ul n:if="$form->getErrors()" class=error>
			<li n:foreach="$form->getErrors() as $err">{$err}</li>
		</ul>

		<p>{label name /} {input name}</p>
		<p>{label description /} {input description}</p>

		<h2>Vstupy</h2>
		<p class=keys>Vstupy se na příkazové řádce zadávají jako <code>--jmeno=hodnota</code>.</p>

		<div id=inputs>
			<div class=row n:foreach="$form['inputs']->getComponents() as $row">
				{input $row['name']}
				{input $row['required']} povinný
				{input $row['default']}
				{input $row['description']}
				<button type=button class=del-row>×</button>
			</div>
		</div>
		<button type=button data-add="inputs">+ vstup</button>

		<p>{input save}</p>
	{/form}

	{if $name !== null}
		<h2>Smazat</h2>
		<p class=keys>
			Workflow se spouští jménem z cronu a z CLI, což tenhle nástroj
			nevidí — zkontroluj si, že ho nikde nespouštíš.
		</p>

		{form deleteWorkflowForm}
			<ul n:if="$form->getErrors()" class=error>
				<li n:foreach="$form->getErrors() as $err">{$err}</li>
			</ul>

			<button type=submit n:name=save onclick="return confirm('Opravdu smazat {$name}?')">Smazat</button>
		{/form}
	{/if}

	{include rows}
{/if}
```

- [ ] **Step 6: Doplň odkazy**

Do `gui/src/Presentation/Workflow/default.latte` pod nadpis přidej:

```latte
<p><a n:href="Workflow:edit">+ nové workflow</a></p>
```

Do `gui/src/Presentation/Workflow/detail.latte`, hned za `<h1>`, přidej odkaz
na úpravu hlavičky:

```latte
	<p><a n:href="Workflow:edit, name: $workflow->name">upravit hlavičku</a></p>
```

- [ ] **Step 7: Spusť test a ověř, že prochází**

Run: `cd /home/honza/Dokumenty/Projekty/donut-org/donut/gui && vendor/bin/tester tests/WorkflowPresenter.envelope.phpt tests/Latte.TemplatesCompile.phpt -C`
Expected: PASS obojí.

- [ ] **Step 8: Vyzkoušej to v prohlížeči**

```bash
cd /tmp && rm -rf zkouska-ob && mkdir -p zkouska-ob/workflows && cd zkouska-ob
cp -r /home/honza/Dokumenty/Projekty/donut-org/donut/docs/workflows/donut/blocks .
php -S 127.0.0.1:8000 -t . /home/honza/Dokumenty/Projekty/donut-org/donut/gui/www/index.php
```

1. Založ workflow se dvěma vstupy, prostřední řádek smaž před uložením.
2. Z detailu do něj přidej krok — musí to jít, detail to umí od minulého projektu.
3. Uprav hlavičku a ověř, že ten krok **nezmizel**.
4. Zkus založit workflow pod jménem, které už existuje — musí to odmítnout.
5. Smaž ho a ověř, že zmizelo ze seznamu.

**Kopie je v `/tmp`, referenční zátěž se nesmí změnit** — ověř
`git status --porcelain docs/workflows/`. Zapiš do reportu, co jsi viděl,
včetně obsahu uloženého souboru.

- [ ] **Step 9: Aktualizuj readme**

`gui/readme.md` popisuje, co GUI umí. Doplň do „Co je vidět" zakládání,
úpravu hlavičky a mazání workflow, a **z „Co zatím není" odeber obálku** —
po tomhle tasku už nezbývá nic z vrstvy 3. Sekci se spouštěním přes `php -S`
nech beze změny; dokumentuje past, na které se ztratil čas.

- [ ] **Step 10: Odškrtni v zadání**

V `docs/zadani.md` škrtni obálku a celou vrstvu 3, stejně jako jsou škrtnuté
předchozí položky. Krok 5 (GUI) je tím hotový; v „Pořadí prací" zůstane
z otevřených věcí jen ověření kroku 4 na ostro.

- [ ] **Step 11: Spusť obě sady a PHPStan**

Run:
```bash
cd /home/honza/Dokumenty/Projekty/donut-org/donut/gui && vendor/bin/tester tests -C && vendor/bin/phpstan analyse
cd /home/honza/Dokumenty/Projekty/donut-org/donut && vendor/bin/tester tests -C && vendor/bin/phpstan analyse
```
Expected: GUI 27 testů (bylo 26), donut 32 beze změny, PHPStan obojí čistý.

- [ ] **Step 12: Commit**

```bash
cd /home/honza/Dokumenty/Projekty/donut-org/donut
git add gui/src/Presentation/Workflow/WorkflowPresenter.php \
	gui/src/Presentation/Workflow/WorkflowEditTemplate.php \
	gui/src/Presentation/Workflow/edit.latte \
	gui/src/Presentation/Workflow/default.latte \
	gui/src/Presentation/Workflow/detail.latte \
	gui/tests/WorkflowPresenter.envelope.phpt \
	gui/readme.md docs/zadani.md
git commit -m "GUI: obálka workflow — zakládání, úprava hlavičky, mazání"
```
