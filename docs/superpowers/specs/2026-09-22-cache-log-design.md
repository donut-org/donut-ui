# Adresáře cache a log — návrh

Do instalace se za běhu nezapisuje vůbec. Všechno, co aplikace vyrobí, leží
v adresářích uživatele podle XDG, a po upgradu se to překompiluje samo.

## Cíl

GUI má jít nainstalovat globálně — `/opt/donut-ui`, vlastněné rootem, pro
běžného uživatele read-only — a spouštět ho má neprivilegovaný účet. Dnes to
nejde, protože `src/Bootstrap.php` má obě zapisovatelná místa napevno
odvozená od kořene instalace:

| řádek | co tam je |
|---|---|
| `src/Bootstrap.php:32` | `$root = \dirname(__DIR__)` |
| `src/Bootstrap.php:46` | `FileSystem::createDir($root . '/log')` |
| `src/Bootstrap.php:47` | `$configurator->enableTracy($root . '/log')` |
| `src/Bootstrap.php:49` | `$configurator->setTempDirectory($root . '/temp')` |

Zvenčí se to přepsat nedá a **nešlo by to ani konfigurací**:
`setTempDirectory()` je `addStaticParameters(['tempDir' => …])`
(`Configurator.php:115-118`) a cesta ke cache kontejneru se ze statických
parametrů odvodí v `getCacheDirectory()` (`Configurator.php:360-366`) dřív,
než se přečte první řádek NEONu. Logovací adresář jde rovnou do
`enableTracy()`, kam konfigurace nedosáhne vůbec.

## Dva adresáře, oba u uživatele

```
/opt/donut-ui/              root, read-only — jen kód a vendor
~/.cache/donut-ui/          $XDG_CACHE_HOME
    cache/Container_*.php   DI kontejner
    cache/latte/            kompilované šablony
    sessions/               flash zprávy
~/.local/state/donut-ui/
    log/exception.log       Tracy
~/.config/donut/default/    profil — blocks/, workflows/ (dnešek, nemění se)
```

