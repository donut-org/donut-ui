# Adresáře cache a log — implementační plán

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Do instalace se za běhu nezapisuje vůbec — kompilát, sessions i log jdou do XDG adresářů uživatele — a po upgradu se kompilát obnoví sám.

**Architecture:** `Bootstrap` si nechá cesty spočítat dvěma novými třídami (`Dirs`, `Revision`) a předá je `Configuratoru`. `%tempDir%` ukazuje na cache uživatele, takže cokoli, co sáhne po výchozí hodnotě frameworku, píše do zapisovatelného místa; `%cacheDir%` je jeho alias pro konfiguraci. Šablony se obnovují Latteho vlastní revalidací (`setAutoRefresh`), zapnutou přes `services:` v NEONu; kontejner obnovuje revize instalace přidaná do klíče jako statický parametr.

**Tech Stack:** PHP >= 8.4, Nette 3.2 (application, bootstrap, forms, http, di), Latte 3, Tracy 2.12, nette/tester ^2.6, PHPStan level max

**Spec:** `docs/superpowers/specs/2026-09-22-cache-log-design.md`

## Global Constraints

- Pracuje se na větvi `cache-log-dirs`, kde už leží commit se specifikací
- PHPStan **level max** nad `src` i `tests` musí zůstat čistý — `make phpstan`
- Testy `make test` (nette/tester, `vendor/bin/tester -p php -C tests/`)
- **Zprávy commitů anglicky**, ve stylu `git log` tohohle repozitáře: rozkazovací způsob, velké první písmeno, bez tečky („Add the license file", „Rename the package to donut-org/donut-ui")
- `git add` **vždy s explicitními cestami**, nikdy `git add -A` ani `git add .`
- Testy smí zapisovat jedině do `TEMP_DIR` (`tests/bootstrap.php:9`), nikdy do pracovní kopie
- **Na `Configurator::$defaultExtensions` se nesahá.** Bylo to zvážené a zamítnuté — podstrčit `true` na místo jménem `debugMode` zapne i Tracy panel Latte. Konfigurace říká jednu věc na jednom místě.
- `www/index.php` se **nemění** — `boot()` si dál bere `getenv()` a vrací `Configurator`
- `.gitignore` se **nemění** — `/temp` i `/log` dál platí pro fallback bez `HOME`
- `Makefile` se **nemění** — žádný warm-up ani nový cíl nevzniká
- Prázdná hodnota proměnné prostředí se počítá jako nenastavená — stejné pravidlo jako `Donut\Profile::value()` a `DONUT_GUI_DEBUG` (`src/Bootstrap.php:35-36`)
- Komentáře v kódu vysvětlují **proč**, ne co — tak je psaný zbytek `src/`

## Ověřené skutečnosti

Tohle není odhad, je to změřené na tomhle vendoru. Neodvozuj to znovu a nepřestavuj podle vlastní představy:

| tvrzení | důkaz |
|---|---|
| `services: latte.latteFactory: setup: - setAutoRefresh(true)` funguje v produkčním režimu | dva procesy: `PRVNI` → změna šablony → `DRUHE`; bez toho řádku `PRVNI` → `PRVNI` |
| `session: savePath: %cacheDir%/sessions` se expanduje | `getByType(Nette\Http\Session::class)->getOptions()['save_path']` vrátí cestu |
| statický parametr `revision` mění třídu kontejneru | `a` → `Container_878bed6e14`, znovu `a` → totéž, `b` → `Container_bc501e744b` |
| `%tempDir%` i `%cacheDir%` jsou čitelné přes `Container::getParameter()` | vrací obojí |
| `Tracy\Debugger::$logDirectory` drží to, co dostal `enableTracy()` | vrací cestu |

**Past, na kterou se dá naletět:** `Engine::loadTemplate()` (`vendor/latte/latte/src/Latte/Engine.php:219-223`) zkracuje přes `class_exists($class, autoload: false)` a jméno třídy je hash **cesty** šablony, ne jejího obsahu. Jakmile je šablona v procesu jednou načtená, v tom procesu se už nikdy nezkompiluje znovu — bez ohledu na `autoRefresh`. Test, který vyrenderuje dvakrát za sebou v jednom procesu, projde i s vypnutou revalidací a **netestuje nic**. Proto Task 5 renderuje ve dvou procesech.

---

## Struktura souborů

**Nové:**

