# Krok `run` — pevné vstupy a výstupy — implementační plán

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Formulář kroku `run` v GUI postaví seznam vstupů podle deklarace kamene, který krok volá, a výstupy do mapy nabídne jako tři pevná pole místo proměnlivého seznamu selectboxů.

**Architecture:** Nová čistá třída `Donut\Gui\BlockInputs` přeloží *kámen + krok* na seřazený seznam slotů a zpátky na řádky pro `StepMapper`. Zakládaný krok `run` si kámen vybere na nové mezistránce `Workflow:pickBlock`; `WorkflowPresenter::actionStep()` kámen resolvuje dřív, než se formulář postaví, a bez něj formulář nevydá. Kontejner `in` zůstává číslovaný, jméno vstupu se do POSTu neposílá — přiřadí ho server podle pořadí.

**Tech Stack:** PHP 8.5, Nette Application/Forms, Latte, Nette Tester, PHPStan level max. Žádný build krok, žádný npm.

**Spec:** `docs/superpowers/specs/2026-08-27-krok-run-pevne-vstupy-design.md`

## Global Constraints

- **Pracuje se v `gui/`.** Donut (`src/`, `tests/` v kořeni) se nemění. Před dokončením se přesto spustí i kořenová sada, aby se ověřilo, že se nerozbila.
- **Testy:** `cd gui && vendor/bin/tester tests -C`. Jeden test: `vendor/bin/tester tests/Jmeno.phpt -C`.
- **PHPStan:** `cd gui && vendor/bin/phpstan analyse` — `level: max`, **nula chyb**. Musí projít na konci každého tasku, ne až na konci plánu.
- **Kód a komentáře anglicky.** Česky je jen `docs/` a tenhle plán. Commit messages anglicky.
- **Žádná stávající aserce se nesmí smazat ani oslabit bez náhrady.** Když aserce ztratí smysl, nahradí se aserce na totéž chování v novém tvaru, ne se zruší.
- **PHPStan pasti, na kterých kód v tomhle projektu opakovaně padá:**
  1. `(string) $mixed` je `cast.string`. Použij `Donut\Gui\Text::of()` nebo `is_string()` guard.
  2. `Donut\Format` typuje kolekce jako `array<int, Step>`, ne `list<Step>`. Nad vlastními poli je `list<…>` v pořádku, nad kolekcemi z `Donut\Format` ne.
  3. `Form::Filled`, ne `$form::Filled`.
  4. `$nullable?->prop ?? $default` je `nullsafe.neverNull`, když je `prop` sama nenullable. Rozděl do meziproměnné: `$x = $obj?->prop;` a pak `$x ?? $default`.
  5. `.phpt` soubory PHPStan **neanalyzuje** (`paths: [src, tests]`, ale jen `.php`). Na typech záleží jen v `tests/inc/*.php`.
- **Jména komponent v Nette musí odpovídat `[a-zA-Z0-9_]+`.** Jméno vstupu kamene je libovolný string, proto se jím nikdy neklíčuje kontejner.
- **`git add` vždy s konkrétními cestami, nikdy `git add -A` ani `git add .`.** V pracovním stromu jsou nesledované položky, které do commitu nepatří: `.github/workflows/frontbot.yml`, `docs/logo.png`, `rss`, `donut-org_donut.sublime-workspace`. Pozor i na `git add <adresář>` — svezl by nesledovaný soubor uvnitř. Před každým commitem zkontroluj `git status --short`.
- **Kanály jsou `stdout`, `stderr`, `exit_code`** — `Donut\Format\RunStep::Channels`. Nikdy je nepiš ručně, ber je z konstanty.

---

### Task 1: `BlockInputs` — sloty formuláře

Čistý překlad *kámen + krok → seřazený seznam slotů* a *sloty + POST → řádky pro `StepMapper`*. Nic se zatím nedrátuje do presenteru; task končí zelenou sadou a novým testem.

**Files:**
- Create: `gui/src/BlockInputSlot.php`
- Create: `gui/src/BlockInputs.php`
- Create: `gui/tests/BlockInputs.phpt`
- Create: `gui/tests/BlockInputs.ValidatorContract.phpt`

**Interfaces:**
- Consumes: `Donut\Format\Block`, `Donut\Format\RunStep`, `Donut\Format\Input`, `Donut\Format\StdinSpec`, `Donut\Gui\Text`
- Produces:
  - `Donut\Gui\BlockInputSlot` — `readonly` vlastnosti `string $name`, `bool $required`, `?string $default`, `?string $description`, `string $value`, `bool $declared`
  - `Donut\Gui\BlockInputs::slots(Block $block, ?RunStep $step): array<int, BlockInputSlot>`
  - `Donut\Gui\BlockInputs::rows(array $slots, mixed $post): list<array{key: string, value: string}>`

- [ ] **Step 1: Napiš padající test na pořadí slotů**

Vytvoř `gui/tests/BlockInputs.phpt`:

```php
<?php

declare(strict_types=1);

use Donut\Format\Block;
use Donut\Format\Input;
use Donut\Format\RunStep;
use Donut\Format\StdinSpec;
use Donut\Gui\BlockInputs;
use Donut\Template;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

// The inputs are deliberately NOT in alphabetical order: declaration order is
// filter, compact, while sorted order would be compact, filter. An
// implementation that sorted the slots instead of keeping the block's order
// would pass an alphabetical fixture without anyone noticing.
$jq = new Block(
	name: 'jq',
	command: 'jq',
	args: [['{%filter%}'], ['{%compact%}']],
	inputs: [
		'filter' => new Input('filter', required: true, description: 'jq expression'),
		'compact' => new Input('compact', required: true, default: '-c'),
	],
	stdin: new StdinSpec(required: true, description: 'JSON to filter'),
);

// --- a new step: every slot is empty, order comes from the block ---

$slots = BlockInputs::slots($jq, null);

Assert::same(
	['filter', 'compact', 'stdin'],
	array_map(fn($slot): string => $slot->name, $slots),
	'declaration order, with stdin last — not alphabetical'
);
Assert::same(['', '', ''], array_map(fn($slot): string => $slot->value, $slots));
Assert::same([true, true, true], array_map(fn($slot): bool => $slot->declared, $slots));
```

- [ ] **Step 2: Spusť test a ověř, že padá**

Run: `cd gui && vendor/bin/tester tests/BlockInputs.phpt -C`
Expected: FAIL — `Class 'Donut\Gui\BlockInputs' not found`

- [ ] **Step 3: Napiš `BlockInputSlot`**

Vytvoř `gui/src/BlockInputSlot.php`:

```php
<?php

declare(strict_types=1);

namespace Donut\Gui;


/**
 * One row of the "Block inputs" table.
 *
 * A slot is not the block's declaration: it also carries the value the step
 * passes today, and whether the block declares it at all. A key in the
 * step's `in` that the block does not declare gets a slot too — otherwise
 * saving would delete it without a word.
 */
final class BlockInputSlot
{
	public function __construct(
		public readonly string $name,
		public readonly bool $required,
		public readonly ?string $default,
		public readonly ?string $description,
		public readonly string $value,
		public readonly bool $declared,
	) {
	}
}
```

- [ ] **Step 4: Napiš `BlockInputs::slots()`**

Vytvoř `gui/src/BlockInputs.php`:

```php
<?php

declare(strict_types=1);

namespace Donut\Gui;

use Donut\Format\Block;
use Donut\Format\RunStep;


/**
 * The rows of the step form's input table. A pure conversion, knows nothing
 * of Nette or HTTP — same as BlockMapper, InputMapper and RowShape.
 *
 * The list is fixed by the block, not by the user: the block declares its
 * inputs and the validator checks them right afterwards, so there is no
 * reason to have the user retype their names.
 */
final class BlockInputs
{
	/**
	 * Slots in the order they are shown: declared inputs as the block
	 * declares them, then stdin, then whatever the step fills in that the
	 * block does not declare.
	 *
	 * @return array<int, BlockInputSlot>
	 */
	public static function slots(Block $block, ?RunStep $step): array
	{
		// $step?->in ?? [] reports nullsafe.neverNull to PHPStan (level max) —
		// `in` itself is never null, only the step is. Splitting it into a
		// variable works around that, same as in WorkflowMapper::toWorkflow().
		$stepIn = $step?->in;
		$in = $stepIn ?? [];

		$slots = [];
		$taken = [];

		foreach ($block->inputs as $name => $input) {
			$taken[$name] = true;
			$template = $in[$name] ?? null;

			$slots[] = new BlockInputSlot(
				name: $name,
				// The same rule the validator applies to an unfilled input
				// (Validator::checkRunStep()): an input with a default is
				// never missing, whatever it says about being required.
				required: $input->required && $input->default === null,
				default: $input->default,
				description: $input->description,
				value: $template === null ? '' : $template->getSource(),
				declared: true,
			);
		}

		// A block declaring an input literally named "stdin" is an error the
		// validator reports; without this guard the page would render two
		// slots with the same name and the second would overwrite the first
		// on save.
		if ($block->stdin !== null && !isset($block->inputs['stdin'])) {
			$taken['stdin'] = true;
			$template = $in['stdin'] ?? null;

			$slots[] = new BlockInputSlot(
				name: 'stdin',
				required: $block->stdin->required,
				default: null,
				description: $block->stdin->description,
				value: $template === null ? '' : $template->getSource(),
				declared: true,
			);
		}

		foreach ($in as $name => $template) {
			if (isset($taken[$name])) {
				continue;
			}

			$slots[] = new BlockInputSlot(
				name: $name,
				required: false,
				default: null,
				description: null,
				value: $template->getSource(),
				declared: false,
			);
		}

		return $slots;
	}
}
```