Dělítko je cena ztráty. Kompilát i sessions jsou zahoditelné z definice —
zmizí a vyrobí se znovu. Log je jediná stopa po chybě, kterou uživatel
viděl. Přesně kvůli tomuhle rozdílu XDG rozlišuje `XDG_CACHE_HOME`
a `XDG_STATE_HOME`; specifikace u druhého jmenuje logy doslova
(*„actions history (logs, history, recently used files)"*).

Sdílený předgenerovaný kompilát v instalaci se **nedělá** — viz *Co se
vědomě nedělá*. Aplikace je malá a v cílovém nasazení má jeden účet, takže
sdílení nešetří nic a platilo by se za ně krokem navíc při každé instalaci
i upgradu.

## `%tempDir%` je uživatelská cache

`setTempDirectory()` dostane adresář s cache, ne instalaci. Tím se otočí
výchozí hodnota, kterou si bere všechno ostatní: Configurator drátuje
rozšíření na `'%tempDir%/cache'` (`Configurator.php:37-52` — `cache`,
`search`, `application`), takže cokoli budoucího, co sáhne po výchozí
hodnotě, píše do zapisovatelného adresáře uživatele. Opačné pořadí by
znamenalo, že každé nové rozšíření na read-only instalaci spadne, dokud ho
někdo ručně nepřesměruje.

`%cacheDir%` je alias — statický parametr se stejnou hodnotou. Oba jsou
statické, takže `savePath: %cacheDir%/sessions` se expanduje normálně při
kompilaci a žádný dynamický parametr není potřeba. Jméno existuje proto, aby
konfigurace nemluvila o „temp", když jde o cache uživatele.

## Rozhraní: dvě proměnné prostředí

| proměnná | co určuje | výchozí |
|---|---|---|
| `DONUT_GUI_CACHE` | kompilát, sessions | `$XDG_CACHE_HOME/donut-ui`, jinak `$HOME/.cache/donut-ui`, jinak `$root/temp` |
| `DONUT_GUI_LOG` | log | `$XDG_STATE_HOME/donut-ui/log`, jinak `$HOME/.local/state/donut-ui/log`, jinak `$root/log` |

Prázdná hodnota znamená nenastaveno — stejné pravidlo, jaké má
`Donut\Profile::value()` a `DONUT_GUI_DEBUG` (`src/Bootstrap.php:35-36`).

Když v prostředí není ani `HOME`, ani odpovídající `XDG_*`, `Bootstrap`
**nehází výjimku**, jak to dělá `Profile` u profilů, ale sáhne do instalace:
`$root/temp` a `$root/log`. Profil je to, kvůli čemu aplikace existuje — bez
něj nemá co dělat. Log je provozní detail a shodit kvůli němu GUI by byla
špatná výměna. V zapisovatelném klonu je ten fallback zároveň přesně to
místo, kde dnes všechno leží.

Pro vývoj z klonu se mění jedna viditelná věc: **kompilát, sessions i log se
stěhují** do `~/.cache/donut-ui` a `~/.local/state/donut-ui/log`. Readme dnes
na dvou místech slibuje `log/exception.log`, takže se přepíše. `.gitignore`
si `/temp` i `/log` ponechá — platí pro fallback bez `HOME`. Makefile se
nemění.

## Upgrade se musí překompilovat sám

Produkční režim nekontroluje čerstvost ničeho — ani šablon, ani kontejneru.
Každá strana má ale jinou obranu, takže i jiné řešení.

### Šablony: Latte to umí, jen je to vypnuté

Latte revalidaci má. `generateRefreshSignature()` porovnává verzi Latte,
**obsah šablony** a mtime souborů rozšíření (`Runtime/Cache.php:135-145`).
Běží ale jen při `autoRefresh = true` a ten je svázaný s debug režimem
(`LatteExtension.php:74`: `addSetup('setAutoRefresh', [$this->debugMode])`).

Rozvázat to jde konfigurací, bez sahání do frameworku:

```neon
services:
	latte.latteFactory:
		setup:
			- setAutoRefresh(true)
```

`latte.latteFactory` je `FactoryDefinition` se setupy na result definition
(`LatteExtension.php:69-81`) a `ServicesExtension.php:137-171` setup
z konfigurace přidává na `getResultDefinition()` **za** ten z rozšíření,
takže vyhraje. Klíč `latte: autoRefresh:` neexistuje — schéma rozšíření zná
jen `debugger`, `extensions`, `templateClass`, `strictTypes`,
`strictParsing`, `scopedLoopVariables`, `dedent`, `phpLinter` a `locale`,
a je to `Expect::structure()` bez `otherItems()`, takže neznámý klíč spadne.
Jediná vazba na vnitřek rozšíření je tedy jméno služby `latte.latteFactory`;
kdyby ho Nette přejmenovalo, konfigurace spadne při kompilaci, ne tiše za
běhu.

Cena je `fopen` se sdíleným zámkem a přečtení zdroje šablony při každém
renderu (`Runtime/Cache.php:37-49`) — pro nástroj, který běží na localhostu
a obslouží pár requestů za minutu, je to nic.

Vedle upgradu to spraví ještě jednu věc: dnes se **úprava šablony
neprojeví**, dokud někdo nesmaže cache nebo nespustí `make server debug=1`,
a readme to popisuje jako vlastnost. Nově se projeví vždycky a ta věta
z readme zmizí.

Vlastní mechanismus na šablony tedy netřeba. Kdyby se sahalo po jménu
kompilátu místo po revalidaci, muselo by se počítat s tím, že v něm verze
Latte **není** — je jen v komentáři v hlavičce
(`Compiler/TemplateGenerator.php:66`).

### Kontejner: revize

Kontejner se bez auto-rebuildu jen includne, pokud soubor existuje
(`ContainerLoader.php:61-76`), a auto-rebuild je v `loadContainer()`
(`Configurator.php:283-293`) natvrdo svázaný s `%debugMode%`. Jediná obrana
je tedy klíč (`generateContainerKey()`, `Configurator.php:344-357`):
statické parametry, **cesty** ke konfiguračním souborům (ne jejich obsah),
minor verze PHP a `filemtime` composerovského `ClassLoader.php`.

To poslední navíc neznamená, co se zdá. Composer si ten soubor kopíruje
s původním mtime svého vlastního vydání:

```
2026-07-01  vendor/composer/ClassLoader.php     ← mtime Composeru
2026-09-05  vendor/composer/installed.php       ← skutečná instalace
2026-09-05  vendor/composer/autoload_real.php
```

Mění se tedy jen při upgradu samotného Composeru, ne při `composer update`
závislostí projektu. **Kontejner se tedy po upgradu sám nepřestaví.**

`Bootstrap` proto spočítá jednu hodnotu:

```php
$revision = (string) \filemtime($root . '/vendor/composer/installed.php');
```

Jeden `stat`. `installed.php` přepisuje každý `composer install` i `update`
(viz časy výš) a při instalaci rozbalením nového stromu je to nový soubor.
Falešný poplach — `composer install` beze změny závislostí — stojí jednu
kompilaci navíc, což je ta levnější chyba.

Dostane se do klíče jediným řádkem:
`addStaticParameters(['revision' => $revision])`. `generateContainerKey()`
bere celé pole statických parametrů, takže víc není potřeba — jiná revize
znamená jiný hash a nový soubor v zapisovatelném adresáři uživatele.

Tím je podmínka splněná na obou stranách a bez jediného zásahu
administrátora: šablony se překompilují, když se změní jejich obsah,
kontejner, když se změní instalace.

Starý kompilát v `~/.cache/donut-ui` zůstane ležet. Uklízet ho návrh
nezkouší: je to XDG cache, tedy adresář, který je z definice kdykoli
smazatelný, a jde o stovky kilobajtů. `rm -rf ~/.cache/donut-ui` je vždycky
bezpečné a nic jiného to nevyžaduje.

## Sessions: třetí místo, kam se zapisuje

GUI používá flash zprávy (`src/Presentation/Block/BlockPresenter.php:326`
a `:400`, `src/Presentation/Workflow/WorkflowPresenter.php:577`, `:704`
a `:750`) a `Presenter::flashMessage()` startuje session. V
`config/common.neon` dnes žádná sekce `session:` není, takže platí
`session.save_path` z php.ini — běžně `/var/lib/php/sessions`
s právy `root:www-data 0770`. Neprivilegovaný účet tam nezapíše a **první
uložení workflow skončí varováním ze `session_start()` místo hlášky
o úspěchu**. Bez tohohle by instalace sice běžela, ale s rozbitou zpětnou
vazbou u každé akce, která něco ukládá.

`config/common.neon` proto dostane:

```neon
session:
	savePath: %cacheDir%/sessions
```

`Session::setOptions()` klíč normalizuje z `savePath` na `save_path`
a `session.save_path` je platná ini direktiva, takže projde. Adresář musí
existovat — PHP si ho nezaloží — takže ho `boot()` vytvoří stejně, jako
dnes vytváří `log/`.

## Testy

Píšou se napřed. `tests/bootstrap.php` už definuje `TEMP_DIR`
(řádek 9), takže je kam sahat.

**Rozšíření `tests/Bootstrap.phpt`** — dnes testuje jen debug režim:

- tabulka prostředí → oba adresáře: obě proměnné nastavené, prázdné
  (= nenastavené), `XDG_CACHE_HOME` a `XDG_STATE_HOME`, jen `HOME`, ani
  jedno
- `%tempDir%` a `%cacheDir%` mají stejnou hodnotu a míří do cache
  uživatele, ne do instalace — ověří se přes `Container::getParameter()`

**Nové testy na „překompiluje se samo"** — jádro zadání, a každá polovina má
jiný mechanismus, takže i jiný test:

- **kontejner**: dvě různé revize dají **různé jméno třídy kontejneru**,
  stejná revize stejné
- **šablony**: vyrenderovat šablonu, **změnit její obsah**, vyrenderovat
  znovu a čekat nový výstup. Chování, ne příznak — `autoRefresh` nemá
  veřejný getter a tvrzení „setup z konfigurace přebije ten z rozšíření"
  stojí na pořadí, které se ověří jedině tím, že se šablona opravdu
  překompiluje.

Bez toho druhého testu by se dalo omylem spolehnout na to, že klíč
kontejneru pokrývá i šablony — a nepokrývá; to je přesně chyba, kterou
návrh opravuje.

**Nový test na sessions** — `session.savePath` v kontejneru ukazuje do
`%cacheDir%/sessions`.

**Vedlejší efekt**: `boot()` dnes při každém volání zakládá `log/`
v repozitáři, takže testy debug režimu zapisují do pracovní kopie. S
proměnnou to zmizí.

Ruční ověření: nainstalovat do `/tmp`, `chmod -R a-w`, spustit pod jiným
účtem a projít detail workflow i uložení kamene — tedy cestu, která
renderuje šablony a používá flash zprávu. Pak `touch` na `installed.php`,
ať je vidět, že se postavil nový kontejner.

## Co se vědomě nedělá

- **sdílený kompilát v instalaci** (zvažovalo se jako varianta A a B) —
  jeden předgenerovaný kompilát pro všechny účty by ušetřil kompilaci, ale
  stál by `make warmup` při instalaci i po každém upgradu, přibití
  `wwwDir` a `consoleMode` do statických parametrů (jinak by warm-up z CLI
  vyrobil kontejner, který server nenačte — `getDefaultParameters()` je
  odvozuje od vstupního skriptu a SAPI) a chybovou hlášku pro případ, kdy
  build zastará. Aplikace je malá, účet v cílovém nasazení jeden; sdílet
  tedy není co komu.
- **auto-rebuild kontejneru** — u šablon se revalidace zapíná, protože
  Latte ji má a jde k ní konfigurací. U kontejneru ne: `loadContainer()` si
  bere auto-rebuild z `%debugMode%` natvrdo (`Configurator.php:283-293`),
  takže rozvázat by se to dalo jen vlastním potomkem `Configuratoru`, který
  by duplikoval i cestu `/nette.configurator`. Navíc by to znamenalo zámek
  a kontrolu celého stromu závislostí při každém requestu. Revize udělá
  totéž jedním `stat`em a bez sahání do cizích tříd.
- **mutování `defaultExtensions`** — cesta, jak `setAutoRefresh` přepsat
  přes veřejnou property `Configurator::$defaultExtensions`. Znamenalo by to
  podstrčit `true` na místo, které se jmenuje `debugMode`, a tím zároveň
  zapnout Tracy panel Latte. Konfigurace říká jednu věc na jednom místě.
- **obsahový hash `installed.php` místo mtime** — pokryl by i referenci
  kořenového balíčku, ale četl by 8 kB při každém requestu. Falešný poplach
  z mtime stojí jednu kompilaci.
- **úklid starých revizí** — XDG cache se smí smazat kdykoli celá.
- **`nette/caching`** — není nainstalované a `{cache}` se v šablonách
  nepoužívá. `%cacheDir%` dnes obsahuje jen `sessions/` a kompilát.
- **systemd unit a balíček** — instalace je v readme posloupnost příkazů;
  zabalit ji je samostatná práce.
- **`DONUT_HOME` a `DONUT_PROFILE`** — profil se zvenčí nastavit už dá
  (`config/common.neon:17`) a tenhle návrh se ho nedotýká.