| soubor | odpovědnost |
|---|---|
| `src/Dirs.php` | z prostředí spočítat adresář cache a adresář logu |
| `src/Revision.php` | otisk instalace pro klíč kontejneru |
| `tests/Dirs.phpt` | tabulka prostředí → cesty |
| `tests/Revision.phpt` | otisk se hýbe s instalací |
| `tests/Bootstrap.dirs.phpt` | `boot()` ty cesty opravdu použije |
| `tests/Bootstrap.revision.phpt` | jiná revize = jiný kontejner |
| `tests/Session.savePath.phpt` | sessions míří do cache uživatele |
| `tests/Latte.AutoRefresh.phpt` | změna šablony se projeví |

**Měněné:**

| soubor | co |
|---|---|
| `src/Bootstrap.php:30-53` | cesty z `Dirs`, `%cacheDir%`, `%revision%`, adresář sessions (T3, T5) |
| `config/common.neon` | `session: savePath:` (T4), `latte.latteFactory` setup (T5) |
| `tests/Bootstrap.phpt:20-29` | prostředí v asercích musí ukázat do `TEMP_DIR` (T3) |
| `readme.md:30-31, 68-70, 78-80` | kam se píše a co dělá `debug=1` (T6) |

**Nedotčené:** `www/index.php`, `Makefile`, `.gitignore`, `tests/Latte.TemplatesCompile.phpt`.

`tests/Latte.TemplatesCompile.phpt` zůstává, čím je — syntaktická kontrola přes `new Engine` a `compile()`, která na disk nezapisuje. Není to předehřívání a nemá se v něj měnit.

---

### Task 1: `Dirs` — cache a log z prostředí

**Files:**
- Create: `src/Dirs.php`
- Test: `tests/Dirs.phpt`

**Interfaces:**
- Consumes: nic
- Produces: `Donut\Gui\Dirs::cache(array $env, string $root): string`, `Donut\Gui\Dirs::log(array $env, string $root): string` — obě čistě počítají řetězec, na disk nesahají

Pořadí, které se testuje:

| | 1. | 2. | 3. | 4. |
|---|---|---|---|---|
| cache | `DONUT_GUI_CACHE` | `$XDG_CACHE_HOME/donut-ui` | `$HOME/.cache/donut-ui` | `$root/temp` |
| log | `DONUT_GUI_LOG` | `$XDG_STATE_HOME/donut-ui/log` | `$HOME/.local/state/donut-ui/log` | `$root/log` |

- [ ] **Step 1: Napiš padající test**

Vytvoř `tests/Dirs.phpt`:

```php
<?php

declare(strict_types=1);

use Donut\Gui\Dirs;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

// Cesty se jen počítají, na disk se nesahá — proto je v testu smyšlený
// kořen a smyšlený domov.
$root = '/opt/donut-ui';
$home = ['HOME' => '/home/agent'];

// Výslovná proměnná přebíjí všechno ostatní.
Assert::same('/var/cache/donut', Dirs::cache(['DONUT_GUI_CACHE' => '/var/cache/donut'] + $home, $root));
Assert::same('/var/log/donut', Dirs::log(['DONUT_GUI_LOG' => '/var/log/donut'] + $home, $root));

// Pak XDG. Podadresář `donut-ui` je v obou, jméno `log` jen v tom stavovém —
// cache drží víc věcí a rozlišuje je až uvnitř.
Assert::same('/x/cache/donut-ui', Dirs::cache(['XDG_CACHE_HOME' => '/x/cache'] + $home, $root));
Assert::same('/x/state/donut-ui/log', Dirs::log(['XDG_STATE_HOME' => '/x/state'] + $home, $root));

// Pak HOME s výchozími cestami, které XDG předepisuje.
Assert::same('/home/agent/.cache/donut-ui', Dirs::cache($home, $root));
Assert::same('/home/agent/.local/state/donut-ui/log', Dirs::log($home, $root));

// Prázdná hodnota je totéž co nenastavená.
Assert::same('/home/agent/.cache/donut-ui', Dirs::cache(['DONUT_GUI_CACHE' => '', 'XDG_CACHE_HOME' => ''] + $home, $root));
Assert::same('/home/agent/.local/state/donut-ui/log', Dirs::log(['DONUT_GUI_LOG' => '', 'XDG_STATE_HOME' => ''] + $home, $root));

// Prostředí bez HOME i bez XDG je rozbité, ale kvůli logu se nezahazuje
// běh aplikace — na rozdíl od Donut\Profile, který bez profilu vyhodí
// výjimku, protože bez něj nemá co ukázat.
Assert::same('/opt/donut-ui/temp', Dirs::cache([], $root));
Assert::same('/opt/donut-ui/log', Dirs::log([], $root));
```