- [ ] **Step 5: Spusť test a ověř, že prochází**

Run: `cd gui && vendor/bin/tester tests/BlockInputs.phpt -C`
Expected: PASS

- [ ] **Step 6: Přidej test na hodnoty, `required` a nedeklarované klíče**

Připiš na konec `gui/tests/BlockInputs.phpt`:

```php
// --- an existing step: values land in their slots ---

$step = new RunStep(
	block: 'jq',
	in: [
		'filter' => Template::parse('.id'),
		'stdin' => Template::parse('{%body%}'),
		// A key the block does not declare — a typo, or an input that
		// disappeared from the block after the workflow was written.
		'filtr' => Template::parse('.old'),
	],
);

$slots = BlockInputs::slots($jq, $step);

Assert::same(
	['filter', 'compact', 'stdin', 'filtr'],
	array_map(fn($slot): string => $slot->name, $slots),
	'undeclared keys go last, in the order the step has them'
);
Assert::same(['.id', '', '{%body%}', '.old'], array_map(fn($slot): string => $slot->value, $slots));
Assert::same([true, true, true, false], array_map(fn($slot): bool => $slot->declared, $slots));

// required: "filter" is required and has no default; "compact" is required
// but has one, so it is never missing; stdin follows stdin.required; an
// undeclared key is never required — it has to be emptied.
Assert::same([true, false, true, false], array_map(fn($slot): bool => $slot->required, $slots));

// the declaration travels with the slot, so the form can show it
Assert::same([null, '-c', null, null], array_map(fn($slot): ?string => $slot->default, $slots));
Assert::same(
	['jq expression', null, 'JSON to filter', null],
	array_map(fn($slot): ?string => $slot->description, $slots)
);

// --- a block with no inputs and no stdin has no slots ---

$echo = new Block(name: 'echo', command: 'echo', args: [['hi']]);
Assert::same([], BlockInputs::slots($echo, null));

// --- a block that does not read stdin gets no stdin slot ---

$noStdin = new Block(
	name: 'greet',
	command: 'echo',
	args: [['{%text%}']],
	inputs: ['text' => new Input('text')],
);
Assert::same(['text'], array_map(fn($slot): string => $slot->name, BlockInputs::slots($noStdin, null)));

// --- a block that declares an input named stdin gets one slot, not two ---
// The validator reports that as an error; the form must not render the same
// name twice, because the second field would overwrite the first on save.

$clash = new Block(
	name: 'clash',
	command: 'cat',
	args: [['{%stdin%}']],
	inputs: ['stdin' => new Input('stdin')],
	stdin: new StdinSpec,
);
Assert::same(['stdin'], array_map(fn($slot): string => $slot->name, BlockInputs::slots($clash, null)));
```

- [ ] **Step 7: Spusť test a ověř, že padá na `required`, `default` a nedeklarovaných**

Run: `cd gui && vendor/bin/tester tests/BlockInputs.phpt -C`
Expected: PASS — kód z kroku 4 už tohle všechno umí. **Pokud test projde napoprvé, je to v pořádku** (implementace vznikla proti stejnému chování), ale ověř mutací: dočasně smaž větev `foreach ($in as $name => $template)` na konci `slots()`, spusť test, uvidíš pád na `'undeclared keys go last'`, a vrať to zpět.

- [ ] **Step 8: Napiš padající test na `rows()`**

Připiš na konec `gui/tests/BlockInputs.phpt`:

```php
// --- rows(): the POST is joined back with the names by position ---

$slots = BlockInputs::slots($jq, null);

Assert::same(
	[
		['key' => 'filter', 'value' => '.id'],
		['key' => 'stdin', 'value' => '{%body%}'],
	],
	BlockInputs::rows($slots, [
		0 => ['value' => '.id'],
		1 => ['value' => ''],
		2 => ['value' => '{%body%}'],
	]),
	'index 1 is "compact" and it is empty, so it is not written at all'
);

// An empty value must not become an empty template. A key present with an
// empty string suppresses the block's default (CommandLine::resolveValues()),
// while a missing key lets the default through — so the form never writes one.
Assert::same([], BlockInputs::rows($slots, [0 => ['value' => '   ']]), 'whitespace only is empty');
Assert::same([], BlockInputs::rows($slots, []));
Assert::same([], BlockInputs::rows($slots, null), 'no in container in the POST at all');
Assert::same([], BlockInputs::rows([], [0 => ['value' => 'x']]), 'a value with no slot has no name');
```

- [ ] **Step 9: Spusť test a ověř, že padá**

Run: `cd gui && vendor/bin/tester tests/BlockInputs.phpt -C`
Expected: FAIL — `Call to undefined method Donut\Gui\BlockInputs::rows()`

- [ ] **Step 10: Napiš `BlockInputs::rows()`**

Připiš do `gui/src/BlockInputs.php` pod `slots()`:

```php
	/**
	 * The POST joined back with the slot names: $post[$i] belongs to
	 * $slots[$i]. The name never travels through the POST — input names are
	 * arbitrary strings (JsonSource::parseInputs() accepts anything), while
	 * a Nette component name has to match [a-zA-Z0-9_]+.
	 *
	 * An empty value is dropped, not written as an empty template. The two
	 * are not the same thing: a key present with an empty string suppresses
	 * the block's default, a missing key lets it through — see
	 * CommandLine::resolveValues().
	 *
	 * @param  array<int, BlockInputSlot> $slots
	 * @param  mixed                      $post values of the `in` container
	 * @return list<array{key: string, value: string}>
	 */
	public static function rows(array $slots, mixed $post): array
	{
		$rows = [];

		foreach ($slots as $i => $slot) {
			$row = \is_array($post) ? ($post[$i] ?? null) : null;
			$value = \is_array($row) ? Text::of($row['value'] ?? '') : '';

			if ($value === '') {
				continue;
			}

			$rows[] = ['key' => $slot->name, 'value' => $value];
		}

		return $rows;
	}
```

- [ ] **Step 11: Spusť test a ověř, že prochází**

Run: `cd gui && vendor/bin/tester tests/BlockInputs.phpt -C`
Expected: PASS

- [ ] **Step 12: Napiš smlouvu s validátorem**

`BlockInputs` kopíruje pravidlo, které patří validátoru. Bez tohohle testu se obě pravidla můžou rozejít, aniž by cokoli spadlo — stejný důvod, proč existuje `KeyMap.ValidatorContract.phpt`.

Vytvoř `gui/tests/BlockInputs.ValidatorContract.phpt`:

```php
<?php

declare(strict_types=1);

use Donut\BlockRepository;
use Donut\Format\RunStep;
use Donut\Format\Workflow;
use Donut\Gui\BlockInputs;
use Donut\Validator\Validator;
use Nette\Utils\FileSystem;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

// A slot marked required is exactly an input the validator reports as
// unfilled when the step leaves it out. BlockInputs copies the rule from
// Validator::checkRunStep(); this test is what keeps the copy honest.

$dir = TEMP_DIR . '/blocks';
FileSystem::createDir($dir);
FileSystem::write($dir . '/jq.json', json_encode([
	'name' => 'jq',
	'command' => 'jq',
	'args' => [['{%filter%}'], ['{%compact%}']],
	'inputs' => [
		'filter' => ['required' => true],
		'compact' => ['required' => true, 'default' => '-c'],
		'flags' => ['required' => false],
	],
	'stdin' => ['required' => true],
]));

$blocks = new BlockRepository($dir);
$block = $blocks->get('jq');

// A step that fills in nothing — every unfilled input the validator can
// complain about, it complains about here.
$workflow = new Workflow(name: 'w', steps: [new RunStep(block: 'jq')]);
$problems = (new Validator($blocks))->validate($workflow)->getErrors();

$reported = [];

foreach ($problems as $problem) {
	if (preg_match('~required input "([^"]+)"~', $problem->message, $m) === 1) {
		$reported[$m[1]] = true;
	}

	if (str_contains($problem->message, 'requires stdin')) {
		$reported['stdin'] = true;
	}
}

$required = [];

foreach (BlockInputs::slots($block, null) as $slot) {
	if ($slot->required) {
		$required[$slot->name] = true;
	}
}

ksort($reported);
ksort($required);

Assert::same(['filter' => true, 'stdin' => true], $reported, 'the validator complains about exactly these');
Assert::same($reported, $required, 'and the form marks exactly those as required');

FileSystem::delete(TEMP_DIR);
```

- [ ] **Step 13: Spusť smlouvu a ověř, že prochází**

Run: `cd gui && vendor/bin/tester tests/BlockInputs.ValidatorContract.phpt -C`
Expected: PASS

Pokud padne na tom, že `Workflow` má jiný konstruktor, podívej se do `src/Format/Workflow.php` v kořeni repozitáře a doplň chybějící pojmenované argumenty. Nic jiného v testu neměň.

- [ ] **Step 14: Ověř mutací, že smlouva něco hlídá**

V `gui/src/BlockInputs.php` dočasně změň `required: $input->required && $input->default === null` na `required: $input->required`.

Run: `cd gui && vendor/bin/tester tests/BlockInputs.ValidatorContract.phpt -C`
Expected: FAIL — `compact` se objeví mezi povinnými, ale validátor ho nehlásí.

Vrať změnu zpět a spusť znovu: PASS.

- [ ] **Step 15: Celá sada a PHPStan**

Run: `cd gui && vendor/bin/tester tests -C && vendor/bin/phpstan analyse`
Expected: OK (42 tests), `[OK] No errors`

- [ ] **Step 16: Commit**

```bash
git add gui/src/BlockInputSlot.php gui/src/BlockInputs.php gui/tests/BlockInputs.phpt gui/tests/BlockInputs.ValidatorContract.phpt
git commit -m "Add BlockInputs: the step form's input slots come from the block"
```

