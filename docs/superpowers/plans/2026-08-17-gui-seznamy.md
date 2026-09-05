# Seznamy a detail kamene — implementační plán

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Udělat z přehledu workflow a přehledu kamenů tabulky s akcemi a dát kamenům vlastní stránku detailu, aby měly stejný tvar jako workflow: přehled → detail → editace.

**Architecture:** Dvě šablony se přepíšou na `<table>` uvnitř `.table-responsive`, stejně jako to od minulého projektu mají tabulky ve formulářích. Kámen dostane novou akci `BlockPresenter::actionDetail()`, novou šablonovou třídu a šablonu; data si bere ze stejných zdrojů jako editace (`store()->get()`, `BlockUsage::of()`), takže nevzniká žádná nová cesta k datům. Mazání zůstává na `Block:edit`.

**Tech Stack:** PHP 8.3+, Nette 3 (application, forms ^3.2), Latte 3, nette/tester ^2.6, PHPStan level max, Bootstrap 5.3.8 (vendorovaný)

**Spec:** `docs/superpowers/specs/2026-08-17-gui-seznamy-design.md`

## Global Constraints

- PHP 8.3+ v `gui/`, PHP 8.1+ v kořeni; Nette 3, Latte 3, nette/tester
- PHPStan **level max** nad `src` i `tests` v obou balících musí zůstat čistý
- GUI nesmí sahat do `../src` ani `../vendor` relativní cestou
- **Do `docs/workflows/` se nesmí zapsat nic** — zkoušej nad kopiemi v `/tmp`
- **Past v kopírování fixtury:** `docs/workflows/donut` sám obsahuje `blocks/` a `workflows/`. Kopíruj jeho **obsah** rovnou do pracovního adresáře, ne do podadresáře jménem `workflows`.
- Server jen jako `php -S 127.0.0.1:<port> -t <gui/www> <gui/www/index.php>` — router script je povinný. V `gui/` je na to cíl: `make server port=<port> project=<adresář>`.
- `git add` **vždy s explicitními cestami**, nikdy `git add -A` ani `git add .`
- Nikdy se nesmí commitnout: `.github/workflows/frontbot.yml`, `docs/logo.png`, `donut-org_donut.sublime-workspace`, `rss`, `gui/Makefile`
- Mutace vracej **kopií ze zálohy mimo repozitář** (`cp`), nikdy `git checkout -- <soubor>`, nikdy `git stash`
- **Žádná stávající aserce se nesmí oslabit.** Kde se značkování mění, aserce se přepíše na novou — a v hlášení se každá taková změna vyjmenuje i s tím, co tvrdila dřív.
- Chybové hlášky zůstávají jako dnešní odstavce s `class=error`; alerty jsou projekt B2. Strom kroků ani sekce formulářů se nedotýkej.
- **Mazání kamene zůstává na `Block:edit`**, detail je jen ke čtení. Chrání ho kontrola `$usedBy` a dvě cesty k téže nevratné operaci znamenají dvě místa, kde ta kontrola může chybět — taková asymetrie mezi kamenem a workflow už v tomhle GUI dvakrát způsobila ztrátu dat.
- Zprávy commitů česky, ve stylu ostatních („GUI: …")
- Latte vypisuje `href` (z `n:href`) **před** `class` — regulární výrazy na pořadí atributů nesázej
- Literál `{%klíč%}` jde v Latte napsat jedině jako `{='{%klíč%}'}`
- Pasti PHPStanu level max: `(string) $mixed` je `cast.string`; `list<X>` neprojde tam, kde `Donut\Format` deklaruje `array<int, X>`; `$form::Filled` musí být `Form::Filled`; `$nullable?->prop ?? $default` hlásí `nullsafe.neverNull` — rozděl do mezipro­měnné

---

## Struktura souborů

**Nové:**

| soubor | odpovědnost |
|---|---|
| `gui/src/Presentation/Block/BlockDetailTemplate.php` | vlastnosti šablony detailu kamene |
| `gui/src/Presentation/Block/detail.latte` | stránka detailu kamene |
| `gui/tests/WorkflowPresenter.list.phpt` | tabulka přehledu workflow |
| `gui/tests/BlockPresenter.detail.phpt` | nová stránka detailu |

**Měněné:**

| soubor | co |
|---|---|
| `gui/src/Presentation/Workflow/default.latte` | `<ul>` → tabulka |
| `gui/src/Presentation/Block/default.latte` | rozbalený výpis → tabulka |
| `gui/src/Presentation/Block/BlockPresenter.php` | `actionDetail()`, `renderDetail()`, vlastnost `$detail` |
| `gui/src/Presentation/Block/edit.latte` | drobečky `Kameny / jq / úprava` |
| `gui/tests/Layout.phpt` | drobečky detailu a upravené drobečky editace |
| `gui/tests/BlockPresenter.default.phpt` | aserce na „používá" → jméno workflow |
| `gui/tests/BlockPresenter.delete.phpt` | tytéž dvě aserce |

**Pořadí tasků je závazné:** detail musí existovat dřív, než na něj tabulka kamenů začne odkazovat.

---

### Task 1: Přehled workflow jako tabulka

**Files:**
- Modify: `gui/src/Presentation/Workflow/default.latte`
- Create: `gui/tests/WorkflowPresenter.list.phpt`

**Interfaces:**
- Consumes: `WorkflowDefaultTemplate` s vlastnostmi `$workflows` (`array<string, Workflow|string>`), `$error`, `$dir` — beze změny
- Produces: nic pro pozdější tasky

Vlastnost, na které záleží nejvíc: **odkaz „upravit" musí dostat i řádek workflow, které se nenaparsovalo.** Je to jediná cesta k jeho opravě a smazání a předchozí projekt ji musel doplňovat jako Important nález.

- [ ] **Step 1: Napiš padající test**

Vytvoř `gui/tests/WorkflowPresenter.list.phpt`:

```php
<?php

declare(strict_types=1);

use Nette\Utils\FileSystem;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/inc/workflowPresenter.php';

$dir = TEMP_DIR . '/projekt';
FileSystem::createDir($dir . '/workflows');
FileSystem::write($dir . '/workflows/sync.json', \json_encode([
	'name' => 'sync',
	'description' => 'Synchronizuje kartu',
	'steps' => [],
]));
FileSystem::write($dir . '/workflows/rozbite.json', 'toto neni json');

[, $html] = runWorkflowPresenterIn($dir, ['action' => 'default']);

// hlavičky sloupců
Assert::contains('<th scope=col>Jméno</th>', $html);
Assert::contains('<th scope=col>Popis</th>', $html);

// tabulka se na úzkém okně posouvá, nemačká
Assert::contains('table-responsive', $html);

// platné workflow: jméno je odkaz na detail, popis je vidět
Assert::match('~<a href="[^"]*action=detail[^"]*">sync</a>~', $html);
Assert::contains('Synchronizuje kartu', $html);

// rozbité workflow: jméno není odkaz na detail, chyba je vidět
Assert::notMatch('~<a href="[^"]*">rozbite</a>~', $html);
Assert::contains('<strong>rozbite</strong>', $html);
Assert::contains('class=error', $html);

// a hlavně: i rozbitý řádek má cestu k opravě a mazání
Assert::match('~<a href="[^"]*name=rozbite[^"]*">upravit</a>~', $html);
Assert::match('~<a href="[^"]*name=sync[^"]*">upravit</a>~', $html);

// starý seznam je pryč
Assert::notContains('<ul>', $html);
```

- [ ] **Step 2: Pusť test a ověř, že padá**

Run: `cd gui && vendor/bin/tester tests/WorkflowPresenter.list.phpt -C`
Expected: FAIL na `<th scope=col>Jméno</th>`

- [ ] **Step 3: Přepiš `Workflow/default.latte`**

Nahraď blok od `<ul n:if="$workflows">` po `</ul>`:

```latte
<div class=table-responsive n:if="$workflows">
	<table class="table table-sm align-middle">
		<thead>
			<tr>
				<th scope=col>Jméno</th>
				<th scope=col>Popis</th>
				<th scope=col><span class=visually-hidden>Akce</span></th>
			</tr>
		</thead>
		<tbody>
			<tr n:foreach="$workflows as $name => $workflow">
				<td>
					{if is_string($workflow)}
						<strong>{$name}</strong>
					{else}
						<a n:href="detail $name">{$name}</a>
					{/if}
				</td>
				<td>
					{if is_string($workflow)}
						<span class=error>{$workflow}</span>
					{else}
						{$workflow->description}
					{/if}
				</td>
				{* Odkaz na úpravu je mimo podmínku schválně: workflow, které se
				   nenaparsuje, je ten jediný, u kterého uživatel cestu k opravě
				   a k mazání potřebuje nejvíc. Stejně to má Block/default.latte. *}
				<td><a n:href="Workflow:edit, name: $name">upravit</a></td>
			</tr>
		</tbody>
	</table>
</div>
```

Komentář o odkazu na úpravu přesuň z původního místa sem — vysvětluje vlastnost, kterou předchozí projekt opravoval, a nesmí zmizet.

- [ ] **Step 4: Pusť test a ověř, že prochází**

Run: `cd gui && vendor/bin/tester tests/WorkflowPresenter.list.phpt -C`
Expected: PASS

- [ ] **Step 5: Pusť obě sady a PHPStan**

```bash
cd /home/honza/Dokumenty/Projekty/donut-org/donut
vendor/bin/tester tests -C
cd gui && vendor/bin/tester tests -C && vendor/bin/phpstan analyse -c phpstan.neon --no-progress
```

Expected: gui 35 testů OK (34 + nový), donut 32 OK, PHPStan čistý. **Pokud spadne jiný test, vypiš který a co tvrdil** — `WorkflowPresenter.broken.phpt:27` se ptá na `class=error` v seznamu a `WorkflowPresenter.envelope.phpt:125` na seznam taky; obojí by projít mělo, protože `class=error` i jména zůstávají.

- [ ] **Step 6: Ověř mutací**

Zazálohuj `gui/src/Presentation/Workflow/default.latte` (`cp` mimo repozitář), pak přesuň odkaz „upravit" dovnitř `{else}` větve (tedy jen pro platná workflow).

Run: `cd gui && vendor/bin/tester tests/WorkflowPresenter.list.phpt -C`
Expected: FAIL na `name=rozbite`

Vrať `cp` ze zálohy. **Nikdy `git checkout --`.**

- [ ] **Step 7: Commit**

```bash
cd /home/honza/Dokumenty/Projekty/donut-org/donut
git add gui/src/Presentation/Workflow/default.latte gui/tests/WorkflowPresenter.list.phpt
git commit -m "GUI: přehled workflow jako tabulka"
```

---

### Task 2: Stránka `Block:detail`

**Files:**
- Create: `gui/src/Presentation/Block/BlockDetailTemplate.php`
- Create: `gui/src/Presentation/Block/detail.latte`
- Create: `gui/tests/BlockPresenter.detail.phpt`
- Modify: `gui/src/Presentation/Block/BlockPresenter.php`
- Modify: `gui/src/Presentation/Block/edit.latte`
- Modify: `gui/tests/Layout.phpt`

**Interfaces:**
- Consumes: `BlockPresenter::store(): BlockStore` a `loadWorkflows(): array<string, Workflow>` — obojí už v prezentéru existuje jako privátní metoda; `BlockUsage::of()` vrací mapu jméno kamene → seznam workflow
- Produces: akce `Block:detail` s parametrem `name`, na kterou bude v Tasku 3 odkazovat tabulka kamenů

`Donut\Format\Block` má vlastnosti `name`, `command`, `args`, `inputs`, `stdin`, `timeout`, `allowFailure`, `description`. `args` je pole skupin, každá skupina je pole řetězců. `stdin` je `?StdinSpec` s `required` a `description`.

- [ ] **Step 1: Napiš padající test**

Vytvoř `gui/tests/BlockPresenter.detail.phpt`:

```php
<?php

declare(strict_types=1);

use Nette\Utils\FileSystem;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/inc/blockPresenter.php';

$dir = TEMP_DIR . '/projekt';
FileSystem::createDir($dir . '/blocks');
FileSystem::createDir($dir . '/workflows');

FileSystem::write($dir . '/blocks/curl-get.json', \json_encode([
	'name' => 'curl-get',
	'description' => 'Stáhne adresu',
	'command' => 'curl',
	'args' => [['-sS'], ['-H', '{%hlavicka%}'], ['{%url%}']],
	'inputs' => [
		'url' => ['required' => true, 'description' => 'Úplná adresa'],
		'hlavicka' => ['required' => false, 'default' => 'Accept: */*'],
	],
	'stdin' => ['required' => false, 'description' => 'Tělo požadavku'],
	'timeout' => 30,
	'allow_failure' => [0, 22],
]));

FileSystem::write($dir . '/workflows/sync.json', \json_encode([
	'name' => 'sync',
	'steps' => [['type' => 'run', 'block' => 'curl-get', 'in' => ['url' => 'x']]],
]));

[, $html] = runBlockPresenterIn($dir, ['action' => 'detail', 'name' => 'curl-get']);

// příkaz a popis
Assert::contains('curl', $html);
Assert::contains('Stáhne adresu', $html);

// argumenty — dnešní přehled je nevypisuje vůbec, detail je vypsat musí
Assert::contains('-sS', $html);
Assert::contains('-H', $html);

// vstupy i s povinností a výchozí hodnotou
Assert::contains('url', $html);
Assert::contains('povinný', $html);
Assert::contains('Úplná adresa', $html);
Assert::contains('Accept: */*', $html);

// stdin, timeout, allow_failure
Assert::contains('Tělo požadavku', $html);
Assert::contains('30', $html);
Assert::contains('22', $html);

// kdo kámen používá
Assert::contains('sync', $html);

// cesta na editaci
Assert::match('~<a href="[^"]*action=edit[^"]*">upravit</a>~', $html);


// --- rozbitý kámen musí jít otevřít ---
FileSystem::write($dir . '/blocks/rozbity.json', 'toto neni json');

[, $rozbity] = runBlockPresenterIn($dir, ['action' => 'detail', 'name' => 'rozbity']);

Assert::contains('class=error', $rozbity);
Assert::match('~<a href="[^"]*action=edit[^"]*">upravit</a>~', $rozbity);
```

- [ ] **Step 2: Pusť test a ověř, že padá**

Run: `cd gui && vendor/bin/tester tests/BlockPresenter.detail.phpt -C`
Expected: FAIL — akce `detail` neexistuje

- [ ] **Step 3: Napiš `BlockDetailTemplate`**

Vytvoř `gui/src/Presentation/Block/BlockDetailTemplate.php`:

```php
<?php

declare(strict_types=1);

namespace Donut\Gui\Presentation\Block;

use Donut\Format\Block;
use Nette\Bridges\ApplicationLatte\Template;


final class BlockDetailTemplate extends Template
{
	public ?string $name = null;

	/** Null znamená, že se soubor nenaparsoval — pak je vyplněný $error. */
	public ?Block $block = null;

	public ?string $error = null;

	/** @var list<string> jména workflow, která kámen volají */
	public array $usedBy = [];
}
```

- [ ] **Step 4: Přidej akci do `BlockPresenter`**

K existující vlastnosti `private ?Block $edited = null;` přidej:

```php
	private ?Block $detail = null;
```

a za `renderDefault()` vlož:

```php
	public function actionDetail(string $name): void
	{
		try {
			$this->detail = $this->store()->get($name);

		} catch (ParseException $e) {
			// Chybějící adresář i nenaparsovatelný soubor končí stejně:
			// stránka se vykreslí s hláškou a s odkazem na editaci, protože
			// rozbitý kámen je ten, u kterého je cesta k opravě potřeba
			// nejvíc. Totéž pravidlo má Workflow:detail.
			/** @var BlockDetailTemplate $template */
			$template = $this->template;
			$template->error = $e->getMessage();
		}
	}


	public function renderDetail(string $name): void
	{
		/** @var BlockDetailTemplate $template */
		$template = $this->template;
		$template->name = $name;
		$template->block = $this->detail;
		$template->usedBy = BlockUsage::of($this->loadWorkflows())[$name] ?? [];
	}
```

- [ ] **Step 5: Napiš `detail.latte`**

Vytvoř `gui/src/Presentation/Block/detail.latte`:

```latte
{block title}{$name} — Donut{/block}

{block breadcrumbs}
	<li class=breadcrumb-item><a n:href="Block:default">Kameny</a></li>
	<li class="breadcrumb-item active" aria-current=page>{$name}</li>
{/block}

{block content}
<h1>{$name}</h1>

{* Odkaz na editaci je mimo podmínku schválně: rozbitý kámen je ten, u kterého
   uživatel cestu k opravě a k mazání potřebuje nejvíc. *}
<p><a n:href="Block:edit, name: $name">upravit</a></p>

<p n:if="$error" class=error>{$error}</p>

{if $block !== null}
	<p n:if="$block->description">{$block->description}</p>

	<p>příkaz: <code>{$block->command}</code></p>

	<h2 n:if="$block->args">Argumenty</h2>
	<ul n:if="$block->args">
		<li n:foreach="$block->args as $group">
			{foreach $group as $arg}<code>{$arg}</code>{sep} {/sep}{/foreach}
		</li>
	</ul>

	<h2 n:if="$block->inputs">Vstupy</h2>
	<ul n:if="$block->inputs">
		<li n:foreach="$block->inputs as $input">
			<code>{$input->name}</code>
			{$input->required ? 'povinný' : 'volitelný'}
			{if $input->default !== null}(výchozí <code>{$input->default}</code>){/if}
			{$input->description}
		</li>
	</ul>

	<p n:if="$block->stdin">
		čte <strong>stdin</strong> —
		{$block->stdin->required ? 'povinný' : 'volitelný'}
		{$block->stdin->description}
	</p>

	<p n:if="$block->timeout !== null">timeout: {$block->timeout} s</p>
	<p n:if="$block->allowFailure !== false">
		allow_failure: {is_array($block->allowFailure) ? implode(', ', $block->allowFailure) : 'jakýkoliv exit kód'}
	</p>

	<h2 n:if="$usedBy">Používá</h2>
	<ul n:if="$usedBy">
		<li n:foreach="$usedBy as $workflow">{$workflow}</li>
	</ul>
{/if}
```

- [ ] **Step 6: Pusť test a ověř, že prochází**

Run: `cd gui && vendor/bin/tester tests/BlockPresenter.detail.phpt -C`
Expected: PASS

- [ ] **Step 7: Posuň drobečky editace kamene**

V `gui/src/Presentation/Block/edit.latte` nahraď blok drobečků:

```latte
{block breadcrumbs}
	<li class=breadcrumb-item><a n:href="Block:default">Kameny</a></li>
	<li n:if="$name !== null" class=breadcrumb-item><a n:href="Block:detail, name: $name">{$name}</a></li>
	<li class="breadcrumb-item active" aria-current=page>{$name === null ? 'nový kámen' : 'úprava'}</li>
{/block}
```

Bez toho by detail i editace visely pod týmž drobečkem a z editace by nevedla cesta na detail. Je to týž tvar, jaký má workflow (`Workflow / card-dev / hlavička`).

- [ ] **Step 8: Uprav a rozšiř `Layout.phpt`**

Dnešní aserce na řádku 96 očekává u `Block:edit` jméno kamene jako poslední aktivní položku:

```php
Assert::match('~<li class="breadcrumb-item active" aria-current=page>k</li>~', $blockEdit);
```

Nahraď ji dvojicí, která popisuje nový tvar — jméno je nově odkaz, poslední je `úprava`:

```php
Assert::match('~<li class=breadcrumb-item><a href="[^"]*">k</a></li>~', $blockEdit);
Assert::match('~<li class="breadcrumb-item active" aria-current=page>úprava</li>~', $blockEdit);
```

A přidej na konec souboru drobečky nové stránky:

```php
// drobečky Block:detail: sekce je odkaz, jméno kamene poslední
[, $blockDetail] = runBlockPresenterIn($dir, ['action' => 'detail', 'name' => 'k']);
Assert::match('~<li class=breadcrumb-item><a href="[^"]*">Kameny</a></li>~', $blockDetail);
Assert::match('~<li class="breadcrumb-item active" aria-current=page>k</li>~', $blockDetail);
```

- [ ] **Step 9: Pusť obě sady a PHPStan**

```bash
cd /home/honza/Dokumenty/Projekty/donut-org/donut
vendor/bin/tester tests -C
cd gui && vendor/bin/tester tests -C && vendor/bin/phpstan analyse -c phpstan.neon --no-progress
```

Expected: gui 36 testů OK, donut 32 OK, PHPStan čistý. `Latte.TemplatesCompile.phpt` musí zkompilovat i novou `detail.latte`.

- [ ] **Step 10: Ověř mutacemi**

Obojí se zálohou `cp` mimo repozitář a návratem `cp`:

1. V `detail.latte` přesuň odkaz „upravit" dovnitř `{if $block !== null}`.
   Run: `cd gui && vendor/bin/tester tests/BlockPresenter.detail.phpt -C`
   Expected: FAIL na rozbitém kameni

2. V `edit.latte` smaž z drobečků prostřední `<li>` s odkazem na detail.
   Run: `cd gui && vendor/bin/tester tests/Layout.phpt -C`
   Expected: FAIL

- [ ] **Step 11: Commit**

```bash
cd /home/honza/Dokumenty/Projekty/donut-org/donut
git add gui/src/Presentation/Block/BlockDetailTemplate.php gui/src/Presentation/Block/detail.latte \
        gui/src/Presentation/Block/BlockPresenter.php gui/src/Presentation/Block/edit.latte \
        gui/tests/BlockPresenter.detail.phpt gui/tests/Layout.phpt
git commit -m "GUI: kámen má vlastní stránku detailu"
```

---

### Task 3: Přehled kamenů jako tabulka

**Files:**
- Modify: `gui/src/Presentation/Block/default.latte`
- Modify: `gui/tests/BlockPresenter.default.phpt`
- Modify: `gui/tests/BlockPresenter.delete.phpt`

**Interfaces:**
- Consumes: akce `Block:detail` z Tasku 2; `BlockDefaultTemplate` s vlastnostmi `$blocks` (`array<string, Block|string>`), `$error`, `$dir`, `$usage` — beze změny
- Produces: nic pro pozdější tasky

Dnešní šablona vypisuje **celý obsah každého kamene**. Ten výpis se ruší — je teď na `Block:detail`. Zůstane tabulka se čtyřmi sloupci.

**Tenhle task přepisuje tři stávající aserce.** Všechny visí na českém slově „používá", které dnes stojí ve větě pod nadpisem kamene; v tabulce se z něj stane hlavička sloupce „Používá" s velkým „P". Přepíšou se na jméno workflow v řádku, což je silnější tvrzení než shoda českého slova.

- [ ] **Step 1: Přepiš aserce v `BlockPresenter.default.phpt`**

Na řádku 25 stojí:

```php
Assert::notContains('používá', $html);
```

Nahraď ji tvrzením o hlavičce a o prázdné buňce — fixtura téhle sekce žádné workflow nemá:

```php
// Kámen, který nikdo nepoužívá, má buňku „Používá" prázdnou. Ptát se na
// nepřítomnost slova „používá" už nejde — je z něj hlavička sloupce.
Assert::contains('<th scope=col>Používá</th>', $html);
Assert::match('~<td>\s*</td>~', $html);
```

Pak přidej na **konec** souboru novou sekci. Rozbitý kámen dnes na přehledu netestuje **nic** — jediný rozbitý kámen v celé sadě se zkouší na stránce editace (`BlockPresenter.delete.phpt:86`). Task přitom právě to značkování přepisuje, takže tuhle díru zavře:

```php
// --- rozbitý kámen v přehledu: chyba je vidět a cesta k opravě zůstává ---
// Nenaparsovatelný soubor je ten, u kterého uživatel cestu k opravě a mazání
// potřebuje nejvíc. U workflow to musel doplňovat až předchozí projekt jako
// Important nález; u kamenů to do teď nehlídala žádná aserce.

$sRozbitym = TEMP_DIR . '/s-rozbitym';
FileSystem::createDir($sRozbitym . '/blocks');
FileSystem::write($sRozbitym . '/blocks/dobry.json', json_encode([
	'name' => 'dobry', 'command' => 'echo', 'args' => [],
]));
FileSystem::write($sRozbitym . '/blocks/rozbity.json', 'toto neni json');

[, $html] = runBlockPresenterIn($sRozbitym, ['action' => 'default']);

Assert::contains('<strong>rozbity</strong>', $html);
Assert::contains('class=error', $html);
Assert::match('~<a href="[^"]*name=rozbity[^"]*">upravit</a>~', $html);

// dobrý kámen vedle něj zůstane odkazem na detail
Assert::match('~<a href="[^"]*action=detail[^"]*">dobry</a>~', $html);
```

- [ ] **Step 2: Přepiš aserce v `BlockPresenter.delete.phpt`**

Na řádcích 32 a 33 stojí dvojice, která se ptá na přehled:

```php
Assert::contains('používá', $html);
Assert::contains('w', $html);
```

Nahraď ji jednou přesnou aserci na buňku ve sloupci „Používá". Fixtura toho testu má kámen `pouzity`, který volá workflow **`w`**, a kámen `volny`, který nevolá nikdo:

```php
// Tabulka ukazuje, které workflow kámen volá. Dřív se tu hlídalo slovo
// „používá" z věty pod nadpisem — v tabulce je z něj hlavička sloupce.
// Ptáme se proto na obsah buňky; `contains('w')` samotné nic netvrdilo,
// protože písmeno w je v HTML všude (workflow, www).
Assert::match('~<td>\s*w\s*</td>~', $html);
```

**Na řádku 46 aserci neměň.** Ta míří na stránku *editace* použitého kamene, kde větu „Nejde smazat — používá ho: w." vypisuje `Block/edit.latte:134` — tenhle task se jí nedotýká. Totéž platí pro `contains('class=error')` na řádku 89.

- [ ] **Step 3: Pusť je a ověř, že padají**

Run: `cd gui && vendor/bin/tester tests/BlockPresenter.default.phpt tests/BlockPresenter.delete.phpt -C`
Expected: FAIL na `<th scope=col>Používá</th>` — tabulka zatím neexistuje

- [ ] **Step 4: Přepiš `Block/default.latte`**

Nahraď celý blok `<div n:foreach="$blocks as $name => $block">` … `</div>` (tedy rozbalený výpis) tabulkou:

```latte
<div class=table-responsive n:if="$blocks">
	<table class="table table-sm align-middle">
		<thead>
			<tr>
				<th scope=col>Jméno</th>
				<th scope=col>Příkaz</th>
				<th scope=col>Používá</th>
				<th scope=col><span class=visually-hidden>Akce</span></th>
			</tr>
		</thead>
		<tbody>
			<tr n:foreach="$blocks as $name => $block">
				<td>
					{if is_string($block)}
						<strong>{$name}</strong>
					{else}
						<a n:href="Block:detail, name: $name">{$name}</a>
					{/if}
				</td>
				<td>
					{if is_string($block)}
						<span class=error>{$block}</span>
					{else}
						<code>{$block->command}</code>
					{/if}
				</td>
				<td>{if isset($usage[$name])}{implode(', ', $usage[$name])}{/if}</td>
				{* Odkaz na úpravu je mimo podmínku schválně: kámen, který se
				   nenaparsuje, je ten jediný, u kterého uživatel cestu k opravě
				   a k mazání potřebuje nejvíc. Stejně to má Workflow/default.latte. *}
				<td><a n:href="Block:edit, name: $name">upravit</a></td>
			</tr>
		</tbody>
	</table>
</div>
```

- [ ] **Step 5: Pusť sady a PHPStan**

```bash
cd /home/honza/Dokumenty/Projekty/donut-org/donut
vendor/bin/tester tests -C
cd gui && vendor/bin/tester tests -C && vendor/bin/phpstan analyse -c phpstan.neon --no-progress
```

Expected: gui 36 OK, donut 32 OK, PHPStan čistý. **Pokud spadne jiný test, vypiš který a co tvrdil** — `Layout.phpt` renderuje `Block:default` kvůli drobečkům a navigaci, `BlockPresenter.default.phpt` prázdný stav a chybějící adresář.

- [ ] **Step 6: Ověř mutací**

Zazálohuj `gui/src/Presentation/Block/default.latte` (`cp` mimo repozitář) a pusť postupně dvě mutace:

1. Přesuň odkaz „upravit" dovnitř `{else}` větve (tedy jen pro naparsovatelné kameny).
   Run: `cd gui && vendor/bin/tester tests/BlockPresenter.default.phpt -C`
   Expected: FAIL na `name=rozbity` — rozbitý kámen přijde o cestu k opravě

2. Vrať soubor a smaž ze sloupce „Používá" výpis (`<td></td>` natvrdo).
   Run: `cd gui && vendor/bin/tester tests/BlockPresenter.delete.phpt -C`
   Expected: FAIL na `<td>\s*w\s*</td>`

Po každé mutaci vrať soubor `cp` ze zálohy. **Nikdy `git checkout --`.** Přežije-li některá, **nahlas to jako díru v pokrytí** — neohýbej test.

- [ ] **Step 7: Commit**

```bash
cd /home/honza/Dokumenty/Projekty/donut-org/donut
git add gui/src/Presentation/Block/default.latte gui/tests/BlockPresenter.default.phpt \
        gui/tests/BlockPresenter.delete.phpt
git commit -m "GUI: přehled kamenů jako tabulka"
```

---

## Závěrečná kontrola celého projektu

Po Tasku 3 projdi GUI v prohlížeči nad **kopií** dat v `/tmp`, v širokém (1280 px) i úzkém (390 px) okně. V `gui/` je na spuštění cíl:

```bash
cd /home/honza/Dokumenty/Projekty/donut-org/donut
rm -rf /tmp/seznamy && cp -r docs/workflows/donut /tmp/seznamy
cd gui && make server port=8810 project=/tmp/seznamy
```

Projdi:

1. přehled workflow — jména vedou na detail, „upravit" funguje u všech řádků
2. přehled kamenů — jména vedou na nový detail, sloupec „Používá" sedí s tím, co je ve workflow
3. detail kamene — argumenty, vstupy, stdin, timeout, allow_failure, seznam workflow
4. drobečky `Kameny / jq / úprava` a cesta z editace zpět na detail
5. rozbitý soubor kamene i workflow — obojí musí jít otevřít i upravit
6. obě tabulky na 390 px — musí se posouvat, ne mačkat

`docs/workflows/` musí zůstat nedotčené: ověř `git status --porcelain docs/workflows/` před i po.