- [ ] **Step 2: Spusť ho a ověř, že padá**

Run: `vendor/bin/tester -p php -C tests/Dirs.phpt`
Expected: FAIL — `Class 'Donut\Gui\Dirs' not found`

- [ ] **Step 3: Napiš `src/Dirs.php`**

```php
<?php

declare(strict_types=1);

namespace Donut\Gui;


/**
 * Kam GUI za běhu zapisuje: cache a log.
 *
 * Obojí leží v XDG adresářích uživatele, ne v instalaci. Aplikace pak může
 * být nainstalovaná globálně a patřit rootovi — účet, který ji spouští,
 * nepotřebuje uvnitř ní zapisovat nikam.
 *
 * Dělítko je to, co těmi dvěma proměnnými myslí XDG. Kompilát kontejneru,
 * kompilát šablon i sessions jsou zahoditelné: smažou se a vyrobí znovu,
 * takže patří do cache. Log je jediná stopa po chybě, kterou uživatel
 * viděl, takže patří do stavového adresáře — specifikace tam logy jmenuje
 * výslovně.
 *
 * Prostředí přichází jako pole, ne přes getenv(), stejně jako ho bere
 * Donut\Profile — jinak by se tahle tabulka nedala otestovat.
 */
final class Dirs
{
	/**
	 * @param array<string, string> $env
	 */
	public static function cache(array $env, string $root): string
	{
		return self::value($env, 'DONUT_GUI_CACHE')
			?? self::userDir($env, 'XDG_CACHE_HOME', '/.cache')
			?? $root . '/temp';
	}


	/**
	 * @param array<string, string> $env
	 */
	public static function log(array $env, string $root): string
	{
		$explicit = self::value($env, 'DONUT_GUI_LOG');

		if ($explicit !== null) {
			return $explicit;
		}

		// Stavový adresář drží zatím jen log, ale jmenuje se podle
		// aplikace — kdyby přibylo něco dalšího, nebude to muset stát
		// vedle souborů Tracy.
		return (self::userDir($env, 'XDG_STATE_HOME', '/.local/state') ?? $root) . '/log';
	}


	/**
	 * Vrací null, když se z prostředí domov určit nedá. Volající pak sáhne
	 * do instalace — v zapisovatelném klonu je to přesně ten adresář, kde
	 * dneska všechno leží.
	 *
	 * @param array<string, string> $env
	 */
	private static function userDir(array $env, string $variable, string $fallback): ?string
	{
		$base = self::value($env, $variable);

		if ($base !== null) {
			return $base . '/donut-ui';
		}

		$home = self::value($env, 'HOME');

		return $home === null ? null : $home . $fallback . '/donut-ui';
	}


	/**
	 * Prázdná hodnota je totéž co nenastavená — stejné pravidlo, jaké má
	 * Donut\Profile pro své vlastní proměnné.
	 *
	 * @param array<string, string> $env
	 */
	private static function value(array $env, string $key): ?string
	{
		$value = $env[$key] ?? '';

		return $value === '' ? null : $value;
	}
}
```

- [ ] **Step 4: Spusť test a PHPStan**

Run: `vendor/bin/tester -p php -C tests/Dirs.phpt && vendor/bin/phpstan analyse`
Expected: oboje projde

- [ ] **Step 5: Commit**

```bash
git add src/Dirs.php tests/Dirs.phpt
git commit -m "Work out the cache and log directories from the environment"
```

---

### Task 2: `Revision` — otisk instalace

**Files:**
- Create: `src/Revision.php`
- Test: `tests/Revision.phpt`

**Interfaces:**
- Consumes: nic
- Produces: `Donut\Gui\Revision::of(string $root): string` — mtime `vendor/composer/installed.php` jako řetězec, prázdný řetězec když soubor chybí

- [ ] **Step 1: Napiš padající test**

Vytvoř `tests/Revision.phpt`:

```php
<?php

declare(strict_types=1);

use Donut\Gui\Revision;
use Nette\Utils\FileSystem;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

$root = TEMP_DIR . '/install';
$installed = $root . '/vendor/composer/installed.php';
FileSystem::write($installed, '<?php return [];');

\touch($installed, 1_600_000_000);
Assert::same('1600000000', Revision::of($root));

// Přeinstalace ten soubor přepíše, a o to celé jde: jiná revize znamená
// jiný klíč kontejneru, takže se postaví nový.
\touch($installed, 1_700_000_000);
Assert::same('1700000000', Revision::of($root));

// Rozbitá instalace není důvod přestavovat kontejner při každém requestu,
// což by se dělo, kdyby se vracel třeba čas.
Assert::same('', Revision::of(TEMP_DIR . '/nowhere'));
```

- [ ] **Step 2: Spusť ho a ověř, že padá**

Run: `vendor/bin/tester -p php -C tests/Revision.phpt`
Expected: FAIL — `Class 'Donut\Gui\Revision' not found`

- [ ] **Step 3: Napiš `src/Revision.php`**

```php
<?php

declare(strict_types=1);

namespace Donut\Gui;


/**
 * Otisk instalace, kterým zastarává zkompilovaný kontejner.
 *
 * Produkční režim kontejner nikdy nepřestavuje: soubor se jen includne,
 * pokud existuje, a jediné, co ho může zneplatnit, je klíč cache. Nette do
 * něj dává filemtime composerovského ClassLoader.php s komentářem
 * „composer update" — jenže Composer si ten soubor kopíruje s mtime svého
 * vlastního vydání, takže se mění při upgradu Composeru, ne při upgradu
 * závislostí projektu. Bez něčeho dalšího v klíči by upgradovaná instalace
 * dál běžela na kontejneru zkompilovaném pro tu předchozí.
 *
 * installed.php přepisuje každý `composer install` i `update` a instalace
 * rozbalená jako nový strom přináší nový soubor. Falešný poplach —
 * instalace, která nic nezměnila — stojí jednu kompilaci navíc, což je ta
 * levnější chyba.
 *
 * Šablony tohle nepotřebují: ty se obnovují Latteho vlastní revalidací,
 * zapnutou v config/common.neon.
 */
final class Revision
{
	public static function of(string $root): string
	{
		$time = @\filemtime($root . '/vendor/composer/installed.php'); // @ - soubor nemusí existovat

		// Rozbitá instalace drží prázdnou revizi. Cokoli proměnlivého by
		// znamenalo nový kontejner při každém requestu.
		return $time === false ? '' : (string) $time;
	}
}
```

- [ ] **Step 4: Spusť test a PHPStan**

Run: `vendor/bin/tester -p php -C tests/Revision.phpt && vendor/bin/phpstan analyse`
Expected: oboje projde

- [ ] **Step 5: Commit**

```bash
git add src/Revision.php tests/Revision.phpt
git commit -m "Fingerprint the installation for the container cache key"
```

---

### Task 3: `Bootstrap` ty cesty použije

**Files:**
- Modify: `src/Bootstrap.php:30-53`
- Test: `tests/Bootstrap.dirs.phpt`, `tests/Bootstrap.revision.phpt`

**Interfaces:**
- Consumes: `Dirs::cache()`, `Dirs::log()` (Task 1), `Revision::of()` (Task 2)
- Produces: `Bootstrap::boot(array $env): Configurator` se statickými parametry `tempDir`, `cacheDir` (stejná hodnota) a `revision`; adresáře logu a `cache/sessions` na disku

- [ ] **Step 1: Napiš padající test na adresáře**

Vytvoř `tests/Bootstrap.dirs.phpt`:

```php
<?php

declare(strict_types=1);

use Donut\Gui\Bootstrap;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

$cache = TEMP_DIR . '/cache';
$log = TEMP_DIR . '/log';

$container = Bootstrap::boot(['DONUT_GUI_CACHE' => $cache, 'DONUT_GUI_LOG' => $log])
	->createContainer(initialize: false);

// %tempDir% je cache uživatele, ne instalace. Tím se otáčí výchozí
// hodnota: cokoli, co si ji vezme od frameworku, píše tam, kam
// neprivilegovaný účet smí.
Assert::same($cache, $container->getParameter('tempDir'));

// %cacheDir% je týž adresář pod jménem, kterým o něm mluví konfigurace.
Assert::same($cache, $container->getParameter('cacheDir'));

// Tracy bez existujícího adresáře odmítne nastartovat, takže ho boot()
// zakládá.
Assert::true(\is_dir($log));
Assert::same($log, Tracy\Debugger::$logDirectory);

// PHP do adresáře sessions zapisuje, ale nezakládá ho. Bez něj se hláška
// po uložení workflow změní ve varování ze session_start().
Assert::true(\is_dir($cache . '/sessions'));
```

