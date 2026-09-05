# Vzhled GUI — rám a formuláře — implementační plán

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Dát GUI dvousloupcový bootstrapí rám s offcanvas navigací a drobečky, formulářům bootstrapí vzhled přes `FormFactory`, a opakujícím se řádkům hlavičku, ze které je poznat, co do kterého políčka patří.

**Architecture:** Assety jsou obyčejné soubory pod `gui/www/assets/`; servíruje je vestavěný PHP server, jakmile mu to router script dovolí přes `StaticFile::shouldServe()`. `@layout.latte` se stane rámem stránky, drobečky si každá šablona definuje sama blokem. `FormFactory::create()` vrací `Form` s jedním `onRender`, který doplní bootstrapí třídy — šablony se nemění, protože tag `{form}` spouští `fireRenderEvents()` i při ručním vykreslování políček.

**Tech Stack:** PHP 8.3+, Nette 3 (application, forms ^3.2), Latte 3, nette/tester ^2.6, PHPStan level max, Bootstrap 5.3.8 (vendorovaný, bez build kroku), vanilla JS

**Spec:** `docs/superpowers/specs/2026-08-16-gui-vzhled-design.md`

## Global Constraints

- PHP 8.3+ v `gui/`, PHP 8.1+ v kořeni; Nette 3, Latte 3, nette/tester
- PHPStan **level max** nad `src` i `tests` v obou balících musí zůstat čistý
- GUI nesmí sahat do `../src` ani `../vendor` relativní cestou
- Do `docs/workflows/` se nesmí zapsat nic — zkoušej nad kopiemi v `/tmp`
- Server jen jako `php -S 127.0.0.1:<port> -t <gui/www> <gui/www/index.php>` — router script je povinný
- `git add` **vždy s explicitními cestami**, nikdy `git add -A` ani `git add .`
- Nikdy se nesmí commitnout: `.github/workflows/frontbot.yml`, `docs/logo.png`, `donut-org_donut.sublime-workspace`, `rss`
- Mutace vracej **kopií ze zálohy mimo repozitář** (`cp`), nikdy `git checkout -- <soubor>`, nikdy `git stash`
- Bootstrap **5.3.8**, vendorovaný do repa; žádný build krok, žádný `npm`, žádný sass
- Žádný tmavý režim, žádné logo (zatím textový název)
- Inline `<script>` v Latte vyžaduje `n:syntax="off"`, jinak šablona nezkompiluje
- Nette Form komponentu nelze renderovat uvnitř `n:foreach`
- Slovník formátu `snake_case`, lidmi pojmenované klíče mapy `camelCase`, engine dodává UPPERCASE
- Zprávy commitů česky, ve stylu ostatních („GUI: …")
- **Žádná stávající aserce se nesmí oslabit.** Kde se značkování mění, aserce se přepíše na nové — a v hlášení se každá taková změna vyjmenuje.
- Pasti PHPStanu level max v tomhle repozitáři: `(string) $mixed` je `cast.string`; `list<X>` neprojde tam, kde `Donut\Format` deklaruje `array<int, X>`; `$form::Filled` musí být `Form::Filled`; `$nullable?->prop ?? $default` hlásí `nullsafe.neverNull` — rozděl do mezipro­měnné

---

## Struktura souborů

**Nové:**

| soubor | odpovědnost |
|---|---|
| `gui/src/StaticFile.php` | rozhodnout, jestli router nechá soubor serveru |
| `gui/src/FormFactory.php` | vyrobit `Form` s bootstrapími třídami |
| `gui/www/assets/bootstrap.min.css` | vendorovaný Bootstrap 5.3.8 |
| `gui/www/assets/bootstrap.bundle.min.js` | vendorovaný Bootstrap 5.3.8, kvůli offcanvas |
| `gui/www/assets/donut.css` | to, co je dnes inline ve `<style>` v `@layout.latte` |
| `gui/www/assets/rows.js` | to, co je dnes inline ve `<script>` v `rows.latte` |
| `gui/tests/StaticFile.phpt` | jednotkový test stráže včetně průchodů cestou |
| `gui/tests/FormFactory.phpt` | třídy podle typu prvku, a že nepřepíše cizí |
| `gui/tests/Layout.phpt` | navigace, aktivní položka, drobečky, offcanvas, assety |

**Měněné:**

| soubor | co |
|---|---|
| `gui/www/index.php` | zavolá `StaticFile::shouldServe()` |
| `gui/readme.md` | nový spouštěcí příkaz, oprava tvrzení o routeru, verze Bootstrapu |
| `gui/src/Presentation/@layout.latte` | přepis na dvousloupcový rám |
| `gui/src/Presentation/rows.latte` | **smaže se**, JS jde do `rows.js` |
| `gui/src/Presentation/Workflow/{default,detail,edit,step}.latte` | drobečky, tabulky |
| `gui/src/Presentation/Block/{default,edit}.latte` | drobečky, tabulky |
| `gui/src/Presentation/Workflow/WorkflowPresenter.php` | tři `new Form` → továrna |
| `gui/src/Presentation/Block/BlockPresenter.php` | dvě `new Form` → továrna |
| `gui/tests/inc/workflowPresenter.php` | poctivá továrna na prezentéry |

---

### Task 1: `StaticFile` a nové spouštění

**Files:**
- Create: `gui/src/StaticFile.php`
- Create: `gui/tests/StaticFile.phpt`
- Modify: `gui/www/index.php`
- Modify: `gui/readme.md`

**Interfaces:**
- Consumes: nic z dřívějších tasků
- Produces: `Donut\Gui\StaticFile::shouldServe(string $root, string $uri): bool` — `true`, když `$uri` ukazuje na existující soubor pod `$root`

Kontext, který task potřebuje znát: `php -S` statické soubory servírovat umí, ale ne zároveň s router scriptem — router dostane **každý** požadavek a server po souboru sáhne jen tehdy, když router vrátí `return false`. Docroot se proto přesune z projektu na `gui/www`; pracovní adresář to nezmění, protože router `chdir()` potlačí.

- [ ] **Step 1: Napiš padající test**

Vytvoř `gui/tests/StaticFile.phpt`:

```php
<?php

declare(strict_types=1);

use Donut\Gui\StaticFile;
use Nette\Utils\FileSystem;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

$root = TEMP_DIR . '/www';
FileSystem::createDir($root . '/assets');
FileSystem::write($root . '/assets/bootstrap.min.css', 'body{}');
FileSystem::write($root . '/index.php', '<?php');
FileSystem::write(TEMP_DIR . '/tajne.txt', 'TAJEMSTVI');

// existující soubor pod docrootem se má nechat serveru
Assert::true(StaticFile::shouldServe($root, '/assets/bootstrap.min.css'));
Assert::true(StaticFile::shouldServe($root, '/assets/bootstrap.min.css?v=1'));
Assert::true(StaticFile::shouldServe($root, '/index.php'));

// neexistující soubor patří aplikaci
Assert::false(StaticFile::shouldServe($root, '/'));
Assert::false(StaticFile::shouldServe($root, '/?presenter=Workflow&action=edit'));
Assert::false(StaticFile::shouldServe($root, '/assets/neni.css'));

// adresář není soubor
Assert::false(StaticFile::shouldServe($root, '/assets'));

// průchod cestou ven z docrootu. Bez téhle kontroly vrací vestavěný server
// prázdnou dvoustovku: soubor existuje, router mu ho pustí, server ho pak
// odmítne vydat. Obsah neunikne, ale odpověď 200 s prázdným tělem je nesmysl.
Assert::false(StaticFile::shouldServe($root, '/../tajne.txt'));
Assert::false(StaticFile::shouldServe($root, '/assets/../../tajne.txt'));
Assert::false(StaticFile::shouldServe($root, '/..%2ftajne.txt'));
Assert::false(StaticFile::shouldServe($root, '/etc/passwd'));

// prefix docrootu se musí porovnávat i s oddělovačem — sousední adresář se
// stejným začátkem jména nesmí projít
FileSystem::write(TEMP_DIR . '/wwwjine/soubor.txt', 'x');
Assert::false(StaticFile::shouldServe($root, '/../wwwjine/soubor.txt'));
```

- [ ] **Step 2: Pusť test a ověř, že padá**

Run: `cd gui && vendor/bin/tester tests/StaticFile.phpt -C`
Expected: FAIL — třída `Donut\Gui\StaticFile` neexistuje

- [ ] **Step 3: Napiš `StaticFile`**

Vytvoř `gui/src/StaticFile.php`:

```php
<?php

declare(strict_types=1);

namespace Donut\Gui;


/**
 * Rozhoduje, jestli má router script vestavěného PHP serveru přenechat
 * požadavek serveru jako statický soubor.
 *
 * Vestavěný server po souboru sáhne jen tehdy, když router vrátí false;
 * bez toho dostane index.php i požadavek na bootstrap.min.css.
 */
final class StaticFile
{
	public static function shouldServe(string $root, string $uri): bool
	{
		$path = \parse_url($uri, \PHP_URL_PATH);

		if (!\is_string($path)) {
			return false;
		}

		$rootReal = \realpath($root);
		$fileReal = \realpath($root . \urldecode($path));

		if ($rootReal === false || $fileReal === false) {
			return false;
		}

		// Oddělovač na konci prefixu je nutný: bez něj by /../wwwjine/x
		// prošlo, protože ".../wwwjine/x" začíná na ".../www".
		return \is_file($fileReal)
			&& \str_starts_with($fileReal, $rootReal . \DIRECTORY_SEPARATOR);
	}
}
```

- [ ] **Step 4: Pusť test a ověř, že prochází**

Run: `cd gui && vendor/bin/tester tests/StaticFile.phpt -C`
Expected: PASS

- [ ] **Step 5: Zapoj stráž do `index.php`**

Přepiš `gui/www/index.php` na:

```php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// Vestavěný server servíruje statické soubory jen tehdy, když mu je router
// script přenechá vrácením false. Bez tohohle by /assets/bootstrap.min.css
// skončilo v aplikaci jako neznámá adresa.
$uri = $_SERVER['REQUEST_URI'] ?? '';

if (\PHP_SAPI === 'cli-server' && \is_string($uri) && Donut\Gui\StaticFile::shouldServe(__DIR__, $uri)) {
	return false;
}

Donut\Gui\Bootstrap::boot()
	->createContainer()
	->getByType(Nette\Application\Application::class)
	->run();
```

- [ ] **Step 6: Ověř to živě nad kopií, ne nad `docs/workflows/`**

```bash
cd /home/honza/Dokumenty/Projekty/donut-org/donut
mkdir -p /tmp/vzhled-t1 && cp -r docs/workflows/donut /tmp/vzhled-t1/workflows
mkdir -p gui/www/assets && echo 'body{}' > gui/www/assets/zkouska.css
cd /tmp/vzhled-t1
php -S 127.0.0.1:8801 -t /home/honza/Dokumenty/Projekty/donut-org/donut/gui/www \
    /home/honza/Dokumenty/Projekty/donut-org/donut/gui/www/index.php &
sleep 1
curl -s -w ' <- HTTP %{http_code} typ=%{content_type}\n' http://127.0.0.1:8801/assets/zkouska.css
curl -s -o /dev/null -w 'stránka HTTP %{http_code}\n' 'http://127.0.0.1:8801/?presenter=Workflow&action=default'
curl -s --path-as-is -o /dev/null -w 'průchod HTTP %{http_code}\n' 'http://127.0.0.1:8801/assets/../../composer.json'
kill %1
rm -f /home/honza/Dokumenty/Projekty/donut-org/donut/gui/www/assets/zkouska.css
```

Expected: CSS se vydá jako `text/css`, stránka workflow je 200 a **vypíše seznam workflow z `/tmp/vzhled-t1`** (důkaz, že pracovní adresář zůstal v projektu), průchod cestou skončí normální odpovědí aplikace, ne prázdnou dvoustovkou.

- [ ] **Step 7: Uprav `gui/readme.md`**

Nahraď dnešní spouštěcí příkaz a odstavec pod ním:

```markdown
```
cd /muj/projekt
php -S 127.0.0.1:8000 -t /cesta/k/donut/gui/www /cesta/k/donut/gui/www/index.php
```

Router script (`gui/www/index.php` jako poslední argument) je nutný proto,
aby vestavěný server **neudělal** `chdir()` do docrootu — díky tomu může
`-t` ukazovat na `gui/www` (odkud se servírují assety) a GUI přesto hledá
`blocks/` a `workflows/` v adresáři, ze kterého jsi server spustil.

Router zároveň každý požadavek nejdřív pošle do `index.php`; statický soubor
se vydá jen tehdy, když ho `Donut\Gui\StaticFile::shouldServe()` uzná za
existující soubor uvnitř `gui/www`.
```

- [ ] **Step 8: Pusť obě sady a PHPStan**

```bash
cd /home/honza/Dokumenty/Projekty/donut-org/donut
vendor/bin/tester tests -C
vendor/bin/phpstan analyse -c phpstan.neon --no-progress
cd gui && vendor/bin/tester tests -C && vendor/bin/phpstan analyse -c phpstan.neon --no-progress
```

Expected: donut 32 testů OK, gui 31 testů OK (30 + `StaticFile.phpt`), PHPStan čistý v obou.

- [ ] **Step 9: Ověř mutací, že test chytá**

Zazálohuj `gui/src/StaticFile.php` mimo repozitář (`cp`), pak odeber kontrolu prefixu:

```php
		return \is_file($fileReal);
```

Run: `cd gui && vendor/bin/tester tests/StaticFile.phpt -C`
Expected: FAIL na `/../tajne.txt`

Vrať soubor `cp` ze zálohy. **Nikdy `git checkout --`.**

- [ ] **Step 10: Commit**

```bash
cd /home/honza/Dokumenty/Projekty/donut-org/donut
git add gui/src/StaticFile.php gui/tests/StaticFile.phpt gui/www/index.php gui/readme.md
git commit -m "GUI: statické soubory ze samostatného docrootu"
```

---

### Task 2: Assety a konec inline skriptů

**Files:**
- Create: `gui/www/assets/bootstrap.min.css`, `gui/www/assets/bootstrap.bundle.min.js`
- Create: `gui/www/assets/donut.css`
- Create: `gui/www/assets/rows.js`
- Delete: `gui/src/Presentation/rows.latte`
- Modify: `gui/src/Presentation/@layout.latte`
- Modify: `gui/src/Presentation/Workflow/edit.latte`, `gui/src/Presentation/Workflow/step.latte`, `gui/src/Presentation/Block/edit.latte` (odstranění `{import}` a `{include rows}`)
- Modify: `gui/readme.md`

**Interfaces:**
- Consumes: `StaticFile::shouldServe()` z Tasku 1 (assety se bez ní nevydají)
- Produces: `/assets/bootstrap.min.css`, `/assets/bootstrap.bundle.min.js`, `/assets/donut.css`, `/assets/rows.js`; globální funkce `maxIndex` a `cloneRow` z `rows.js`

**Pozor na závislost, kterou nesmíš přehlédnout:** skript pro skupiny argumentů v `Block/edit.latte` volá `maxIndex` a `cloneRow`, které dneska vznikají v `rows.latte`. Po přesunu do `rows.js` musí zůstat **globální**, tedy klasický skript, **ne `type=module`** — v modulu by byly modulu vlastní a skript argumentů by na ně nedosáhl. Volají se až uvnitř obsluhy kliknutí, takže na pořadí načtení nezáleží; na tom, že jsou globální, ano.

- [ ] **Step 1: Stáhni Bootstrap**

```bash
cd /home/honza/Dokumenty/Projekty/donut-org/donut
mkdir -p gui/www/assets
curl -sL -o gui/www/assets/bootstrap.min.css https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css
curl -sL -o gui/www/assets/bootstrap.bundle.min.js https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js
head -c 40 gui/www/assets/bootstrap.min.css; echo
head -c 40 gui/www/assets/bootstrap.bundle.min.js; echo
ls -l gui/www/assets/
```

Expected: oba soubory začínají komentářem `/*! Bootstrap v5.3.8`, CSS má zhruba 230 kB, JS zhruba 80 kB. Když se verze v hlavičce neshoduje s 5.3.8, **nepokračuj** a nahlas to.

- [ ] **Step 2: Vytvoř `gui/www/assets/donut.css`**

Přesuň do něj obsah dnešního `<style>` bloku z `@layout.latte` beze změny pravidel:

```css
.error { color: #a00 }
.warning { color: #a60 }
code { background: #f4f4f4; padding: .1em .3em }
/* Na řádku kroku, ne na <li> — to obaluje i vnořené then/else/foreach
   a background by obarvil celý podstrom místo jednoho kroku. */
li > div.write { background: #eafbea }
li > div.read { background: #eef3fb }
li > div.write.read { background: #f4f0e6 }
.keys { font-size: .85em; color: #666 }
.keys a { color: #06c }
```

Pravidlo pro `body` (`font`, `margin`, `max-width`) **vypusť** — od Tasku 3 ho dělá Bootstrap a `max-width: 60rem` by dvousloupcový layout zúžil.

- [ ] **Step 3: Vytvoř `gui/www/assets/rows.js`**

Obsah je JavaScript z dnešního `rows.latte` beze změny chování, včetně komentářů:

```js
// Sdílený JS pro opakující se řádky formuláře. Používá ho editace kamene
// (args, inputs) i stránka kroku (in, out).
//
// Značkování, které očekává:
//   <tbody id="inputs"> … <tr class="js-row"> … <input name="inputs[0][name]"> … </tr> </tbody>
//   <button type=button data-add="inputs">+ řádek</button>
//   <button type=button class="js-del-row">×</button>   (uvnitř .js-row)
//
// Klasický skript, ne modul: maxIndex a cloneRow musí zůstat globální,
// protože je volá i skript skupin argumentů v Block/edit.latte.

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
		const rows = box.querySelectorAll('.js-row');
		const prefix = new RegExp('^' + add + '\\[(\\d+)]');
		const next = maxIndex(box.querySelectorAll('input, select'), prefix) + 1;
		// Přejmenuje se jen indexová část; zbytek jména zůstane, aby řádek
		// s víc poli nedostal všechna pole pod jedním jménem.
		box.appendChild(cloneRow(
			rows[rows.length - 1],
			n => n.replace(new RegExp('^' + add + '\\[\\d+]'), add + '[' + next + ']')
		));
	}

	if (e.target.classList && e.target.classList.contains('js-del-row')) {
		const row = e.target.closest('.js-row');
		const box = row.parentElement;
		// Poslední řádek zůstane, jinak by nebylo co klonovat.
		if (box.querySelectorAll('.js-row').length > 1) row.remove();
	}
});
```

Selektory jsou nově `.js-row` a `.js-del-row` — značkování na ně přepíše Task 6. **Do Tasku 6 tedy přidávání a mazání řádků nefunguje**; to je vědomé a Task 6 to vrátí.

- [ ] **Step 4: Načti assety z layoutu**

Přepiš `gui/src/Presentation/@layout.latte` (rám přijde v Tasku 3, teď jen výměna `<style>` za odkazy):

```latte
<!DOCTYPE html>
<html lang=cs>
<meta charset=utf-8>
<meta name=viewport content="width=device-width, initial-scale=1">
<title>{block title}Donut{/block}</title>
<link rel=stylesheet href="/assets/bootstrap.min.css">
<link rel=stylesheet href="/assets/donut.css">

{include content}

<script src="/assets/bootstrap.bundle.min.js"></script>
<script src="/assets/rows.js"></script>
```

- [ ] **Step 5: Smaž `rows.latte` a jeho použití**

```bash
cd /home/honza/Dokumenty/Projekty/donut-org/donut
git rm gui/src/Presentation/rows.latte
```

V `gui/src/Presentation/Workflow/edit.latte`, `gui/src/Presentation/Workflow/step.latte` a `gui/src/Presentation/Block/edit.latte` odstraň první řádek — ve všech třech je znění shodné, `{import '../rows.latte'}` — a v každé z nich i řádek `{include rows}`.

Skript skupin argumentů v `Block/edit.latte` (blok `<script n:syntax="off">` s `add-group`, `add-arg`, `del-group`) **nech být** — argumenty do tohoto projektu nepatří a jeho volání `maxIndex`/`cloneRow` teď obsluhuje `rows.js`.

- [ ] **Step 6: Pusť sady a PHPStan**

```bash
cd /home/honza/Dokumenty/Projekty/donut-org/donut
vendor/bin/tester tests -C
cd gui && vendor/bin/tester tests -C && vendor/bin/phpstan analyse -c phpstan.neon --no-progress
```

Expected: obě sady zelené (gui 31), PHPStan čistý. `Latte.TemplatesCompile.phpt` musí projít i po smazání `rows.latte` — hledá `.latte` soubory Finderem, takže se počet jen sníží.

- [ ] **Step 7: Ověř živě, že se assety opravdu vydají**

```bash
cd /home/honza/Dokumenty/Projekty/donut-org/donut
rm -rf /tmp/vzhled-t2 && mkdir -p /tmp/vzhled-t2 && cp -r docs/workflows/donut /tmp/vzhled-t2/workflows
cd /tmp/vzhled-t2
php -S 127.0.0.1:8802 -t /home/honza/Dokumenty/Projekty/donut-org/donut/gui/www \
    /home/honza/Dokumenty/Projekty/donut-org/donut/gui/www/index.php &
sleep 1
for f in bootstrap.min.css donut.css bootstrap.bundle.min.js rows.js; do
  printf '%-26s ' "$f"
  curl -s -o /dev/null -w 'HTTP %{http_code} typ=%{content_type} %{size_download} B\n' "http://127.0.0.1:8802/assets/$f"
done
kill %1
```

Expected: všechny čtyři 200, CSS jako `text/css`, JS jako `application/javascript` nebo `text/javascript`.

- [ ] **Step 8: Doplň readme o verzi Bootstrapu**

Do `gui/readme.md` přidej do sekce o spouštění odstavec:

```markdown
Assety leží v `gui/www/assets/`. Bootstrap je verze **5.3.8**, vendorovaný
ručně z `https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/`; aktualizace
znamená nahradit `bootstrap.min.css` a `bootstrap.bundle.min.js` novými
soubory odtamtud. Žádný build krok, žádný `npm`.
```

- [ ] **Step 9: Commit**

```bash
cd /home/honza/Dokumenty/Projekty/donut-org/donut
git add gui/www/assets gui/src/Presentation/@layout.latte gui/src/Presentation/rows.latte \
        gui/src/Presentation/Workflow/edit.latte gui/src/Presentation/Workflow/step.latte \
        gui/src/Presentation/Block/edit.latte gui/readme.md
git commit -m "GUI: Bootstrap a sdílený JS jako soubory, konec inline skriptů"
```

---

### Task 3: Dvousloupcový layout s offcanvas navigací

**Files:**
- Modify: `gui/src/Presentation/@layout.latte`
- Modify: `gui/tests/inc/workflowPresenter.php`
- Create: `gui/tests/Layout.phpt`

**Interfaces:**
- Consumes: assety z Tasku 2
- Produces: bloky `{block content}` (beze změny) a `{block breadcrumbs}`, který naplní Task 4

Dvě věci ověřené předem, ať se na nich task nezasekne:

1. **Testovací prostředí layout renderuje** — `runWorkflowPresenterIn()` vrací HTML včetně `<!DOCTYPE>`, takže se navigace dá testovat v procesu, bez prohlížeče.
2. **`$presenter` v šablonách k dispozici není** (šablonové třídy mají deklarované vlastnosti), ale Latte 3 nabízí z `UIExtension` funkci `isLinkCurrent()`. Ta funguje — jenže dnešní testovací továrna vrací pro každé jméno `WorkflowPresenter`, takže `isLinkCurrent('Block:*')` vyjde `true` i na stránce workflow a aserce by byla vakuová. Proto je součástí tasku oprava té továrny.

- [ ] **Step 1: Naprav testovací továrnu na prezentéry**

V `gui/tests/inc/workflowPresenter.php` nahraď tělo `getPresenterClass()`:

```php
		public function getPresenterClass(string &$name): string
		{
			// Poctivé mapování jména na třídu: bez něj vrací isLinkCurrent()
			// v šabloně true pro každou sekci a aserce na aktivní položku
			// navigace by byla vakuová.
			return $name === 'Block'
				? BlockPresenter::class
				: WorkflowPresenter::class;
		}
```

a doplň nahoru `use Donut\Gui\Presentation\Block\BlockPresenter;`.

- [ ] **Step 2: Napiš padající test**

Vytvoř `gui/tests/Layout.phpt`:

```php
<?php

declare(strict_types=1);

use Nette\Utils\FileSystem;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/inc/workflowPresenter.php';

$dir = TEMP_DIR . '/projekt';
FileSystem::createDir($dir . '/workflows');
FileSystem::write($dir . '/workflows/w.json', '{"name":"w","steps":[]}');

[, $html] = runWorkflowPresenterIn($dir, ['action' => 'default']);

// assety
Assert::contains('/assets/bootstrap.min.css', $html);
Assert::contains('/assets/donut.css', $html);
Assert::contains('/assets/bootstrap.bundle.min.js', $html);
Assert::contains('/assets/rows.js', $html);

// dvousloupcový rám
Assert::contains('container-fluid', $html);
Assert::match('~<main[^>]*class="[^"]*\bcol\b~', $html, 'obsah je pravý sloupec');

// levý sloupec je jeden prvek: pod md offcanvas, od md výš sloupec
Assert::match('~<nav[^>]*class="[^"]*\boffcanvas-md\b~', $html);
Assert::match('~<nav[^>]*class="[^"]*\bcol-md-3\b~', $html);
Assert::contains('data-bs-toggle=offcanvas', $html);
Assert::contains('id=nav', $html);

// obě sekce v navigaci
Assert::contains('>Workflow</a>', $html);
Assert::contains('>Kameny</a>', $html);

// aktivní je ta, na které stojíme — a ta druhá ne
Assert::match('~<a class="nav-link active"[^>]*>Workflow</a>~', $html, 'Workflow je aktivní');
Assert::notMatch('~<a class="nav-link active"[^>]*>Kameny</a>~', $html, 'Kameny aktivní nejsou');

// výchozí drobečky, dokud je stránka nepřepíše (Task 4)
Assert::contains('breadcrumb', $html);
```

- [ ] **Step 3: Pusť test a ověř, že padá**

Run: `cd gui && vendor/bin/tester tests/Layout.phpt -C`
Expected: FAIL na `container-fluid` — layout je zatím jen hlavička a `{include content}`

- [ ] **Step 4: Přepiš `@layout.latte`**

```latte
<!DOCTYPE html>
<html lang=cs>
<meta charset=utf-8>
<meta name=viewport content="width=device-width, initial-scale=1">
<title>{block title}Donut{/block}</title>
<link rel=stylesheet href="/assets/bootstrap.min.css">
<link rel=stylesheet href="/assets/donut.css">

<div class="container-fluid">
	<div class="row">
		{*
			Levý sloupec je jeden prvek, ne dva: offcanvas-md znamená pod md
			vysouvací panel, od md výš obyčejný sloupec. Navigace se proto
			nepíše dvakrát a nic se nepřepíná v JS.
		*}
		<nav id=nav class="col-md-3 col-lg-2 offcanvas-md offcanvas-start bg-body-tertiary p-3" tabindex="-1" aria-label="Hlavní navigace">
			<div class="d-flex justify-content-between align-items-center mb-3">
				<a class="fs-4 fw-semibold text-decoration-none" n:href="Workflow:default">Donut</a>
				<button type=button class="btn-close d-md-none" data-bs-dismiss=offcanvas data-bs-target="#nav" aria-label="Zavřít"></button>
			</div>

			<ul class="nav nav-pills flex-column">
				<li class=nav-item>
					<a n:class="nav-link, isLinkCurrent('Workflow:*') ? active" n:href="Workflow:default">Workflow</a>
				</li>
				<li class=nav-item>
					<a n:class="nav-link, isLinkCurrent('Block:*') ? active" n:href="Block:default">Kameny</a>
				</li>
			</ul>
		</nav>

		<main class="col py-3 px-md-4">
			<div class="d-flex align-items-center gap-2 mb-3">
				<button type=button class="btn btn-outline-secondary d-md-none" data-bs-toggle=offcanvas data-bs-target="#nav" aria-label="Otevřít navigaci">☰</button>

				<nav aria-label="Drobečky">
					<ol class="breadcrumb mb-0">
						{block breadcrumbs}<li class="breadcrumb-item active">Donut</li>{/block}
					</ol>
				</nav>
			</div>

			{include content}
		</main>
	</div>
</div>

<script src="/assets/bootstrap.bundle.min.js"></script>
<script src="/assets/rows.js"></script>
```

- [ ] **Step 5: Pusť test a ověř, že prochází**

Run: `cd gui && vendor/bin/tester tests/Layout.phpt -C`
Expected: PASS

- [ ] **Step 6: Pusť obě sady a PHPStan**

```bash
cd /home/honza/Dokumenty/Projekty/donut-org/donut
vendor/bin/tester tests -C
cd gui && vendor/bin/tester tests -C && vendor/bin/phpstan analyse -c phpstan.neon --no-progress
```

Expected: gui 32 testů OK (31 + `Layout.phpt`), donut 32 OK, PHPStan čistý. `tests/inc/*.php` PHPStan analyzuje — nový `use` musí projít.

- [ ] **Step 7: Ověř mutací, že aserce na aktivní položku není vakuová**

Zazálohuj `gui/src/Presentation/@layout.latte` (`cp`), pak nahraď obě podmínky natvrdo:

```latte
					<a class="nav-link active" n:href="Workflow:default">Workflow</a>
				</li>
				<li class=nav-item>
					<a class="nav-link active" n:href="Block:default">Kameny</a>
```

Run: `cd gui && vendor/bin/tester tests/Layout.phpt -C`
Expected: FAIL na „Kameny aktivní nejsou"

Vrať `cp` ze zálohy.

- [ ] **Step 8: Ověř offcanvas v prohlížeči, včetně úzkého okna**

Nad kopií v `/tmp` (nikdy nad `docs/workflows/`) spusť server jako v Tasku 2 a v headless Chrome se podívej na stránku ve dvou šířkách — 1280 px a 390 px. V širokém okně musí být navigace vidět vlevo a tlačítko ☰ skryté; v úzkém naopak. Po kliknutí na ☰ musí panel vyjet a `btn-close` ho zavřít.

- [ ] **Step 9: Commit**

```bash
cd /home/honza/Dokumenty/Projekty/donut-org/donut
git add gui/src/Presentation/@layout.latte gui/tests/Layout.phpt gui/tests/inc/workflowPresenter.php
git commit -m "GUI: dvousloupcový layout s offcanvas navigací"
```

---

### Task 4: Drobečky místo zpátečních odkazů

**Files:**
- Modify: `gui/src/Presentation/Workflow/default.latte`, `detail.latte`, `edit.latte`, `step.latte`
- Modify: `gui/src/Presentation/Block/default.latte`, `edit.latte`
- Modify: `gui/tests/Layout.phpt`

**Interfaces:**
- Consumes: `{block breadcrumbs}` z Tasku 3
- Produces: nic pro pozdější tasky

Drobečky zůstávají na šablonách, ne na prezentérech: jsou to navigační text, který zná právě kreslená šablona, a všechny potřebné hodnoty (`$name`, `$at`) v ní už jsou.

- [ ] **Step 1: Rozšiř `Layout.phpt` o drobečky**

Přidej na konec `gui/tests/Layout.phpt`:

```php
// drobečky přehledu: poslední položka je aktivní a není odkaz
Assert::match('~<li class="breadcrumb-item active">Workflow</li>~', $html);

// drobečky detailu: sekce je odkaz, jméno workflow poslední
[, $detail] = runWorkflowPresenterIn($dir, ['action' => 'detail', 'name' => 'w']);
Assert::match('~<li class=breadcrumb-item><a href="[^"]*">Workflow</a></li>~', $detail);
Assert::match('~<li class="breadcrumb-item active">w</li>~', $detail);

// zpáteční odkazy zmizely — drobečky je nahradily
Assert::notContains('← workflow', $detail);
```

- [ ] **Step 2: Pusť test a ověř, že padá**

Run: `cd gui && vendor/bin/tester tests/Layout.phpt -C`
Expected: FAIL — šablony `{block breadcrumbs}` zatím nedefinují

- [ ] **Step 3: `Workflow/default.latte`**

Za řádek `{block title}…{/block}` přidej:

```latte
{block breadcrumbs}
	<li class="breadcrumb-item active">Workflow</li>
{/block}
```

a smaž poslední řádek `<p><a n:href="Block:default">Kameny</a></p>` — odkaz na kameny je nově v levé navigaci.

- [ ] **Step 4: `Workflow/detail.latte`**

Přidej:

```latte
{block breadcrumbs}
	<li class=breadcrumb-item><a n:href="Workflow:default">Workflow</a></li>
	<li class="breadcrumb-item active">{$workflow?->name ?? 'chyba'}</li>
{/block}
```

`WorkflowDetailTemplate` má vlastnosti `$error`, `$workflow`, `$problems`, `$keys`, `$selectedKey`, `$selectedKeyExists` a `$workflowProblems` — **žádné `$name`**. Když se workflow nenaparsuje, je `$workflow` `null` a jméno v šabloně k dispozici není; proto `'chyba'`. Novou vlastnost kvůli drobečkům nezaváděj.

Smaž řádek `<p><a n:href="default">← workflow</a></p>`.

- [ ] **Step 5: `Workflow/edit.latte`**

```latte
{block breadcrumbs}
	<li class=breadcrumb-item><a n:href="Workflow:default">Workflow</a></li>
	<li n:if="$name !== null" class=breadcrumb-item><a n:href="Workflow:detail, name: $name">{$name}</a></li>
	<li class="breadcrumb-item active">{$name === null ? 'nové workflow' : 'hlavička'}</li>
{/block}
```

a smaž řádek `<p><a n:href="Workflow:default">← workflow</a></p>`.

- [ ] **Step 6: `Workflow/step.latte`**

```latte
{block breadcrumbs}
	<li class=breadcrumb-item><a n:href="Workflow:default">Workflow</a></li>
	<li class=breadcrumb-item><a n:href="Workflow:detail, name: $name">{$name}</a></li>
	<li class="breadcrumb-item active">krok</li>
{/block}
```

a smaž řádek `<p><a n:href="Workflow:detail, name: $name">← {$name}</a></p>`.

- [ ] **Step 7: `Block/default.latte` a `Block/edit.latte`**

Do `Block/default.latte`:

```latte
{block breadcrumbs}
	<li class="breadcrumb-item active">Kameny</li>
{/block}
```

a smaž `<p><a n:href="Workflow:default">← workflow</a></p>`.

Do `Block/edit.latte`:

```latte
{block breadcrumbs}
	<li class=breadcrumb-item><a n:href="Block:default">Kameny</a></li>
	<li class="breadcrumb-item active">{$name ?? 'nový kámen'}</li>
{/block}
```

a smaž `<p><a n:href="Block:default">← kameny</a></p>`.

- [ ] **Step 8: Pusť sady a PHPStan**

```bash
cd /home/honza/Dokumenty/Projekty/donut-org/donut
vendor/bin/tester tests -C
cd gui && vendor/bin/tester tests -C && vendor/bin/phpstan analyse -c phpstan.neon --no-progress
```

Expected: obě zelené, PHPStan čistý. **Pokud spadne jiný test než `Layout.phpt`**, znamená to, že se opíral o zpáteční odkaz — vypiš který a co přesně tvrdil; aserci přepiš na drobečky, nikdy ji neoslabuj.

- [ ] **Step 9: Ověř mutací**

Zazálohuj `gui/src/Presentation/Workflow/detail.latte` (`cp`), smaž z něj celý blok `{block breadcrumbs}…{/block}`.

Run: `cd gui && vendor/bin/tester tests/Layout.phpt -C`
Expected: FAIL na drobečcích detailu

Vrať `cp` ze zálohy.

- [ ] **Step 10: Commit**

```bash
cd /home/honza/Dokumenty/Projekty/donut-org/donut
git add gui/src/Presentation/Workflow/default.latte gui/src/Presentation/Workflow/detail.latte \
        gui/src/Presentation/Workflow/edit.latte gui/src/Presentation/Workflow/step.latte \
        gui/src/Presentation/Block/default.latte gui/src/Presentation/Block/edit.latte \
        gui/tests/Layout.phpt
git commit -m "GUI: drobečky místo zpátečních odkazů"
```

---

### Task 5: `FormFactory`

**Files:**
- Create: `gui/src/FormFactory.php`
- Create: `gui/tests/FormFactory.phpt`
- Modify: `gui/src/Presentation/Workflow/WorkflowPresenter.php`
- Modify: `gui/src/Presentation/Block/BlockPresenter.php`

**Interfaces:**
- Consumes: nic
- Produces: `Donut\Gui\FormFactory::create(): Nette\Application\UI\Form`

Ověřeno předem: šablony vykreslují políčka ručně přes `{input name}`, takže bootstrapí recept přes `$renderer->wrappers` by se neuplatnil — `onRender` ano, protože tag `{form}` ho spouští (`Nette\Bridges\FormsLatte\Runtime::begin()` volá `fireRenderEvents()`). Naměřeno:

```
před:  <input type="text" name="jmeno" id="frm-jmeno">
po:    <input type="text" name="jmeno" id="frm-jmeno" class="form-control">
```

- [ ] **Step 1: Napiš padající test**

Vytvoř `gui/tests/FormFactory.phpt`:

```php
<?php

declare(strict_types=1);

use Donut\Gui\FormFactory;
use Nette\Forms\Form;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

$form = FormFactory::create();
$form->addText('jmeno', 'Jméno');
$form->addTextArea('popis', 'Popis');
$form->addSelect('op', 'Operátor', ['eq' => '=']);
$form->addCheckbox('povinny', 'Povinný');
$form->addSubmit('save', 'Uložit');
$form->addHidden('typ');

// třídu doplňuje onRender, které spouští tag {form} přes fireRenderEvents()
Assert::notContains('form-control', (string) $form['jmeno']->getControl(), 'před vykreslením ještě ne');

$form->fireRenderEvents();

Assert::contains('class="form-control"', (string) $form['jmeno']->getControl());
Assert::contains('class="form-control"', (string) $form['popis']->getControl());
Assert::contains('class="form-select"', (string) $form['op']->getControl());
Assert::contains('class="form-check-input"', (string) $form['povinny']->getControl());
Assert::contains('class="btn btn-primary"', (string) $form['save']->getControl());

// skryté pole žádnou třídu nedostane
Assert::notContains('class=', (string) $form['typ']->getControl());

// vlastní třídu továrna nepřepíše — na tom stojí mazací tlačítko
$vlastni = FormFactory::create();
$vlastni->addSubmit('smazat', 'Smazat')
	->getControlPrototype()->setAttribute('class', 'btn btn-danger');
$vlastni->addText('jmeno')
	->getControlPrototype()->setAttribute('class', 'form-control form-control-lg');

$vlastni->fireRenderEvents();

Assert::contains('class="btn btn-danger"', (string) $vlastni['smazat']->getControl());
Assert::notContains('btn-primary', (string) $vlastni['smazat']->getControl());
Assert::contains('class="form-control form-control-lg"', (string) $vlastni['jmeno']->getControl());

// opakované vykreslení třídu nezdvojí
$form->fireRenderEvents();
Assert::same(1, \substr_count((string) $form['jmeno']->getControl(), 'form-control'));

// továrna vrací UI\Form, ne holý Nette\Forms\Form — prezentéry ho registrují
// jako komponentu
Assert::type(Nette\Application\UI\Form::class, $form);
Assert::type(Form::class, $form);
```

- [ ] **Step 2: Pusť test a ověř, že padá**

Run: `cd gui && vendor/bin/tester tests/FormFactory.phpt -C`
Expected: FAIL — třída `Donut\Gui\FormFactory` neexistuje

- [ ] **Step 3: Napiš `FormFactory`**

Vytvoř `gui/src/FormFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Donut\Gui;

use Nette\Application\UI\Form as UIForm;
use Nette\Forms\Controls\BaseControl;
use Nette\Forms\Form;


/**
 * Formulář s bootstrapími třídami.
 *
 * Šablony vykreslují políčka ručně přes {input jmeno}, takže bootstrapí
 * recept přes $renderer->wrappers by se neuplatnil. onRender ano — tag
 * {form} ho spouští přes fireRenderEvents().
 */
final class FormFactory
{
	/** @var array<string, string> typ prvku → třída */
	private const Classes = [
		'text' => 'form-control',
		'textarea' => 'form-control',
		'select' => 'form-select',
		'checkbox' => 'form-check-input',
		'button' => 'btn btn-primary',
	];


	public static function create(): UIForm
	{
		$form = new UIForm;
		$form->onRender[] = self::addClasses(...);

		return $form;
	}


	public static function addClasses(Form $form): void
	{
		foreach ($form->getControls() as $control) {
			if (!$control instanceof BaseControl) {
				continue;
			}

			$type = $control->getOption('type');

			if (!\is_string($type) || !isset(self::Classes[$type])) {
				continue;
			}

			$el = $control->getControlPrototype();

			// Jen tam, kde žádná třída není. Díky tomu si mazací tlačítko
			// může říct o btn-danger při vzniku a továrna mu to nepřepíše —
			// a opakované vykreslení třídu nezdvojí.
			if ($el->getAttribute('class') !== null) {
				continue;
			}

			$el->setAttribute('class', self::Classes[$type]);
		}
	}
}
```

- [ ] **Step 4: Pusť test a ověř, že prochází**

Run: `cd gui && vendor/bin/tester tests/FormFactory.phpt -C`
Expected: PASS

- [ ] **Step 5: Nasaď továrnu na všech pět formulářů**

Ve `WorkflowPresenter.php` nahraď `new Form` v `createComponentHeaderForm()`, `createComponentDeleteWorkflowForm()` a `createComponentStepForm()`; v `BlockPresenter.php` v `createComponentBlockForm()` a `createComponentDeleteForm()`. Všude stejně:

```php
		$form = FormFactory::create();
```

Doplň do obou souborů `use Donut\Gui\FormFactory;`.

Import `Nette\Application\UI\Form` v **obou** souborech **zůstává** — oba ho používají jako návratový typ továrních metod (`WorkflowPresenter` třikrát, `BlockPresenter` dvakrát) i pro konstanty pravidel `Form::Filled`, `Form::Integer`, `Form::Min` a `Form::Pattern`. Neodebírej ho; PHPStan by ohlásil neznámou třídu.

Mazacím tlačítkům dej červenou hned při vzniku, aby je továrna nepřepsala. Jména se na obou stranách **liší** — ve `WorkflowPresenter::createComponentDeleteWorkflowForm()` je to `save`:

```php
		$form->addSubmit('save', 'Smazat')
			->getControlPrototype()->setAttribute('class', 'btn btn-danger');
```

a v `BlockPresenter::createComponentDeleteForm()` je to `delete`:

```php
		$form->addSubmit('delete', 'Smazat')
			->getControlPrototype()->setAttribute('class', 'btn btn-danger');
```

Obě tlačítka vykreslují šablony ručně přes `<button type=submit n:name=…>`. To funguje: `FieldNNameNode` vypíše atributy z prototypu a vynuluje jen ty, které si šablona napíše sama. **Do markupu těch tlačítek proto `class` nepiš** — přebilo by to prototyp a `btn btn-danger` by zmizelo.

- [ ] **Step 6: Pusť obě sady a PHPStan**

```bash
cd /home/honza/Dokumenty/Projekty/donut-org/donut
vendor/bin/tester tests -C
cd gui && vendor/bin/tester tests -C && vendor/bin/phpstan analyse -c phpstan.neon --no-progress
```

Expected: gui 33 OK, donut 32 OK, PHPStan čistý. **Žádný stávající test se nesmí měnit** — třídy se jen přidávají do atributů, aserce se ptají na `value=` a jména polí.

- [ ] **Step 7: Ověř mutací**

Zazálohuj `gui/src/FormFactory.php` (`cp`), pak odeber ochranu proti přepsání:

```php
			$el->setAttribute('class', self::Classes[$type]);
```

(tedy smaž celý blok `if ($el->getAttribute('class') !== null) { continue; }`)

Run: `cd gui && vendor/bin/tester tests/FormFactory.phpt -C`
Expected: FAIL na `btn-danger`

Vrať `cp` ze zálohy.

- [ ] **Step 8: Commit**

```bash
cd /home/honza/Dokumenty/Projekty/donut-org/donut
git add gui/src/FormFactory.php gui/tests/FormFactory.phpt \
        gui/src/Presentation/Workflow/WorkflowPresenter.php \
        gui/src/Presentation/Block/BlockPresenter.php
git commit -m "GUI: FormFactory nasazuje bootstrapí třídy"
```

---

### Task 6: Opakující se řádky jako tabulky

**Files:**
- Modify: `gui/src/Presentation/Workflow/edit.latte`
- Modify: `gui/src/Presentation/Block/edit.latte`
- Modify: `gui/src/Presentation/Workflow/step.latte`
- Create: `gui/tests/Rows.phpt`

**Interfaces:**
- Consumes: `rows.js` z Tasku 2 (očekává `.js-row`, `.js-del-row`, `data-add`), `FormFactory` z Tasku 5
- Produces: nic pro pozdější tasky

Tohle je jádro projektu: dnes jsou vstupy čtyři nepopsaná políčka vedle sebe, protože `createComponentHeaderForm()` volá `$row->addText('name')` bez druhého argumentu. Hlavička tabulky je odpověď na „co do toho patří".

**Kolize `.row`:** dnešní `<div class=row>` je zároveň Bootstrap grid. Proto `<tr class=js-row>` — prefix `js-` říká, že na tu třídu sahá skript, ne stylopis.

**Kontrakt, který se nesmí změnit:** indexy se nikdy nepřečíslovávají, nový řádek dostane o jedna vyšší než dosavadní maximum a smazání nechá díru. Stojí na něm `RowShape::of()`.

- [ ] **Step 1: Napiš padající test**

Vytvoř `gui/tests/Rows.phpt`:

```php
<?php

declare(strict_types=1);

use Nette\Utils\FileSystem;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/inc/workflowPresenter.php';

$dir = TEMP_DIR . '/projekt';
FileSystem::createDir($dir . '/workflows');
FileSystem::write($dir . '/workflows/w.json', \json_encode([
	'name' => 'w',
	'inputs' => ['repo' => ['required' => true, 'description' => 'Repozitář']],
	'steps' => [],
]));

[, $html] = runWorkflowPresenterIn($dir, ['action' => 'edit', 'name' => 'w']);

// hlavička je to, co uživateli říká, co do kterého políčka patří
Assert::contains('<th scope=col>Jméno</th>', $html);
Assert::contains('<th scope=col>Povinný</th>', $html);
Assert::contains('<th scope=col>Výchozí</th>', $html);
Assert::contains('<th scope=col>Popis</th>', $html);

// značkování, na které sahá rows.js
Assert::contains('<tbody id=inputs>', $html);
Assert::match('~<tr class=js-row>~', $html);
Assert::contains('js-del-row', $html);
Assert::contains('data-add=inputs', $html);

// stará třída .row je pryč — kolidovala by s Bootstrap gridem
Assert::notMatch('~<div class=row[ >]~', $html);

// hodnoty ze souboru v tabulce zůstávají
Assert::match('~name="inputs\[0\]\[name\]"[^>]*value="repo"~', $html);
Assert::match('~name="inputs\[0\]\[description\]"[^>]*value="Repozitář"~', $html);

// nápověda pod tabulkou
Assert::contains('--jmeno=hodnota', $html);
Assert::contains('když ji volající nepředá', $html);


// --- stránka kroku: tabulky in a out -----------------------------------

$krok = TEMP_DIR . '/krok';
FileSystem::createDir($krok . '/blocks');
FileSystem::createDir($krok . '/workflows');
FileSystem::write($krok . '/blocks/jq.json', \json_encode([
	'name' => 'jq',
	'command' => 'jq',
	'inputs' => ['filter' => ['required' => true]],
	'stdin' => ['required' => true],
]));
FileSystem::write($krok . '/workflows/w.json', \json_encode([
	'name' => 'w',
	'steps' => [[
		'type' => 'run',
		'block' => 'jq',
		'in' => ['filter' => '.id'],
		'out' => ['result' => 'id'],
	]],
]));

[, $stepHtml] = runWorkflowPresenterIn($krok, [
	'action' => 'step',
	'name' => 'w',
	'at' => 'w.json:steps[0]',
]);

Assert::contains('<th scope=col>Vstup kamene</th>', $stepHtml);
Assert::contains('<th scope=col>Co z kamene</th>', $stepHtml);
Assert::contains('<th scope=col>Pod jakým klíčem do mapy</th>', $stepHtml);

// literál {%klíč%} v hlavičce — Latte ho umí vypsat jen přes {='…'}
Assert::contains('&#123;%klíč%}', $stepHtml);

Assert::contains('<tbody id=in>', $stepHtml);
Assert::contains('<tbody id=out>', $stepHtml);
Assert::contains('data-add=in', $stepHtml);
Assert::contains('data-add=out', $stepHtml);
Assert::notMatch('~<div class=row[ >]~', $stepHtml);

// hodnoty kroku v tabulkách zůstávají
Assert::match('~name="in\[0\]\[key\]"[^>]*value="filter"~', $stepHtml);
Assert::match('~name="out\[0\]\[value\]"[^>]*value="id"~', $stepHtml);
```

- [ ] **Step 2: Pusť test a ověř, že padá**

Run: `cd gui && vendor/bin/tester tests/Rows.phpt -C`
Expected: FAIL na `<th scope=col>Jméno</th>`

- [ ] **Step 3: `Workflow/edit.latte` — tabulka vstupů**

Nahraď dnešní blok (od `<h2>Vstupy</h2>` po `<button type=button data-add="inputs">+ vstup</button>`):

```latte
		<h2>Vstupy</h2>

		<table class="table table-sm align-middle">
			<thead>
				<tr>
					<th scope=col>Jméno</th>
					<th scope=col>Povinný</th>
					<th scope=col>Výchozí</th>
					<th scope=col>Popis</th>
					<th scope=col><span class=visually-hidden>Smazat řádek</span></th>
				</tr>
			</thead>
			<tbody id=inputs>
				<tr class=js-row n:foreach="$form['inputs']->getComponents() as $row">
					<td>{input $row['name']}</td>
					<td>{input $row['required']}</td>
					<td>{input $row['default']}</td>
					<td>{input $row['description']}</td>
					<td><button type=button class="btn btn-sm btn-outline-danger js-del-row">×</button></td>
				</tr>
			</tbody>
		</table>

		<button type=button class="btn btn-sm btn-secondary" data-add=inputs>+ vstup</button>

		<p class=form-text>
			Výchozí hodnota se použije, když ji volající nepředá.
			Na příkazové řádce se vstup zadává jako <code>--jmeno=hodnota</code>.
		</p>
```

Dnešní větu `<p class=keys>Vstupy se na příkazové řádce zadávají jako <code>--jmeno=hodnota</code>.</p>` nad tabulkou smaž — nahradila ji ta pod tabulkou.

- [ ] **Step 4: `Block/edit.latte` — tabulka vstupů**

Nahraď blok od `<h2>Vstupy</h2>` po `<button type=button data-add=inputs>+ vstup</button>` **týmž** značkováním jako v kroku 3, včetně věty pod tabulkou. Skupiny argumentů (`arg-group`, `add-arg`, `del-group`) **nech beze změny** — mají vlastní dvouúrovňovou strukturu a do tohohle projektu nepatří; jen tlačítkům doplň bootstrapí třídy:

```latte
				<button type=button class="btn btn-sm btn-secondary add-arg">+ argument</button>
				<button type=button class="btn btn-sm btn-outline-danger del-group">× skupina</button>
```

a níž:

```latte
		<button type=button class="btn btn-sm btn-secondary" id=add-group>+ skupina</button>
```

- [ ] **Step 5: `Workflow/step.latte` — tabulky `in` a `out`**

Nahraď blok `<h2>Vstupy kamene</h2>` … `+ vstup`:

```latte
			<h2>Vstupy kamene</h2>

			<table class="table table-sm align-middle">
				<thead>
					<tr>
						<th scope=col>Vstup kamene</th>
						<th scope=col>Hodnota nebo <code>{='{%klíč%}'}</code></th>
						<th scope=col><span class=visually-hidden>Smazat řádek</span></th>
					</tr>
				</thead>
				<tbody id=in>
					<tr class=js-row n:foreach="$form['in']->getComponents() as $row">
						<td>{input $row['key']}</td>
						<td>{input $row['value']}</td>
						<td><button type=button class="btn btn-sm btn-outline-danger js-del-row">×</button></td>
					</tr>
				</tbody>
			</table>

			<button type=button class="btn btn-sm btn-secondary" data-add=in>+ vstup</button>
```

a blok `<h2>Výstupy do mapy</h2>` … `+ výstup`:

```latte
			<h2>Výstupy do mapy</h2>

			<table class="table table-sm align-middle">
				<thead>
					<tr>
						<th scope=col>Co z kamene</th>
						<th scope=col>Pod jakým klíčem do mapy</th>
						<th scope=col><span class=visually-hidden>Smazat řádek</span></th>
					</tr>
				</thead>
				<tbody id=out>
					<tr class=js-row n:foreach="$form['out']->getComponents() as $row">
						<td>{input $row['channel']}</td>
						<td>{input $row['value']}</td>
						<td><button type=button class="btn btn-sm btn-outline-danger js-del-row">×</button></td>
					</tr>
				</tbody>
			</table>

			<button type=button class="btn btn-sm btn-secondary" data-add=out>+ výstup</button>
```

Pozn.: `{='{%klíč%}'}` je **jediný** zápis, kterým se literál `{%klíč%}` v Latte vypíše. Naměřeno na Latte 3 v tomhle repozitáři:

```
holé <code>{%klíč%}</code>      → CHYBA: Unexpected '%'
<code>{"{%klíč%}"}</code>       → CHYBA: Unexpected '%'
<code>{='{%klíč%}'}</code>      → &#123;%klíč%}     (v prohlížeči {%klíč%})
```

- [ ] **Step 6: Pusť test a ověř, že prochází**

Run: `cd gui && vendor/bin/tester tests/Rows.phpt -C`
Expected: PASS

- [ ] **Step 7: Pusť obě sady a PHPStan**

```bash
cd /home/honza/Dokumenty/Projekty/donut-org/donut
vendor/bin/tester tests -C
cd gui && vendor/bin/tester tests -C && vendor/bin/phpstan analyse -c phpstan.neon --no-progress
```

Expected: gui 34 OK, donut 32 OK, PHPStan čistý.

- [ ] **Step 8: Ověř mutací**

Zazálohuj `gui/src/Presentation/Workflow/edit.latte` (`cp`), pak smaž z tabulky celý `<thead>…</thead>`.

Run: `cd gui && vendor/bin/tester tests/Rows.phpt -C`
Expected: FAIL na `<th scope=col>Jméno</th>`

Vrať `cp` ze zálohy.

- [ ] **Step 9: Ověř v prohlížeči, že přidávání a mazání řádků zase funguje**

Tohle je jediné, co sada neumí — `rows.js` běží až v prohlížeči. Nad kopií v `/tmp` (nikdy nad `docs/workflows/`) otevři úpravu hlavičky workflow a ověř:

1. „+ vstup" přidá řádek a jeho pole mají index o jedna vyšší než dosavadní maximum
2. „×" řádek smaže, ale poslední řádek smazat nejde
3. po smazání prostředního řádku a uložení **zůstanou oba zbylé vstupy** — díra v číslování nesmí nic ztratit
4. totéž na stránce kroku pro `in` i `out`
5. na stránce kamene pořád fungují skupiny argumentů („+ skupina", „+ argument", „× skupina") — jejich skript volá `maxIndex` a `cloneRow` z `rows.js`

Bod 5 je ta nejdůležitější kontrola celého tasku: je to jediné místo, kde se pozná, že přesun funkcí do souboru nerozbil cizí skript.

- [ ] **Step 10: Commit**

```bash
cd /home/honza/Dokumenty/Projekty/donut-org/donut
git add gui/src/Presentation/Workflow/edit.latte gui/src/Presentation/Workflow/step.latte \
        gui/src/Presentation/Block/edit.latte gui/tests/Rows.phpt
git commit -m "GUI: opakující se řádky jako tabulky s hlavičkou"
```

---

## Závěrečná kontrola celého projektu

Po Tasku 6 projdi GUI v prohlížeči nad **kopií** dat v `/tmp` jako uživatel, v širokém i úzkém okně:

1. od prázdného adresáře přes založení workflow, přidání kroku, úpravu hlavičky až po smazání
2. založení a úprava kamene včetně skupin argumentů
3. rozbité workflow (nevalidní JSON) — musí jít otevřít i smazat
4. projekt bez `blocks/` — detail workflow se musí vykreslit i s hláškou

`docs/workflows/` musí zůstat nedotčené: ověř `git status --porcelain docs/workflows/` před i po.