---

### Task 2: Mezistránka `Workflow:pickBlock`

Zakládaný krok `run` musí kámen znát dřív, než se formulář postaví. Odkaz „+ step: run" proto povede na stránku s kartami kamenů.

Po tomhle tasku vede „+ step: run" přes mezistránku na `Workflow:step` s parametrem `block`, který starý formulář zatím **ignoruje** — select kamene tam pořád je a je prázdný. To je přechodný stav uvnitř větve; narovná ho Task 4.

**Files:**
- Create: `gui/src/Presentation/Workflow/WorkflowPickBlockTemplate.php`
- Create: `gui/src/Presentation/Workflow/pickBlock.latte`
- Create: `gui/tests/WorkflowPresenter.pickBlock.phpt`
- Modify: `gui/src/Presentation/Workflow/WorkflowPresenter.php` (přibude `renderPickBlock()`)
- Modify: `gui/src/Presentation/Workflow/steps.latte` (blok `add` na konci `{define steps}`)

**Interfaces:**
- Consumes: `Donut\BlockRepository`, `Donut\Gui\StepPath`, `Donut\Gui\ProfileDir`, `Donut\Gui\Presentation\LayoutTemplate`
- Produces: akce `Workflow:pickBlock` s parametry `name` (jméno workflow) a `at` (cesta `StepPath`, kam se krok vloží); odkazuje na `Workflow:step` s `type=run` a `block=<jméno>`

- [ ] **Step 1: Napiš padající test**

Vytvoř `gui/tests/WorkflowPresenter.pickBlock.phpt`:

```php
<?php

declare(strict_types=1);

use Donut\Profile;
use Nette\Application\BadRequestException;
use Nette\Application\Request as NetteRequest;
use Nette\Utils\FileSystem;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/inc/workflowPresenter.php';

$project = TEMP_DIR . '/pick';
FileSystem::createDir($project . '/blocks');
FileSystem::createDir($project . '/workflows');

FileSystem::write($project . '/blocks/jq.json', json_encode([
	'name' => 'jq', 'command' => 'jq', 'args' => [],
	'description' => 'Filters JSON on stdin.',
	'inputs' => ['filter' => ['required' => true]],
]));
FileSystem::write($project . '/blocks/echo.json', json_encode([
	'name' => 'echo', 'command' => 'echo', 'args' => [],
]));
// A broken file must not hide the working ones — the same rule as on
// Block:default and in `donut --list`.
FileSystem::write($project . '/blocks/broken.json', '{');

FileSystem::write($project . '/workflows/w.json', json_encode([
	'name' => 'w',
	'steps' => [],
]));

[, $html] = runWorkflowPresenterIn($project, [
	'action' => 'pickBlock', 'name' => 'w', 'at' => 'w.json:steps[0]',
]);

// every block is offered, and the card carries what tells them apart
Assert::contains('>jq<', $html);
Assert::contains('Filters JSON on stdin.', $html);
Assert::contains('>echo<', $html);

// the card is a link that hands the block to the step form
Assert::match('~href="[^"]*block=jq[^"]*"~', $html);
Assert::match('~href="[^"]*type=run[^"]*"~', $html);
Assert::match('~href="[^"]*at=w\.json[^"]*"~', $html);

// the broken file is reported, and the working blocks are still there
Assert::contains('broken', $html);
Assert::contains('text-danger', $html);

// --- a missing blocks/ directory says when it will appear ---

$empty = TEMP_DIR . '/emptyProfile';
FileSystem::createDir($empty . '/workflows');
FileSystem::write($empty . '/workflows/w.json', json_encode(['name' => 'w', 'steps' => []]));

[, $emptyHtml] = runWorkflowPresenterIn($empty, [
	'action' => 'pickBlock', 'name' => 'w', 'at' => 'w.json:steps[0]',
]);

Assert::contains('Donut will create it when you save.', $emptyHtml);

// --- a path pointing into another workflow is a bad request ---
//
// The page only builds links, but a wrong `at` would produce links that fail
// one click later, with the message pointing at the step form instead of at
// the address that was actually wrong.

$e = Assert::exception(
	fn() => createWorkflowPresenter([], true, new Profile(\basename($project), $project))
		->run(new NetteRequest('Workflow', 'GET', [
			'action' => 'pickBlock', 'name' => 'w', 'at' => 'other.json:steps[0]',
		])),
	BadRequestException::class,
);
Assert::same(400, $e->getHttpCode());

FileSystem::delete(TEMP_DIR);
```

- [ ] **Step 2: Spusť test a ověř, že padá**

Run: `cd gui && vendor/bin/tester tests/WorkflowPresenter.pickBlock.phpt -C`
Expected: FAIL — Nette hlásí, že `Workflow:pickBlock` neexistuje (chybí `renderPickBlock()` i šablona).

- [ ] **Step 3: Napiš třídu šablony**

Vytvoř `gui/src/Presentation/Workflow/WorkflowPickBlockTemplate.php`:

```php
<?php

declare(strict_types=1);

namespace Donut\Gui\Presentation\Workflow;

use Donut\Format\Block;
use Donut\Gui\Presentation\LayoutTemplate;


/**
 * Template for Workflow:pickBlock — which block will the new run step call?
 */
final class WorkflowPickBlockTemplate extends LayoutTemplate
{
	/** @var array<string, Block|string> name => block, or an error message */
	public array $blocks = [];

	public ?string $error = null;

	public string $dir = '';

	public string $name = '';

	public string $at = '';
}
```

- [ ] **Step 4: Napiš `renderPickBlock()`**

Přidej do `gui/src/Presentation/Workflow/WorkflowPresenter.php` hned za `renderStep()`:

```php
	/**
	 * Which block will the new run step call? A run step cannot be created
	 * without one — the whole input list comes from the block, so the form
	 * has to know it before it is built.
	 */
	public function renderPickBlock(string $name, string $at): void
	{
		/** @var WorkflowPickBlockTemplate $template */
		$template = $this->template;
		$template->name = $name;
		$template->at = $at;
		$template->dir = $this->blockDir();

		// The page only builds links, but a path that belongs elsewhere would
		// produce links that fail one click later — with the message about
		// the step form, not about the address that was wrong.
		try {
			if (StepPath::parse($at)->workflowName() !== $name) {
				throw new \InvalidArgumentException("Path \"{$at}\" does not belong to workflow \"{$name}\".");
			}
		} catch (\InvalidArgumentException $e) {
			$this->error($e->getMessage(), IResponse::S400_BadRequest);
		}

		try {
			$repository = new BlockRepository($this->blockDir());

		} catch (ParseException $e) {
			// The only error the constructor lets through is a missing
			// directory, same as on Block:default — so the hint always applies.
			$template->blocks = [];
			$template->error = $e->getMessage() . ' ' . ProfileDir::hint();

			return;
		}

		$blocks = [];

		foreach ($repository->getNames() as $blockName) {
			try {
				$blocks[$blockName] = $repository->get($blockName);

			} catch (ParseException $e) {
				// A broken file must not hide the others — the same rule as
				// `donut --list`.
				$blocks[$blockName] = $e->getMessage();
			}
		}

		$template->blocks = $blocks;
		$template->error = null;
	}
```

Import `Donut\Gui\ProfileDir` v hlavičce souboru už je; `Donut\BlockRepository`, `Donut\Parser\ParseException`, `Nette\Http\IResponse` a `Donut\Gui\StepPath` taky. Nic dalšího přidávat nemusíš — ověř to.

- [ ] **Step 5: Napiš šablonu**

Vytvoř `gui/src/Presentation/Workflow/pickBlock.latte`:

```latte
{block title}Which block? — {$name} — Donut{/block}

{block breadcrumbs}
	<li class=breadcrumb-item><a n:href="Workflow:default">Workflows</a></li>
	<li class=breadcrumb-item><a n:href="Workflow:detail, name: $name">{$name}</a></li>
	<li class="breadcrumb-item active" aria-current=page>new run step</li>
{/block}

{block content}
<h1>Which block?</h1>
<p class=keys>{$at}</p>

{* The message, including the hint about a missing blocks/, arrives
   ready-made from renderPickBlock(), same as on Block:default. *}
<p n:if="$error" class="alert alert-danger">{$error}</p>
<p n:if="!$error && !$blocks">There are no blocks in <code>{$dir}</code>.</p>

<div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-3">
	<div class=col n:foreach="$blocks as $blockName => $block">
		{if is_string($block)}
			{* A broken file keeps its card so the name stays visible; there is
			   nothing to link to, because nothing parsed. *}
			<div class="card h-100">
				<div class=card-body>
					<h2 class="card-title h6">{$blockName}</h2>
					<p class="card-text text-danger mb-0">{$block}</p>
				</div>
			</div>
		{else}
			<a class="card h-100 text-decoration-none text-reset"
				n:href="Workflow:step, name: $name, at: $at, type: run, block: $blockName">
				<div class=card-body>
					<h2 class="card-title h6">{$blockName}</h2>
					<p n:if="$block->description" class="card-text small">{$block->description}</p>
					<p class="card-text text-muted small mb-0">
						<code>{$block->command}</code>
						{var $count = count($block->inputs)}
						· {if $count === 0}no inputs{else}{$count} {$count === 1 ? 'input' : 'inputs'}{/if}
					</p>
				</div>
			</a>
		{/if}
	</div>
</div>
{/block}
```

- [ ] **Step 6: Spusť test a ověř, že prochází**

Run: `cd gui && vendor/bin/tester tests/WorkflowPresenter.pickBlock.phpt -C`
Expected: PASS

- [ ] **Step 7: Přesměruj „+ step: run" ve stromu**

V `gui/src/Presentation/Workflow/steps.latte` nahraď blok na konci `{define steps}`:

```latte
	<div class="add">+ step:
		{foreach ['run', 'set', 'if', 'foreach'] as $newType}
			<a href="{plink Workflow:step, name: $name, at: (string) $path->index(count($steps)), type: $newType}">{$newType}</a>{sep} {/sep}
		{/foreach}
	</div>
```

tímhle:

```latte
	<div class="add">+ step:
		{* run goes through the block picker: the form cannot be built without
		   knowing which block the step calls. The other three types have
		   nothing to pick. *}
		<a href="{plink Workflow:pickBlock, name: $name, at: (string) $path->index(count($steps))}">run</a>
		{foreach ['set', 'if', 'foreach'] as $newType}
			<a href="{plink Workflow:step, name: $name, at: (string) $path->index(count($steps)), type: $newType}">{$newType}</a>{sep} {/sep}
		{/foreach}
	</div>
```

- [ ] **Step 8: Přidej aserci, že odkaz vede na výběr kamene**

Připiš na konec `gui/tests/WorkflowPresenter.pickBlock.phpt`, **před** `FileSystem::delete(TEMP_DIR);`:

```php
// --- the tree's "+ step: run" goes through the picker, the other three don't ---

[, $detail] = runWorkflowPresenterIn($project, ['action' => 'detail', 'name' => 'w']);

Assert::match('~<div class="add">[^<]*<a href="[^"]*action=pickBlock~', $detail);
Assert::notMatch('~action=step[^"]*type=run~', $detail, 'run must not skip the picker');
Assert::match('~href="[^"]*type=set~', $detail, 'set still goes straight to the form');
```

- [ ] **Step 9: Spusť test a ověř, že prochází**

Run: `cd gui && vendor/bin/tester tests/WorkflowPresenter.pickBlock.phpt -C`
Expected: PASS

Pokud padne `Assert::match` na `<div class="add">` kvůli bílým znakům, uprav regulár na `~<div class="add">.*?action=pickBlock~s` — na formátování Latte výstupu se nespoléhej.

- [ ] **Step 10: Celá sada, ověř šablony a PHPStan**

Run: `cd gui && vendor/bin/tester tests -C && vendor/bin/phpstan analyse`
Expected: OK (43 tests), `[OK] No errors`

`Latte.TemplatesCompile.phpt` sám najde novou šablonu a zkusí ji zkompilovat — pokud padne, je chyba v `pickBlock.latte`, ne v testu.

- [ ] **Step 11: Commit**

```bash
git add gui/src/Presentation/Workflow/WorkflowPickBlockTemplate.php gui/src/Presentation/Workflow/pickBlock.latte gui/src/Presentation/Workflow/WorkflowPresenter.php gui/src/Presentation/Workflow/steps.latte gui/tests/WorkflowPresenter.pickBlock.phpt
git commit -m "Add the block picker for a new run step"
```

---

### Task 3: `actionStep()` resolvuje kámen

Formulář se ještě nemění; task jen zajistí, že v okamžiku jeho stavby je kámen znám, a že bez něj stránka nevznikne.

**Files:**
- Modify: `gui/src/Presentation/Workflow/WorkflowPresenter.php` (`actionStep()`, nová vlastnost `$block`)
- Modify: `gui/tests/WorkflowPresenter.step.phpt` (nové aserce na konci)

**Interfaces:**
- Consumes: `Donut\Gui\BlockInputs` (zatím ne), `Donut\BlockRepository`, `Donut\Format\Block`
- Produces: `private ?Block $block` na `WorkflowPresenter`, naplněná pro `stepType === 'run'` dřív, než se staví `stepForm`

- [ ] **Step 1: Napiš padající testy**

Připiš na konec `gui/tests/WorkflowPresenter.step.phpt`, **před** `FileSystem::delete(TEMP_DIR);`:

```php
// --- a new run step needs to know its block ---
//
// The whole input list comes from the block. Without it there is nothing to
// build the form from, and an address that leaves it out is a wrong request,
// not a missing page — the picker is the way in.

$e = Assert::exception(
	fn() => createWorkflowPresenter([], true, new Profile(\basename($project), $project))
		->run(new NetteRequest('Workflow', 'GET', [
			'action' => 'step', 'name' => 'w', 'at' => 'w.json:steps[0]', 'type' => 'run',
		])),
	BadRequestException::class,
);
Assert::same(400, $e->getHttpCode());

// --- a block that is not in blocks/ is a 404, not a half-usable form ---

$e = Assert::exception(
	fn() => createWorkflowPresenter([], true, new Profile(\basename($project), $project))
		->run(new NetteRequest('Workflow', 'GET', [
			'action' => 'step', 'name' => 'w', 'at' => 'w.json:steps[0]',
			'type' => 'run', 'block' => 'nope',
		])),
	BadRequestException::class,
);
Assert::same(404, $e->getHttpCode());

// --- editing takes the block from the step, not from the address ---
//
// A forged `block` in the query string must not decide which inputs the form
// offers; the step already says which block it calls.

[, $html] = runWorkflowPresenterIn($project, [
	'action' => 'step', 'name' => 'w', 'at' => 'w.json:steps[0]', 'block' => 'nope',
]);

Assert::contains('<form', $html, 'the step is edited, the address is ignored');
```

- [ ] **Step 2: Spusť test a ověř, že padá**

Run: `cd gui && vendor/bin/tester tests/WorkflowPresenter.step.phpt -C`
Expected: FAIL — první aserce: žádná výjimka nepřijde, `run` bez `block` se dnes vykreslí.

- [ ] **Step 3: Přidej vlastnost a resolvování kamene**

V `gui/src/Presentation/Workflow/WorkflowPresenter.php` přidej k ostatním vlastnostem:

```php
	private ?Block $block = null;
```

a doplň import `use Donut\Format\Block;`.

V `actionStep()` změň signaturu na:

```php
	public function actionStep(string $name, string $at, ?string $type = null, ?string $block = null): void
```

a hned za blok, který určuje `$this->stepType` (tedy za `} else { $this->stepType = $type; }`), vlož:

```php
			if ($this->stepType === 'run') {
				// Editing takes the block from the step, same as the type:
				// the step already says which block it calls, and a forged
				// `block` in the address must not decide which inputs the
				// form offers.
				$blockName = $this->editedStep instanceof RunStep
					? $this->editedStep->block
					: ($block ?? '');

				if ($blockName === '') {
					throw new \InvalidArgumentException(
						'A new run step needs the block it calls — start from the block picker.'
					);
				}

				// A block that cannot be read leaves nothing to render: the
				// whole input list comes from it. Unlike a broken workflow,
				// there is no page to keep, so this ends the request.
				$this->block = (new BlockRepository($this->blockDir()))->get($blockName);
			}
```

Pořadí `catch` bloků pod tím **neměň**: `NotFoundException` dědí z `ParseException`, takže větev `catch (NotFoundException | \OutOfRangeException)` musí zůstat před `catch (ParseException)`. Chybějící kámen tak skončí jako 404, chybějící `block` v adrese jako 400 ve větvi `catch (\InvalidArgumentException)`.

Rozbitý soubor kamene (nebo chybějící `blocks/`) padne do `catch (ParseException)`, která dnes nastaví `$template->error` a nechá stránku být — a `step.latte` má formulář uvnitř `{if !$error}`, takže se nevykreslí. To je v pořádku: uživatel uvidí, co je rozbité, a formulář nedostane.

- [ ] **Step 4: Spusť test a ověř, že prochází**

Run: `cd gui && vendor/bin/tester tests/WorkflowPresenter.step.phpt -C`
Expected: PASS

- [ ] **Step 5: Celá sada a PHPStan**

Run: `cd gui && vendor/bin/tester tests -C && vendor/bin/phpstan analyse`
Expected: OK (43 tests), `[OK] No errors`

Pokud PHPStan hlásí `Property Donut\Gui\Presentation\Workflow\WorkflowPresenter::$block is never read, only written`, znamená to, že jsi vynechal jeho použití — to přijde v Tasku 4. Dočasně to **neobcházej** anotací; místo toho ověř, že vlastnost čte alespoň `createComponentStepForm()`. Pokud PHPStan trvá na svém, přesuň zavedení vlastnosti do Tasku 4 a v tomhle tasku drž kámen v lokální proměnné jen pro validaci adresy.

- [ ] **Step 6: Commit**

```bash
git add gui/src/Presentation/Workflow/WorkflowPresenter.php gui/tests/WorkflowPresenter.step.phpt
git commit -m "Resolve the run step's block before the form is built"
```

---

### Task 4: Pevný seznam vstupů

Kontejner `in` přestává být proměnlivým seznamem dvojic a stává se pevným seznamem slotů podle kamene. Select kamene mizí.