- [ ] **Step 2: Napiš padající test na revizi**

Vytvoř `tests/Bootstrap.revision.phpt`:

```php
<?php

declare(strict_types=1);

use Donut\Gui\Bootstrap;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

// Revize je statický parametr a ty jsou celé v klíči cache kontejneru
// (Configurator::generateContainerKey()). Jiná revize tedy znamená jinou
// třídu, tedy nový soubor — a přesně tím se po upgradu přestaví kontejner,
// aniž by k tomu někdo musel přijít a něco spustit.
$env = ['DONUT_GUI_CACHE' => TEMP_DIR . '/cache', 'DONUT_GUI_LOG' => TEMP_DIR . '/log'];

$first = Bootstrap::boot($env)->addStaticParameters(['revision' => 'a'])->createContainer(initialize: false);
$again = Bootstrap::boot($env)->addStaticParameters(['revision' => 'a'])->createContainer(initialize: false);
$other = Bootstrap::boot($env)->addStaticParameters(['revision' => 'b'])->createContainer(initialize: false);

Assert::same(\get_class($first), \get_class($again));
Assert::notSame(\get_class($first), \get_class($other));

// A revize se do kontejneru opravdu dostane, ne že by se jen tiše
// ztratila cestou.
Assert::same('b', $other->getParameter('revision'));
```

- [ ] **Step 3: Spusť oba a ověř, že padají**

Run: `vendor/bin/tester -p php -C tests/Bootstrap.dirs.phpt tests/Bootstrap.revision.phpt`
Expected: FAIL — `tempDir` ukazuje do instalace (`…/donut-ui/temp`), `cacheDir` ani `revision` v kontejneru nejsou

- [ ] **Step 4: Přepiš tělo `boot()`**

V `src/Bootstrap.php` nahraď tělo metody `boot()` od `$root = \dirname(__DIR__);` po `return $configurator;` tímhle:

```php
		$root = \dirname(__DIR__);
		// An empty value counts as unset, the same rule Profile uses for its
		// own variables; '0' is an explicit off rather than "a value exists".
		$flag = $env['DONUT_GUI_DEBUG'] ?? '';
		$debug = $flag !== '' && $flag !== '0';

		$cache = Dirs::cache($env, $root);
		$log = Dirs::log($env, $root);

		$configurator = new Configurator;
		$configurator->setDebugMode($debug);

		// Without enableTracy() an uncaught exception is a blank page. In
		// debug mode Tracy displays it; in production it writes it to the log
		// directory instead — without one, the error the user just hit would
		// go nowhere at all. Tracy refuses to start when that directory is
		// missing rather than creating it, so this does.
		FileSystem::createDir($log);
		$configurator->enableTracy($log);

		// PHP writes sessions but does not create the directory for them.
		// Without it the flash message after a save turns into a warning
		// from session_start().
		FileSystem::createDir($cache . '/sessions');

		$configurator->setTempDirectory($cache);
		$configurator->addStaticParameters([
			// The same directory under the name the configuration uses.
			// %tempDir% is what the framework's own extensions reach for;
			// pointing it at the user's cache is what makes anything added
			// later write where the account may.
			'cacheDir' => $cache,
			// Static parameters are in the container cache key in full, so
			// this one line is the whole mechanism: a new installation
			// compiles a new container.
			'revision' => Revision::of($root),
		]);
		$configurator->addConfig($root . '/config/common.neon');

		return $configurator;
```

- [ ] **Step 5: Oprav komentář u `boot()`**

Docblock metody dnes tvrdí, že produkce zmrazí cache Latte i DI a že úprava šablony se neprojeví, dokud se nesmaže `gui/temp/cache`. U šablon to po Tasku 5 přestane platit a cesta v něm navíc už neexistuje. Nahraď ten odstavec:

```php
	 * The price is that production freezes the Latte and DI caches — editing
	 * a template has no effect until `gui/temp/cache` is cleared. That is the
	 * developer's problem, and the developer is the one setting the variable.
```

textem:

```php
	 * The price is that production freezes the compiled container; editing
	 * a template is not affected, as config/common.neon keeps Latte's own
	 * revalidation switched on. See Donut\Gui\Revision for what expires the
	 * container instead.
```

- [ ] **Step 6: Nasměruj `tests/Bootstrap.phpt` do `TEMP_DIR`**

Ten test volá `boot([])`, tedy prostředí bez proměnných — a to nově spadne
na fallback do instalace, takže by začal zakládat `log/` a `temp/sessions/`
v pracovní kopii. Aserce na debug režim zůstávají slovo od slova stejné,
mění se jen prostředí, které dostanou. Nahraď řádky 20-29:

```php
// boot() zakládá adresář logu a adresář sessions rovnou, takže i test,
// který se ptá jen na debug režim, musí mít kam ukázat. Bez toho by psal
// do pracovní kopie.
$dirs = ['DONUT_GUI_CACHE' => TEMP_DIR . '/cache', 'DONUT_GUI_LOG' => TEMP_DIR . '/log'];

Assert::false(Bootstrap::boot($dirs)->isDebugMode(), 'no variable means production');
Assert::false(Bootstrap::boot(['DONUT_GUI_DEBUG' => ''] + $dirs)->isDebugMode(), 'an empty value is the same as unset');
Assert::false(Bootstrap::boot(['DONUT_GUI_DEBUG' => '0'] + $dirs)->isDebugMode(), '0 means off, not "a value is present"');

Assert::true(Bootstrap::boot(['DONUT_GUI_DEBUG' => '1'] + $dirs)->isDebugMode());

// Anything else truthy also turns it on: the variable is a switch a person
// flips by hand, and refusing "true" or "yes" would only be a puzzle.
Assert::true(Bootstrap::boot(['DONUT_GUI_DEBUG' => 'true'] + $dirs)->isDebugMode());
Assert::true(Bootstrap::boot(['DONUT_GUI_DEBUG' => 'yes'] + $dirs)->isDebugMode());
```

- [ ] **Step 7: Spusť celou sadu a PHPStan**

Run: `make test && make phpstan`
Expected: PASS

- [ ] **Step 8: Ověř, že do pracovní kopie nic nepřibylo**

`git status` tady nestačí — `/temp` i `/log` jsou v `.gitignore`, takže by
se v něm neukázaly, ani kdyby testy psaly do nich.

Run: `ls -d temp/sessions log/exception.log 2>&1`
Expected: u obou „No such file or directory" — testy psaly do `tests/tmp/`

- [ ] **Step 9: Commit**

```bash
git add src/Bootstrap.php tests/Bootstrap.phpt tests/Bootstrap.dirs.phpt tests/Bootstrap.revision.phpt
git commit -m "Point the application at the user's cache and log directories"
```

---

### Task 4: Sessions do cache uživatele

**Files:**
- Modify: `config/common.neon`
- Test: `tests/Session.savePath.phpt`

**Interfaces:**
- Consumes: statický parametr `cacheDir` a adresář `cache/sessions` z Tasku 3
- Produces: `session.savePath` nastavený na `%cacheDir%/sessions`

Bez tohohle platí `session.save_path` z php.ini, běžně `/var/lib/php/sessions` s právy `root:www-data 0770`. Neprivilegovaný účet tam nezapíše a **první uložení workflow skončí varováním místo hlášky o úspěchu** — flash zprávy jdou přes session (`src/Presentation/Block/BlockPresenter.php:326` a `:400`, `src/Presentation/Workflow/WorkflowPresenter.php:577`, `:704`, `:750`).

- [ ] **Step 1: Napiš padající test**

Vytvoř `tests/Session.savePath.phpt`:

```php
<?php

declare(strict_types=1);

use Donut\Gui\Bootstrap;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

$cache = TEMP_DIR . '/cache';

$container = Bootstrap::boot(['DONUT_GUI_CACHE' => $cache, 'DONUT_GUI_LOG' => TEMP_DIR . '/log'])
	->createContainer(initialize: false);

// Session se nestartuje — zajímá nás nastavení, ne běh. Nette klíč
// savePath normalizuje na save_path, protože session.save_path je ini
// direktiva.
$session = $container->getByType(Nette\Http\Session::class);

Assert::same($cache . '/sessions', $session->getOptions()['save_path']);
Assert::true(\is_dir($cache . '/sessions'));
```

- [ ] **Step 2: Spusť ho a ověř, že padá**

Run: `vendor/bin/tester -p php -C tests/Session.savePath.phpt`
Expected: FAIL — `save_path` v options není, pole je prázdné

- [ ] **Step 3: Přidej sekci do `config/common.neon`**

Na konec souboru přidej:

```neon

session:
	# Bez tohohle platí session.save_path z php.ini, kam neprivilegovaný
	# účet běžně zapsat nesmí — a flash zpráva po uložení by se změnila ve
	# varování ze session_start(). %cacheDir% je adresář uživatele, takže
	# sessions patří do něj; zahoditelné jsou stejně jako kompilát.
	savePath: %cacheDir%/sessions
```

- [ ] **Step 4: Spusť test a celou sadu**

Run: `vendor/bin/tester -p php -C tests/Session.savePath.phpt && make test`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add config/common.neon tests/Session.savePath.phpt
git commit -m "Keep sessions in the user's cache directory"
```

---

### Task 5: Šablony se překompilují při změně

**Files:**
- Modify: `config/common.neon`
- Test: `tests/Latte.AutoRefresh.phpt`

**Interfaces:**
- Consumes: `Bootstrap::boot()` z Tasku 3
- Produces: `latte.latteFactory` se `setAutoRefresh(true)` — šablona se po změně obsahu přeloží znovu i v produkčním režimu

Latte revalidaci umí: `generateRefreshSignature()` porovnává verzi Latte, **obsah šablony** a mtime souborů rozšíření. Běží ale jen při `autoRefresh = true` a ten je v `LatteExtension.php:74` svázaný s debug režimem. Rozvazuje se to konfigurací, protože klíč `latte: autoRefresh:` neexistuje — schéma rozšíření zná jen `debugger`, `extensions`, `templateClass`, `strictTypes`, `strictParsing`, `scopedLoopVariables`, `dedent`, `phpLinter` a `locale`, a je to `Expect::structure()` bez `otherItems()`, takže neznámý klíč rovnou spadne.

- [ ] **Step 1: Napiš padající test**

Vytvoř `tests/Latte.AutoRefresh.phpt`:

```php
<?php

declare(strict_types=1);

use Nette\Utils\FileSystem;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

// Renderuje se ve dvou procesech, a je to nutnost, ne opatrnost:
// Engine::loadTemplate() zkracuje přes class_exists() a jméno třídy je hash
// cesty šablony, ne jejího obsahu. Jakmile je šablona v procesu jednou
// načtená, v tom procesu se znovu nezkompiluje nikdy — takže test, který
// vyrenderuje dvakrát za sebou, projde i s vypnutou revalidací a
// netestuje nic.

$env = [
	'DONUT_GUI_CACHE' => TEMP_DIR . '/cache',
	'DONUT_GUI_LOG' => TEMP_DIR . '/log',
];
$template = TEMP_DIR . '/page.latte';
$script = TEMP_DIR . '/render.php';

FileSystem::write($script, \sprintf(
	'<?php declare(strict_types=1); require %s;'
	. ' $container = Donut\Gui\Bootstrap::boot(%s)->createContainer(initialize: false);'
	. ' $latte = $container->getByType(Nette\Bridges\ApplicationLatte\LatteFactory::class)->create();'
	. ' echo $latte->renderToString(%s);',
	\var_export(\dirname(__DIR__) . '/vendor/autoload.php', true),
	\var_export($env, true),
	\var_export($template, true),
));

$render = static fn(): string => \trim((string) \shell_exec(
	\escapeshellarg(\PHP_BINARY) . ' ' . \escapeshellarg($script)
));

FileSystem::write($template, 'PRVNI');
Assert::same('PRVNI', $render());

FileSystem::write($template, 'DRUHE');
Assert::same('DRUHE', $render());
```

- [ ] **Step 2: Spusť ho a ověř, že padá**

Run: `vendor/bin/tester -p php -C tests/Latte.AutoRefresh.phpt`
Expected: FAIL — druhá aserce dostane `PRVNI`, protože produkční režim kompilát zmrazil

Tohle je ta aserce, kvůli které test existuje. Když padne jinak (třeba na prázdném výstupu), něco je špatně se skriptem, ne s Lattem — vypiš si `$render()` a podívej se, co proces vrátil.

- [ ] **Step 3: Přidej setup do `config/common.neon`**

Do existující sekce `services:`, za `SimpleRouter`, přidej:

```neon

	# Latte revalidaci umí, jen ji má LatteExtension svázanou s debug
	# režimem. Bez ní by se šablona po upgradu nepřeložila znovu nikdy —
	# v produkci se nekontroluje mtime a verze Latte není v jméně kompilátu,
	# jen v komentáři v jeho hlavičce. Setup z konfigurace se přidá za ten
	# z rozšíření, takže vyhraje. Cena je otevření zámku a přečtení zdroje
	# šablony při renderu; na nástroji, co běží na localhostu, je to nic.
	latte.latteFactory:
		setup:
			- setAutoRefresh(true)