**Files:**
- Modify: `gui/src/Presentation/Workflow/WorkflowPresenter.php` (`createComponentStepForm()`, `stepFormSucceeded()`, `renderStep()`, smazat `blockNames()`, `rowShape()` zúžit)
- Modify: `gui/src/Presentation/Workflow/WorkflowStepTemplate.php` (`$blocks` pryč, přibude `$block`)
- Modify: `gui/src/Presentation/Workflow/step.latte` (karta „Block", karta „Block inputs")
- Modify: `gui/src/StepMapper.php` (`toValues()` přestane vracet `in`)
- Modify: `gui/tests/WorkflowPresenter.step.phpt`
- Modify: `gui/tests/Rows.phpt` (sekce „the step page")
- Modify: `gui/tests/StepMapper.phpt`

**Interfaces:**
- Consumes: `BlockInputs::slots()`, `BlockInputs::rows()`, `BlockInputSlot`, `WorkflowPresenter::$block` z Tasku 3
- Produces: kontejner `in` s podkontejnery `0..n`, každý s jediným polem `value`; POST tvar `in[0][value]`

- [ ] **Step 1: Uprav fixturu tak, aby pořadí slotů šlo dokázat**

V `gui/tests/WorkflowPresenter.step.phpt` nahraď definici kamene `jq`:

```php
FileSystem::write($project . '/blocks/jq.json', json_encode([
	'name' => 'jq', 'command' => 'jq', 'args' => [],
	'inputs' => ['filter' => ['required' => true]],
	'stdin' => ['required' => true],
]));
```

tímhle:

```php
// The inputs are deliberately NOT in alphabetical order: declaration order
// is filter, compact, while sorted order would be compact, filter. With an
// alphabetical fixture an implementation that sorted the slots would pass
// and nobody would notice.
//
// "compact" is required AND has a default — the validator never reports such
// an input as unfilled, so the form must not mark it required either.
FileSystem::write($project . '/blocks/jq.json', json_encode([
	'name' => 'jq', 'command' => 'jq', 'args' => [['{%filter%}'], ['{%compact%}']],
	'inputs' => [
		'filter' => ['required' => true],
		'compact' => ['required' => true, 'default' => '-c'],
	],
	'stdin' => ['required' => true],
]));
```

- [ ] **Step 2: Napiš padající aserce na pevné sloty**

V `gui/tests/WorkflowPresenter.step.phpt` v sekci „editing an existing step" nahraď:

```php
Assert::contains('value="jq"', $html);
Assert::contains('.id', $html);
```

tímhle:

```php
// The block is not a dropdown any more: switching it would leave the inputs
// of the old one standing, and the form has no way of telling which values
// belong to the new block. It is text with a link to the block itself.
Assert::notContains('name="block"', $html);
Assert::match('~<a[^>]*href="[^"]*action=detail[^"]*name=jq[^"]*"[^>]*>jq</a>~', $html);

// one row per declared input, in the block's order, stdin last
Assert::match('~name="in\[0\]\[value\]"[^>]*value="\.id"~', $html);
Assert::contains('>filter<', $html);
Assert::contains('>compact<', $html);
Assert::contains('>stdin<', $html);

// the name of the input never travels through the POST — it is arbitrary
// text, while a Nette component name has to match [a-zA-Z0-9_]+
Assert::notContains('name="in[0][key]"', $html);

// required exactly where the validator would complain: filter has no
// default, compact has one, stdin follows stdin.required
Assert::match('~name="in\[0\]\[value\]"[^>]*required~', $html);
Assert::notMatch('~name="in\[1\]\[value\]"[^>]*required~', $html);
Assert::match('~name="in\[2\]\[value\]"[^>]*required~', $html);

// the declaration is shown, not hidden: the default as a placeholder
Assert::match('~name="in\[1\]\[value\]"[^>]*placeholder="-c"~', $html);
```

- [ ] **Step 3: Spusť test a ověř, že padá**

Run: `cd gui && vendor/bin/tester tests/WorkflowPresenter.step.phpt -C`
Expected: FAIL — `Assert::notContains('name="block"')` padne, select kamene tam pořád je.

- [ ] **Step 4: Přestav větev `run` ve formuláři**

V `gui/src/Presentation/Workflow/WorkflowPresenter.php` nahraď v `createComponentStepForm()` celý blok od `if ($this->stepType === 'run') {` po konec kontejneru `out` (tedy až před `$form->addText('timeout', …)`) tímhle — kontejner `out` v něm zatím **zůstává beze změny**, tomu se věnuje Task 5:

```php
		if ($this->stepType === 'run') {
			$block = $this->block;

			if ($block === null) {
				throw new \LogicException('unreachable — actionStep() ends the request without a block');
			}

			$this->slots = BlockInputs::slots(
				$block,
				$this->editedStep instanceof RunStep ? $this->editedStep : null,
			);

			$in = $form->addContainer('in');

			foreach ($this->slots as $i => $slot) {
				$row = $in->addContainer((string) $i);

				// aria-label instead of a caption: the table's first column
				// names the input, but <th> names the cell, not the <input>
				// inside it — a screen reader would otherwise just read
				// "textbox".
				$value = $row->addText('value')
					->setHtmlAttribute('aria-label', $slot->name)
					->setDefaultValue($slot->value);

				if ($slot->default !== null) {
					$value->setHtmlAttribute('placeholder', $slot->default);
				}

				if (!$slot->declared) {
					// Not dropped silently: the value stays visible until the
					// user clears it themselves. An empty slot is not written
					// to `in` at all, so clearing the field removes the key.
					$value->addRule(
						Form::Blank,
						"Block \"{$block->name}\" does not declare the input \"{$slot->name}\" — clear the field to drop it."
					);

				} elseif ($slot->required) {
					$value->setRequired("Fill in the required input \"{$slot->name}\".");
				}
			}

			$out = $form->addContainer('out');

			foreach ($this->rowShape()['out'] as $i) {
				$row = $out->addContainer((string) $i);
				$row->addSelect('channel', null, \array_combine(RunStep::Channels, RunStep::Channels))
					->setPrompt('—')
					->setHtmlAttribute('aria-label', 'Block output');
				$row->addText('value')->setHtmlAttribute('aria-label', 'Map key');
			}
```

Přidej k vlastnostem třídy:

```php
	/** @var array<int, BlockInputSlot> */
	private array $slots = [];
```

a importy `use Donut\Gui\BlockInputs;`, `use Donut\Gui\BlockInputSlot;`.

- [ ] **Step 5: Zúž `rowShape()` na `out`**

`rowShape()` už nikdo neptá na `in`. Nahraď ji v `gui/src/Presentation/Workflow/WorkflowPresenter.php`:

```php
	/**
	 * How many rows the `out` container has. The `in` container is not
	 * variable any more — its rows come from the block, see BlockInputs.
	 *
	 * @return array{out: array<int, int>}
	 */
	private function rowShape(): array
	{
		// A different signal carries no out at all — without this condition
		// the form would be built with zero rows and the page would show an
		// empty step that in fact isn't empty.
		$post = $this->isFormPost('stepForm-submit')
			? $this->getHttpRequest()->getPost()
			: null;

		$out = \is_array($post) ? ($post['out'] ?? null) : null;
		$step = $this->editedStep;

		return [
			'out' => RowShape::of($out, $step instanceof RunStep ? \count($step->out) : 0),
		];
	}
```

- [ ] **Step 6: Zapoj `rows()` do ukládání**

V `stepFormSucceeded()` vlož mezi `$values = $form->getValues('array');` a `$rawName = …`:

```php
		if ($this->stepType === 'run') {
			// The name of each input comes from the slots, not from the POST:
			// $values['in'][$i] belongs to $this->slots[$i]. An empty value is
			// dropped rather than written as an empty template — see
			// BlockInputs::rows().
			$values['in'] = BlockInputs::rows($this->slots, $values['in'] ?? null);
		}
```

- [ ] **Step 7: Odeber select kamene ze šablony a přidej odkaz**

V `gui/src/Presentation/Workflow/step.latte` nahraď:

```latte
				{if $type === 'run'}
					<p>{label block /} {input block}</p>
```

tímhle:

```latte
				{if $type === 'run'}
					{* Not a dropdown: switching the block would leave the
					   inputs of the old one standing, and the form has no way
					   of telling which values belong to the new block. A
					   different block is a different step. *}
					<p>Block <a n:href="Block:detail, name: $block">{$block}</a></p>
```

- [ ] **Step 8: Přepiš tabulku vstupů**

V `gui/src/Presentation/Workflow/step.latte` nahraď celou kartu `Block inputs` (od `<div class="card mb-3">` s hlavičkou `Block inputs` po její `</div>`) tímhle:

```latte
			<div class="card mb-3">
				<div class="card-header">Block inputs</div>
				<div class="card-body">
					<p n:if="!$slots" class="text-muted mb-0">The block has no inputs.</p>

					{* table-responsive and aria-label literally like in
					   Workflow/edit.latte. There is no add or delete button:
					   the list is the block's, not the user's. *}
					<div class=table-responsive n:if="$slots">
						<table class="table table-sm align-middle">
							<thead>
								<tr>
									<th scope=col>Block input</th>
									<th scope=col>Value or <code>{='{%key%}'}</code></th>
								</tr>
							</thead>
							<tbody>
								<tr n:foreach="$slots as $i => $slot">
									<td>
										<span n:class="!$slot->declared ? 'text-danger' : null">{$slot->name}</span>
										{if $slot->required}<span class="text-danger" aria-hidden=true>*</span>{/if}
										<div n:if="$slot->description" class="text-muted small">{$slot->description}</div>
										<div n:if="!$slot->declared" class="text-danger small">
											The block does not declare this input. Clear the field to drop it.
										</div>
									</td>
									<td>{input $form['in'][$i]['value']}</td>
								</tr>
							</tbody>
						</table>
					</div>
				</div>
			</div>
```

- [ ] **Step 9: Předej sloty do šablony**

V `gui/src/Presentation/Workflow/WorkflowStepTemplate.php` nahraď:

```php
	/** @var array<string, string> */
	public array $blocks = [];
```

tímhle:

```php
	public string $block = '';

	/** @var array<int, \Donut\Gui\BlockInputSlot> */
	public array $slots = [];
```

V `renderStep()` nahraď `$template->blocks = $this->blockNames();` tímhle:

```php
		$template->block = $this->block?->name ?? '';
		$template->slots = $this->slots;
```

Pozor na past č. 4: `$this->block?->name ?? ''` je v pořádku jen proto, že `$this->block` může být `null` **celý**. Pokud to PHPStan přesto hlásí jako `nullsafe.neverNull`, rozděl to:

```php
		$blockName = $this->block?->name;
		$template->block = $blockName ?? '';
```

`$this->slots` je naplněná až ve `createComponentStepForm()`, které Latte volá při `{form stepForm}` — tedy **po** `renderStep()`. Šablona proto musí sloty brát ze `$form`, ne z `$template->slots`; proto tabulka výše iteruje `$slots` až uvnitř `{form}`. Ověř, že `{foreach $slots …}` stojí uvnitř bloku `{form stepForm}` — pokud ne, přesuň naplnění `$this->slots` do `actionStep()` hned za resolvování kamene:

```php
				$this->slots = BlockInputs::slots(
					$this->block,
					$this->editedStep instanceof RunStep ? $this->editedStep : null,
				);
```

a ve `createComponentStepForm()` už jen čti `$this->slots`. **Tohle je preferovaná varianta** — sloty pak existují dřív než formulář i šablona a nezáleží na pořadí vykreslování.

- [ ] **Step 10: Smaž `blockNames()`**

V `gui/src/Presentation/Workflow/WorkflowPresenter.php` smaž celou privátní metodu `blockNames()` i s docblockem. Byla jen pro select, který zmizel; PHPStan level max hlásí nepoužitou privátní metodu jako chybu.

- [ ] **Step 11: `StepMapper::toValues()` přestane vracet `in`**

V `gui/src/StepMapper.php` v `toValues()` smaž ve větvi `RunStep` cyklus:

```php
			$in = [];

			foreach ($step->in as $key => $template) {
				$in[] = ['key' => $key, 'value' => $template->getSource()];
			}
```

a z vráceného pole klíč `'in' => $in,`.

Doplň nad `return` komentář:

```php
			// `in` is not returned: the values of the inputs travel with the
			// slots (BlockInputs::slots()), and the container they belong to
			// is keyed by position, not by the shape this method used to
			// produce. Nette ignores keys it has no control for, so leaving
			// it here would fail silently rather than loudly.
```

`toIn()` **zůstává beze změny** — dostává řádky z `BlockInputs::rows()`.

- [ ] **Step 12: Sesouhlas `StepMapper.phpt`**

V `gui/tests/StepMapper.phpt` najdi aserci na `in` ve výstupu `toValues()` (kolem řádku 190, `Assert::same([['key' => …]], $values['in'])`) a nahraď ji:

```php
// `in` is not among the values any more: the inputs travel with the slots,
// see BlockInputs. Asserting its absence is what keeps a half-finished
// revert from passing.
Assert::false(array_key_exists('in', $values), 'toValues() does not build the in rows');
```

Aserce na `toStep()` s `in` řádky **nech být** — `toIn()` se nemění a ty řádky teď staví `BlockInputs::rows()`.

- [ ] **Step 13: Sesouhlas `Rows.phpt`**

V `gui/tests/Rows.phpt` v sekci „the step page" nahraď aserce na tabulku `in`:

```php
Assert::contains('<th scope=col>Block input</th>', $stepHtml);
```
zůstává.

Smaž:
```php
Assert::contains('<tbody id=in>', $stepHtml);
Assert::contains('data-add=in', $stepHtml);
Assert::match('~name="in\[0\]\[key\]"[^>]*aria-label="Block input"~', $stepHtml);
Assert::match('~name="in\[0\]\[value\]"[^>]*aria-label="Value"~', $stepHtml);
Assert::match('~name="in\[0\]\[key\]"[^>]*value="filter"~', $stepHtml);
```

a nahraď je:

```php
// The in table has no add/delete buttons: its rows are the block's, not the
// user's. The name of the input is text in the first column, and the field
// carries it as its accessible name.
Assert::notContains('<tbody id=in>', $stepHtml);
Assert::notContains('data-add=in', $stepHtml);
Assert::match('~name="in\[0\]\[value\]"[^>]*aria-label="filter"~', $stepHtml);
Assert::match('~name="in\[0\]\[value\]"[^>]*value="\.id"~', $stepHtml);
```

Uprav i počet mazacích tlačítek — po tomhle tasku je má jen tabulka `out`, která má dva řádky (naplněný plus jeden prázdný navíc):

```php
Assert::same(2, \substr_count($stepHtml, 'js-del-row" aria-label="Delete row"'));
```

Fixtura kamene `jq` v `Rows.phpt` deklaruje jen `filter` a `stdin`; to stačí, pořadí hlídá `WorkflowPresenter.step.phpt`.

- [ ] **Step 14: Sesouhlas POST těla v `WorkflowPresenter.step.phpt`**

V sekci „saving the edit" nahraď:

```php
		'type' => 'run', 'name' => 'named', 'block' => 'jq',
		// gap in numbering deliberately
		'in' => [0 => ['key' => 'filter', 'value' => '.title'], 2 => ['key' => 'stdin', 'value' => '{%x%}']],
```

tímhle:

```php
		'type' => 'run', 'name' => 'named',
		// Index 1 is "compact" and stays empty: an empty slot is not written
		// to `in` at all, or it would suppress the block's default. Index 2
		// is stdin — the server knows that from the block, the POST does not
		// say it anywhere.
		'in' => [0 => ['value' => '.title'], 1 => ['value' => ''], 2 => ['value' => '{%x%}']],
```

Aserce pod tím uprav:

```php
Assert::same(['filter', 'stdin'], array_keys($run->in), 'the empty slot is not written');
Assert::same('.title', $run->in['filter']->getSource());
Assert::same('{%x%}', $run->in['stdin']->getSource(), 'index 2 is stdin, by position');
```

V sekci „a foreign POST must not empty the step form" nahraď:

```php
Assert::contains('value="jq"', $html, 'the chosen block must not be lost');
Assert::match('~name="in\[0\]\[key\]"[^>]*value="filter"~', $html, "the step's inputs must not be lost");
Assert::match('~name="in\[0\]\[value\]"[^>]*value="\.id"~', $html);
```

tímhle:

```php
Assert::contains('>jq</a>', $html, 'the block must not be lost');
Assert::match('~name="in\[0\]\[value\]"[^>]*value="\.id"~', $html, "the step's inputs must not be lost");
```

- [ ] **Step 15: Přepiš test „validace neblokuje uložení"**

Poslední sekce `WorkflowPresenter.step.phpt` dnes dokazuje, že se uloží i krok bez povinných vstupů. To už neplatí — povinné vstupy mají `setRequired()`. Vlastnost „validace neblokuje" ale platí dál a musí být dokázaná jinak: hodnotou, která odkazuje na klíč, jenž v mapě nevznikne.

Nahraď celou sekci od komentáře `// --- an invalid workflow still gets saved` po `Assert::same([], $steps()[0]->in, …);` tímhle:

```php
// --- an invalid workflow still gets saved: validation doesn't block ---
//
// The step reads {%nope%}, a key nothing in the workflow ever writes — a
// hard error for the validator. It must still get saved: a workflow being
// built is invalid most of the time, and a GUI that refused to save it
// would be unusable.
//
// Note: this deliberately isn't manufactured by leaving a required input
// empty any more. Required inputs now carry setRequired(), so the form
// itself stops that — which is the point of Task 4, not a property of
// saving.

runWorkflowPresenterIn(
	$project,
	['action' => 'step', 'name' => 'w', 'at' => 'w.json:steps[0]', 'do' => 'stepForm-submit'],
	[
		'type' => 'run', 'name' => '',
		'in' => [0 => ['value' => '{%nope%}'], 1 => ['value' => ''], 2 => ['value' => 'x']],
		'out' => [], 'timeout' => '',
		'allowFailure' => 'inherit', 'allowFailureCodes' => '', 'save' => 'Save',
	],
);

Assert::same('{%nope%}', $steps()[0]->in['filter']->getSource(), 'an invalid step still gets saved');
```

- [ ] **Step 16: Přidej test na nedeklarovaný vstup**

Připiš do `gui/tests/WorkflowPresenter.step.phpt` před `FileSystem::delete(TEMP_DIR);`:

```php
// --- a key the block does not declare is shown, and must be cleared ---
//
// The block used to declare it, or it is a typo. Either way the form must
// not drop it silently: it renders as a slot with a rule that the field has
// to be empty, so saving is possible only once the user has seen it and
// cleared it themselves.

FileSystem::write($project . '/workflows/leftover.json', json_encode([
	'name' => 'leftover',
	'steps' => [[
		'type' => 'run', 'block' => 'jq',
		'in' => ['filter' => '.id', 'filtr' => '.old'],
	]],
]));

[, $html] = runWorkflowPresenterIn($project, [
	'action' => 'step', 'name' => 'leftover', 'at' => 'leftover.json:steps[0]',
]);

Assert::contains('>filtr<', $html, 'the undeclared key is visible');
Assert::contains('does not declare this input', $html);
Assert::match('~name="in\[3\]\[value\]"[^>]*value="\.old"~', $html, 'undeclared keys go last');

// saving with the field still filled in is refused
$leftover = fn(): array => (new WorkflowParser)
	->parseFile($project . '/workflows/leftover.json')->steps;

runWorkflowPresenterIn(
	$project,
	['action' => 'step', 'name' => 'leftover', 'at' => 'leftover.json:steps[0]', 'do' => 'stepForm-submit'],
	[
		'type' => 'run', 'name' => '',
		'in' => [0 => ['value' => '.id'], 1 => ['value' => ''], 2 => ['value' => 'x'], 3 => ['value' => '.old']],
		'out' => [], 'timeout' => '',
		'allowFailure' => 'inherit', 'allowFailureCodes' => '', 'save' => 'Save',
	],
);

Assert::same(
	['filter', 'filtr'],
	array_keys($leftover()[0]->in),
	'a filled-in undeclared field must not save'
);

// cleared, it saves and the key is gone
runWorkflowPresenterIn(
	$project,
	['action' => 'step', 'name' => 'leftover', 'at' => 'leftover.json:steps[0]', 'do' => 'stepForm-submit'],
	[
		'type' => 'run', 'name' => '',
		'in' => [0 => ['value' => '.id'], 1 => ['value' => ''], 2 => ['value' => 'x'], 3 => ['value' => '']],
		'out' => [], 'timeout' => '',
		'allowFailure' => 'inherit', 'allowFailureCodes' => '', 'save' => 'Save',
	],
);

Assert::same(['filter', 'stdin'], array_keys($leftover()[0]->in), 'cleared, the key is gone');
```

- [ ] **Step 17: Spusť celou sadu**

Run: `cd gui && vendor/bin/tester tests -C`
Expected: OK (43 tests)

Pokud padne `Rows.phpt` na `class="form-select"`, **neopravuj to tady** — select kanálu v `out` tam pořád je, takže tohle projít má. Padá-li to, znamená to, že jsi omylem sáhl i na `out`; vrať to a nech `out` na Task 5.

- [ ] **Step 18: PHPStan**

Run: `cd gui && vendor/bin/phpstan analyse`
Expected: `[OK] No errors`

Časté nálezy a jak je řešit: `cast.string` u `(string) $i` → `$i` je `int` z `foreach` nad `array<int, …>`, takže je to v pořádku; pokud PHPStan tvrdí opak, anotuj `$this->slots` jako `array<int, BlockInputSlot>` (past č. 2 — `list<>` nad kolekcí z `Donut\Format` neprojde, nad vlastní vlastností ano).

- [ ] **Step 19: Commit**

```bash
git add gui/src gui/tests
git commit -m "Build the run step's inputs from the block it calls"
```

---

### Task 5: Tři pevné výstupy

`out` přestává být seznamem řádků *kanál → klíč* a stává se třemi pojmenovanými poli.

**Files:**
- Modify: `gui/src/Presentation/Workflow/WorkflowPresenter.php` (kontejner `out`, smazat `rowShape()`)
- Modify: `gui/src/Presentation/Workflow/step.latte` (karta „Outputs to the map")
- Modify: `gui/src/StepMapper.php` (`toOut()`, `out` v `toValues()`)
- Modify: `gui/tests/StepMapper.phpt`
- Modify: `gui/tests/Rows.phpt`
- Modify: `gui/tests/WorkflowPresenter.step.phpt`

**Interfaces:**
- Consumes: `Donut\Format\RunStep::Channels`
- Produces: kontejner `out` se třemi textovými poli `stdout`, `stderr`, `exit_code`; POST tvar `out[stdout]`; `StepMapper::toValues()['out']` je mapa `kanál → klíč` se třemi klíči

- [ ] **Step 1: Napiš padající test na `StepMapper`**

V `gui/tests/StepMapper.phpt` nahraď aserce, které staví `out` z řádků (`['channel' => …, 'value' => …]`), tímhle — starý tvar zmiz, nový přibude:

```php
// out: three named fields, not rows. An empty field means the channel is
// not mapped; there is no such thing as a row with a channel and no key.
$run = StepMapper::toStep([
	'type' => 'run', 'name' => '', 'block' => 'jq',
	'in' => [],
	'out' => ['stdout' => 'cardId', 'stderr' => '', 'exit_code' => 'rc'],
	'timeout' => '', 'allowFailure' => 'inherit', 'allowFailureCodes' => '',
]);

Assert::type(RunStep::class, $run);
Assert::same(['stdout' => 'cardId', 'exit_code' => 'rc'], $run->out, 'an empty field is not a mapping');

// a channel the form cannot offer is ignored — the fields are built from
// RunStep::Channels, so anything else came from a hand-built POST
$forged = StepMapper::toStep([
	'type' => 'run', 'name' => '', 'block' => 'jq',
	'in' => [], 'out' => ['result' => 'x', 'stdout' => 'ok'],
	'timeout' => '', 'allowFailure' => 'inherit', 'allowFailureCodes' => '',
]);

Assert::same(['stdout' => 'ok'], $forged->out);

// and back: every channel is present, unmapped ones as an empty string, so
// setDefaults() has something to put in each of the three fields
$values = StepMapper::toValues(new RunStep(block: 'jq', out: ['stdout' => 'id']));

Assert::same(['stdout' => 'id', 'stderr' => '', 'exit_code' => ''], $values['out']);
```

Ostatní aserce v souboru, které `out` staví starým tvarem, přepiš stejným způsobem. Projdi si soubor celý — jsou tam nejméně čtyři.

- [ ] **Step 2: Spusť test a ověř, že padá**

Run: `cd gui && vendor/bin/tester tests/StepMapper.phpt -C`
Expected: FAIL — `toOut()` čeká řádky, takže `$run->out` vyjde `[]`.

- [ ] **Step 3: Přepiš `StepMapper::toOut()`**

V `gui/src/StepMapper.php` nahraď celou `toOut()`:

```php
	/**
	 * The three channels as three named fields. An empty field means the
	 * channel is not mapped — that is the only way to say it, so a row with a
	 * channel and no key no longer exists as a concept.
	 *
	 * Anything outside RunStep::Channels is ignored: the form offers exactly
	 * those three, so a different key came from a hand-built POST.
	 *
	 * @param  mixed $raw
	 * @return array<string, string>
	 */
	private static function toOut(mixed $raw): array
	{
		$out = [];

		foreach (RunStep::Channels as $channel) {
			$value = self::text(\is_array($raw) ? ($raw[$channel] ?? '') : '');

			if ($value !== '') {
				$out[$channel] = $value;
			}
		}

		return $out;
	}
```

V `toValues()` nahraď ve větvi `RunStep`:

```php
			$out = [];

			foreach ($step->out as $channel => $value) {
				$out[] = ['channel' => $channel, 'value' => $value];
			}
```

tímhle:

```php
			$out = [];

			// Every channel is present, unmapped ones as an empty string:
			// setDefaults() has to have something for each of the three
			// fields, and an absent key would leave the last value standing
			// after a failed submit.
			foreach (RunStep::Channels as $channel) {
				$out[$channel] = $step->out[$channel] ?? '';
			}
```

- [ ] **Step 4: Spusť test a ověř, že prochází**

Run: `cd gui && vendor/bin/tester tests/StepMapper.phpt -C`
Expected: PASS

- [ ] **Step 5: Přestav kontejner `out` ve formuláři**

V `gui/src/Presentation/Workflow/WorkflowPresenter.php` nahraď v `createComponentStepForm()`:

```php
			$out = $form->addContainer('out');

			foreach ($this->rowShape()['out'] as $i) {
				$row = $out->addContainer((string) $i);
				$row->addSelect('channel', null, \array_combine(RunStep::Channels, RunStep::Channels))
					->setPrompt('—')
					->setHtmlAttribute('aria-label', 'Block output');
				$row->addText('value')->setHtmlAttribute('aria-label', 'Map key');
			}
```

tímhle:

```php
			$out = $form->addContainer('out');

			// Three channels, three fields. A variable list of dropdowns was
			// machinery around a set that can never have a fourth member, and
			// where more than three rows was always a mistake.
			foreach (RunStep::Channels as $channel) {
				$out->addText($channel)
					// aria-label, see the in container above.
					->setHtmlAttribute('aria-label', $channel);
			}
```

Všechny tři kanály odpovídají `[a-zA-Z0-9_]+`, takže jdou použít přímo jako jména komponent.

- [ ] **Step 6: Smaž `rowShape()`**

Po tomhle už se `rowShape()` nikdo neptá. Smaž ji z `gui/src/Presentation/Workflow/WorkflowPresenter.php` celou i s docblockem — PHPStan level max hlásí nepoužitou privátní metodu jako chybu.

`RowShape` jako třída **zůstává**: používá ji `createComponentHeaderForm()` pro vstupy workflow a `BlockPresenter` pro argumenty a vstupy kamene. Ověř to: `grep -rn "RowShape" gui/src`.

- [ ] **Step 7: Přepiš kartu výstupů v šabloně**

V `gui/src/Presentation/Workflow/step.latte` nahraď celou kartu `Outputs to the map` tímhle:

```latte
			<div class="card mb-3">
				<div class="card-header">Outputs to the map</div>
				<div class="card-body">
					<p class="text-muted small">Under which key in the engine map to store each
						channel. An empty field means the channel is thrown away.</p>

					<p n:foreach="$form['out']->getComponents() as $channel => $field">
						<label for="{$field->getHtmlId()}">{$channel}</label> {input $field}
					</p>
				</div>
			</div>
```

- [ ] **Step 8: Odpoj `rows.js` od stránky kroku**

`step.latte` už nemá jediný `js-row` ani `data-add`. Ověř to:

```bash
grep -n "js-row\|js-del-row\|data-add\|tbody id=" gui/src/Presentation/Workflow/step.latte
```

Expected: žádný výstup.

Skript samotný **neodstraňuj a v layoutu ho nech** — načítá ho `@layout.latte` pro všechny stránky a používá ho editace kamene i hlavička workflow. `Layout.phpt` na jeho přítomnost tvrdí.

- [ ] **Step 9: Sesouhlas `Rows.phpt`**

V `gui/tests/Rows.phpt` v sekci „the step page" smaž:

```php
Assert::contains('<th scope=col>Block output</th>', $stepHtml);
Assert::contains('<th scope=col>Map key</th>', $stepHtml);
Assert::contains('<tbody id=out>', $stepHtml);
Assert::contains('data-add=out', $stepHtml);
Assert::same(2, \substr_count($stepHtml, 'js-del-row" aria-label="Delete row"'));
Assert::match('~name="out\[0\]\[channel\]"[^>]*aria-label="Block output"~', $stepHtml);
Assert::match('~name="out\[0\]\[value\]"[^>]*aria-label="Map key"~', $stepHtml);
Assert::same(2, \substr_count($stepHtml, '<div class=table-responsive>'));
Assert::match('~name="out\[0\]\[value\]"[^>]*value="id"~', $stepHtml);
```

a nahraď je:

```php
// Three named fields, no table and no add/delete buttons: the set of
// channels is fixed and a fourth row was always a mistake.
Assert::notContains('<tbody id=out>', $stepHtml);
Assert::notContains('data-add=out', $stepHtml);
Assert::notContains('js-del-row', $stepHtml, 'the step page has no variable rows left');
Assert::match('~name="out\[stdout\]"[^>]*aria-label="stdout"~', $stepHtml);
Assert::match('~name="out\[stderr\]"[^>]*aria-label="stderr"~', $stepHtml);
Assert::match('~name="out\[exit_code\]"[^>]*aria-label="exit_code"~', $stepHtml);
Assert::match('~name="out\[stdout\]"[^>]*value="id"~', $stepHtml);

// only the in table is left, and it still scrolls on a narrow window
Assert::same(1, \substr_count($stepHtml, '<div class=table-responsive>'));
```

- [ ] **Step 10: Zachraň aserci na `form-select`**

`Rows.phpt` má na řádku okolo 188:

```php
// WorkflowPresenter: step (plus a dropdown)
Assert::contains('class="form-control"', $stepHtml);
Assert::contains('class="form-select"', $stepHtml);
```

Krok `run` už žádný `<select>` nemá — jediný, který ve `WorkflowPresenter` zbyl, je operátor podmínky u kroku `if`. Nahraď ty dva řádky tímhle:

```php
// WorkflowPresenter: step. The run step has no dropdown any more — the
// block is text and the channels are three fields — so the select is
// asserted on the if step, where the operator still is one. Without this
// the suite would pass even if the form reverted to plain new Form.
Assert::contains('class="form-control"', $stepHtml);

[, $ifHtml] = runWorkflowPresenterIn($step, [
	'action' => 'step', 'name' => 'w', 'at' => 'w.json:steps[1]',
]);
Assert::contains('class="form-select"', $ifHtml);
```

A do fixtury workflow v `Rows.phpt` (`$step . '/workflows/w.json'`) přidej druhý krok, aby `steps[1]` existoval:

```php
FileSystem::write($step . '/workflows/w.json', \json_encode([
	'name' => 'w',
	'steps' => [
		[
			'type' => 'run',
			'block' => 'jq',
			'in' => ['filter' => '.id'],
			'out' => ['stdout' => 'id'],
		],
		['type' => 'if', 'condition' => ['left' => '{%id%}', 'op' => 'not_empty'], 'then' => []],
	],
]));
```

- [ ] **Step 11: Sesouhlas POST těla ve `WorkflowPresenter.step.phpt`**

Nahraď všechna těla `'out' => [0 => ['channel' => …, 'value' => …]]` novým tvarem. V sekci „saving the edit":

```php
		'out' => ['stdout' => 'title', 'stderr' => '', 'exit_code' => ''],
```

V sekcích, kde je dnes `'out' => []`, nech `'out' => []` — chybějící klíče `toOut()` přečte jako prázdné a nic nenamapuje.

Aserci pod tím nech: `Assert::same(['stdout' => 'title'], $run->out);`.

V sekci „a foreign POST must not empty the step form" nahraď:

```php
Assert::match('~name="out\[0\]\[value\]"[^>]*value="id"~', $html, "the step's outputs must not be lost");
```

tímhle:

```php
Assert::match('~name="out\[stdout\]"[^>]*value="id"~', $html, "the step's outputs must not be lost");
```

- [ ] **Step 12: Spusť celou sadu**

Run: `cd gui && vendor/bin/tester tests -C`
Expected: OK (43 tests)

- [ ] **Step 13: PHPStan v obou projektech**

```bash
cd gui && vendor/bin/phpstan analyse
cd .. && vendor/bin/phpstan analyse
```
Expected: dvakrát `[OK] No errors`

- [ ] **Step 14: Kořenová sada**

Run: `vendor/bin/tester tests -C` (z kořene repozitáře)
Expected: OK (34 tests) — donut se neměnil, tohle je jen kontrola.

- [ ] **Step 15: Commit**

```bash
git add gui/src gui/tests
git commit -m "Map the three output channels with three fixed fields"
```

---

### Task 6: Dokumentace

**Files:**
- Modify: `gui/readme.md` (sekce „Co je vidět")

**Interfaces:**
- Consumes: hotové chování z Tasků 1–5
- Produces: nic, co by další task konzumoval

- [ ] **Step 1: Popiš nové chování**

V `gui/readme.md` v seznamu „Co je vidět" nahraď odrážku začínající **„editace kroku"** tímhle:

```markdown
- **editace kroku** — u každého kroku odkaz „edit" na formulář podle jeho
  typu (`run`, `set`, `if`, `foreach`); pod stromem i v každé vnořené větvi
  jde krok daného typu přidat, přesunout nahoru/dolů nebo smazat (mazání
  krokem s podstromem se ptá na potvrzení)
- **krok `run` zná svůj kámen** — nový `run` se zakládá přes výběr kamene
  z karet, takže formulář má **pevný seznam vstupů**: jeden řádek na vstup,
  který kámen deklaruje, plus `stdin`, když ho kámen čte. Povinné vstupy
  formulář vynutí podle stejného pravidla jako validátor. Prázdný vstup se do
  souboru nezapíše — prázdná hodnota by umlčela default kamene, chybějící
  klíč ho pustí ke slovu. Klíč, který kámen nedeklaruje, je vidět s hláškou
  a uložit jde až po jeho vyprázdnění. Kámen se v editaci nepřepíná: jiný
  kámen znamená jiný krok. Výstupy do mapy jsou tři pole (`stdout`, `stderr`,
  `exit_code`); prázdné pole znamená, že se kanál zahodí
```

- [ ] **Step 2: Doplň, co GUI vědomě neumí**

V `gui/readme.md` do seznamu „Co GUI vědomě neumí" přidej odrážku:

```markdown
- **přepnout kámen u existujícího kroku** — vstupy jsou pevné podle kamene
  a formulář nemá jak poznat, které hodnoty patří do nového; jiný kámen je
  jiný krok, tedy smazat a založit znovu
```

- [ ] **Step 3: Ověř, že readme nelže**

```bash
grep -n "selectbox\|proměnlivý seznam" gui/readme.md
```
Expected: žádný výstup, který by mluvil o vstupech nebo výstupech kroku.

- [ ] **Step 4: Commit**

```bash
git add gui/readme.md
git commit -m "Document the run step's fixed inputs and outputs"
```

---

## Self-review

**Pokrytí specifikace.** Každá sekce specifikace má task: `BlockInputs` → Task 1 (včetně `rows()` a smlouvy s validátorem), `Workflow:pickBlock` → Task 2, `actionStep()` → Task 3, `createComponentStepForm()` větev `in` + `step.latte` + zmizení selectu + `toValues()` bez `in` → Task 4, `out` + `toOut()` + odpojení `rows.js` → Task 5, dokumentace → Task 6. „Co se vědomě neřeší" ze specifikace nevyžaduje žádný task.

**Odchylka od specifikace, kterou plán nese vědomě.** Specifikace chce u karty `Block inputs` tři sloupce (`jméno vstupu, hodnota, poznámka`), `description` jako nápovědu *pod polem* a nedeklarované sloty „pod tabulkou ve vlastním bloku s vysvětlením". Task 4, krok 8 místo toho předepisuje dva sloupce, `description` pod *jménem* vstupu a nedeklarované sloty přímo v tabulce — a implementace plán následuje. Je to obhajitelné: dva sloupce lépe snesou úzké okno a nedeklarované sloty v tabulce drží vizuální pořadí totožné s pozičním indexem, kterým POST spojuje hodnoty zpátky se sloty. Odstavec o pokrytí specifikace výše to ale nezmiňuje, a proto je to zapsané tady — aby další čtenář nepovažoval třísloupcovou tabulku ze specifikace za stále závaznou. Rozhodnutí zůstává, měnit rozvržení není potřeba.

**Aserce na značkování, které z šablon mizí.** Grep přes `gui/tests/*.phpt` na `js-row`, `js-del-row`, `data-add`, `tbody id=`, `Block input`, `Map key`, `channel`, `form-select`, `+ step` našel zásahy v `Rows.phpt` (Tasky 4 a 5), `WorkflowPresenter.step.phpt` (Tasky 3–5), `StepMapper.phpt` (Tasky 4 a 5) a `WorkflowPresenter.detailRender.phpt` / `controls.phpt` (aserce na `class="add"` a `>then</span>` přežívají beze změny, odkaz `run` uvnitř `class="add"` zůstává odkazem). Všechny jsou v seznamech měněných souborů.

**Past, na kterou plán schválně upozorňuje.** `Assert::contains('class="form-select"', $stepHtml)` v `Rows.phpt` přežije Task 4 (selecty kanálů tam ještě jsou) a padne až v Tasku 5, kde stránka kroku `run` přijde o poslední `<select>`. Krok 10 Tasku 5 to řeší přesunem aserce na krok `if`, ne jejím smazáním.

**Konzistence jmen.** `BlockInputs::slots()`/`rows()`, `BlockInputSlot`, `WorkflowPresenter::$block`/`$slots`, `WorkflowStepTemplate::$block`/`$slots`, `WorkflowPickBlockTemplate::$blocks`/`$error`/`$dir`/`$name`/`$at` — použité v pozdějších taskech přesně tak, jak je zavádí ty dřívější.