```

- [ ] **Step 4: Spusť test a celou sadu**

Run: `vendor/bin/tester -p php -C tests/Latte.AutoRefresh.phpt && make test && make phpstan`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add config/common.neon tests/Latte.AutoRefresh.phpt
git commit -m "Let Latte recompile a template when it changes"
```

---

### Task 6: Readme

**Files:**
- Modify: `readme.md:30-31`, `readme.md:68-70`, `readme.md:78-80`

**Interfaces:**
- Consumes: hotové chování z Tasků 1-5
- Produces: nic, na co by navazoval kód

- [ ] **Step 1: Přepiš odstavec o `temp/`**

Nahraď `readme.md:30-31`:

```markdown
`temp/` si Nette vytvoří samo, stačí aby adresář repozitáře byl zapisovatelný.
Používá ho pro cache kontejneru a šablon.
```

textem:

```markdown
Do adresáře repozitáře se za běhu nezapisuje. Kompilát kontejneru a šablon
i sessions jdou do `$XDG_CACHE_HOME/donut-ui` (výchozí `~/.cache/donut-ui`),
log do `$XDG_STATE_HOME/donut-ui/log` (výchozí
`~/.local/state/donut-ui/log`). Obojí si aplikace založí sama a obojí jde
přepsat — `DONUT_GUI_CACHE` a `DONUT_GUI_LOG`. Instalace tak může patřit
rootovi a být pro toho, kdo GUI spouští, jen ke čtení.
```

- [ ] **Step 2: Oprav cestu k logu**

V `readme.md:68-70` nahraď `log/exception.log` za `exception.log` v adresáři
logu, ať věta nelže o umístění:

```markdown
GUI běží ve **výchozím stavu v produkčním režimu** — bez Tracy baru, protože
pro toho, kdo v něm autoruje, je to hotová aplikace. Neodchycená chyba se
zapíše do `exception.log` v adresáři logu (`~/.local/state/donut-ui/log`)
a uživatel dostane stránku, ne bluescreen.
```

- [ ] **Step 3: Přepiš, co dělá `debug=1`**

Nahraď `readme.md:78-80`:

```markdown
To zapne Tracy a zároveň **rozmrazí cache Latte a DI kontejneru**, které
produkční režim schválně drží — bez toho se úprava šablony neprojeví, dokud
nesmažeš `temp/cache`. Přepínač odpovídá proměnné `DONUT_GUI_DEBUG`.
```

textem:

```markdown
To zapne Tracy a zároveň **rozmrazí cache DI kontejneru**. Úpravy šablon se
projeví i bez toho — Latte si obsah hlídá pořád. Kontejner se jinak přestaví
sám až s novou instalací, poznanou podle `vendor/composer/installed.php`.
Přepínač odpovídá proměnné `DONUT_GUI_DEBUG`.
```

- [ ] **Step 4: Ověř, že v readme nezůstalo staré tvrzení**

Run: `grep -n 'temp/cache\|log/exception.log\|adresář repozitáře byl zapisovatelný' readme.md`
Expected: žádný výskyt (`grep` skončí s kódem 1)

- [ ] **Step 5: Commit**

```bash
git add readme.md
git commit -m "Describe where the application writes at run time"
```

---

## Ruční ověření na závěr

Tohle plán neuzavírá automatem, protože ověřuje přesně to, co se v testech
předstírat nedá — cizí účet a instalaci jen ke čtení.

- [ ] Zkopíruj pracovní kopii do `/tmp/donut-ui-install`, spusť `composer install`
- [ ] `chmod -R a-w /tmp/donut-ui-install`
- [ ] Spusť server pod jiným účtem nad kopií ukázkové sady v `/tmp` (nikdy nad `docs/workflows/`)
- [ ] Projdi detail workflow (renderuje šablony) a ulož kámen (používá flash zprávu)
- [ ] Ověř, že v `/tmp/donut-ui-install` nepřibyl žádný soubor a že kompilát a log leží v adresářích toho účtu
- [ ] `touch` na `vendor/composer/installed.php` v kopii a znovu načti stránku — v cache toho účtu musí přibýt nový `Container_*.php`
