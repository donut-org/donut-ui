# Donut GUI

[![Build Status](https://github.com/donut-org/donut-ui/workflows/Build/badge.svg)](https://github.com/donut-org/donut-ui/actions)
[![Downloads this Month](https://img.shields.io/packagist/dm/donut-org/donut-ui.svg)](https://packagist.org/packages/donut-org/donut-ui)
[![Latest Stable Version](https://poser.pugx.org/donut-org/donut-ui/v/stable)](https://github.com/donut-org/donut-ui/releases)
[![License](https://img.shields.io/badge/license-New%20BSD-blue.svg)](https://github.com/donut-org/donut-ui/blob/master/license.md)

Autorské prostředí pro workflow a kameny donutu. Ukazuje, co by řekl
validátor, ještě než workflow doběhne na skutečnou kartu, a umí kameny,
celá workflow i jejich jednotlivé kroky založit, upravit a smazat.

Návrhový dokument: `docs/superpowers/specs/2026-08-05-gui-design.md`.


## Instalace

Balíček je `type: project`, takže se instaluje přes `create-project`:

```bash
composer create-project donut-org/donut-ui
```

Composer založí nový adresář `donut-ui` a rovnou do něj stáhne i závislosti.
Kdo pracuje přímo z klonu tohoto repozitáře, spustí místo toho:

```bash
composer install
```

Do adresáře repozitáře se za běhu nezapisuje. Kompilát kontejneru a šablon
i sessions jdou do `$XDG_CACHE_HOME/donut-ui` (výchozí `~/.cache/donut-ui`),
log do `$XDG_STATE_HOME/donut-ui/log` (výchozí
`~/.local/state/donut-ui/log`). Obojí si aplikace založí sama a obojí jde
přepsat — `DONUT_GUI_CACHE` a `DONUT_GUI_LOG`. Instalace tak může patřit
rootovi a být pro toho, kdo GUI spouští, jen ke čtení.


### Vývoj proti rozpracovanému jádru

`composer.json` požaduje vydané jádro. Při souběžné práci na jádru
i GUI použij druhý manifest, který si vezme sousední checkout donutu:

```bash
COMPOSER=composer-dev.json composer install
```

Nainstaluje `donut-org/donut` symlinkem z `../donut`, takže změny
v jádru jsou vidět okamžitě. Přibude-li závislost, musí se zapsat do
`composer.json` i `composer-dev.json`.


## Spuštění

GUI hledá `blocks/` a `workflows/` v **profilu**, stejně jako CLI: v
`$DONUT_HOME/$DONUT_PROFILE/{blocks,workflows}`, výchozí
`~/.config/donut/default`. Pracovní adresář, odkud server spustíš, roli
nehraje.

Nejrychlejší cesta je `make server` — spustí vestavěný PHP server
nad **výchozím profilem**, tedy nad tím samým, který by vzal CLI:

```bash
make server
```

Nad ukázkovou sadou z repozitáře (`docs/workflows/donut`) přes proměnné:

```bash
make server home=$(pwd)/docs/workflows profile=donut
```

GUI běží ve **výchozím stavu v produkčním režimu** — bez Tracy baru, protože
pro toho, kdo v něm autoruje, je to hotová aplikace. Neodchycená chyba se
zapíše do `exception.log` v adresáři logu (`~/.local/state/donut-ui/log`)
a uživatel dostane stránku, ne bluescreen.

Při práci na samotném GUI:

```bash
make server debug=1
```

To zapne Tracy a zároveň **rozmrazí cache DI kontejneru**. Úpravy šablon se
projeví i bez toho — Latte si obsah hlídá pořád. Kontejner se jinak přestaví
sám až s novou instalací, poznanou podle `vendor/composer/installed.php`.
Přepínač odpovídá proměnné `DONUT_GUI_DEBUG`.

Ruční spuštění nad libovolným profilem:

```bash
DONUT_HOME=~/.config/donut DONUT_PROFILE=default \
	php -S 127.0.0.1:8000 -t /cesta/k/donut-ui/www /cesta/k/donut-ui/www/index.php
```

a otevřít <http://127.0.0.1:8000/>.

Router script (`www/index.php` jako poslední argument) je nutný proto,
aby vestavěný server **neudělal** `chdir()` do docrootu — díky tomu může
`-t` ukazovat na `www` (odkud se servírují assety), a GUI přitom
`blocks/` a `workflows/` vůbec nehledá podle toho, odkud proces běží —
o tom rozhoduje jen profil z `DONUT_HOME`/`DONUT_PROFILE`.

Router zároveň každý požadavek nejdřív pošle do `index.php`; statický soubor
se vydá jen tehdy, když ho `Donut\Gui\StaticFile::shouldServe()` uzná za
existující soubor uvnitř `www`, kromě routeru samotného.

Assety leží v `www/assets/`. Bootstrap je verze **5.3.8**, vendorovaný
ručně z `https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/`; aktualizace
znamená nahradit `bootstrap.min.css` a `bootstrap.bundle.min.js` novými
soubory odtamtud. Žádný build krok, žádný `npm`.

`netteForms.min.js` je zkopírovaný z `vendor/nette/forms/src/assets/`;
po `composer update` ho zkopíruj znovu. Zapíná klientskou validaci pro
všechny formuláře a je to i to, co dává smysl `toggle()` — bez něj se
pravidla vykreslí a nikdo je nezpracuje.


## Co je vidět

- **seznam workflow** — název a popis každého workflow z `workflows/`
- **detail workflow** — kroky ve stromu (`if`/`foreach` vnořené) a problémy
  z validátoru u kroku, kterého se týkají
- **editace kroku** — u každého kroku odkaz „edit" na formulář podle jeho
  typu (`run`, `set`, `if`, `foreach`); pod stromem i v každé vnořené větvi
  jde krok daného typu přidat, přesunout nahoru/dolů nebo smazat (mazání
  krokem s podstromem se ptá na potvrzení)
- **krok `run` zná svůj kámen** — nový `run` se zakládá přes výběr kamene
  z karet, takže formulář má **pevný seznam vstupů**: nejdřív `stdin`, když
  ho kámen čte (bývá to hlavní obsah, ne přepínač), pak jeden řádek na každý
  vstup, který kámen deklaruje. Hodnoty se píšou do pole, které roste podle
  toho, kolik řádků do něj napíšeš. Povinné vstupy formulář vynutí podle
  stejného pravidla jako validátor. Prázdný vstup se do souboru nezapíše — prázdná hodnota by umlčela default kamene, chybějící
  klíč ho pustí ke slovu. Klíč, který kámen nedeklaruje, je vidět s hláškou
  a uložit jde až po jeho vyprázdnění. Kámen se v editaci nepřepíná: jiný
  kámen znamená jiný krok. Výstupy do mapy jsou tři pole (`stdout`, `stderr`,
  `exit_code`); prázdné pole znamená, že se kanál zahodí
- **přehled kamenů** — tabulka kamenů z `blocks/`: jméno, popis, příkaz
  a workflow, která kámen používají; založení, editace a mazání kamene
  formulářem
- **detail kamene** — popis, příkaz, argumenty ve skupinách, deklarované
  vstupy, stdin, timeout, `allow_failure` a seznam workflow, která kámen
  volají
- **hlavička workflow** — založení nového workflow, editace jména (jen při
  založení), popisu a vstupů, a mazání; mazání jen upozorní, že se workflow
  spouští jménem z cronu a z CLI, což GUI nevidí
- **tok klíčů** — u každého kroku je vidět, které klíče čte a které zapisuje;
  klíč je klikatelný odkaz, který zvýrazní všechny kroky, kde figuruje (zápis
  jinou barvou než čtení); výběr drží adresa (`&key=repo`), takže se dá poslat
  odkazem; podmíněný zápis se pozná z toho, že zvýrazněný krok leží uvnitř
  `if` nebo `foreach`

Adresy jsou v query stringu, např.
`?name=card-dev&action=detail&key=repo` — router je Nette
`SimpleRouter`, žádné pěkné URL.


## Co GUI vědomě neumí

Není to seznam nedodělků — jsou to rozhodnutí z návrhu:

- **přejmenovat workflow ani kámen** — obojí se spouští jménem z cronu
  a z CLI, což GUI nevidí; jméno je proto ve formuláři jen při zakládání
  a při editaci je needitovatelné
- **kontrolovat odkazy při mazání workflow** — uvnitř formátu není co
  kontrolovat, vně formátu (cron, CLI) to GUI nevidí; u kamenů kontrola
  je, protože na ně workflow odkazují uvnitř formátu
- **detekovat souběh** — soubor upravený v editoru mezi vykreslením
  stránky a uložením se přepíše bez varování
- **CSRF ochranu a session** — kryje to kontrola Fetch-Metadata i bez session:
  formuláře si o same-origin říkají samy ve `Form::signalReceived()`, metodám
  `handle*` připojuje Nette `Requires(sameOrigin: true)` automaticky — a jinou
  cestou než formulářem nebo signálem `handle*` GUI na disk nezapisuje
  (přesun a mazání kroku jsou `handle*` ve `StepTreeControl`)
- **zakládat adresáře `workflows/` a `blocks/`** — chybějící adresář v
  profilu se jen ohlásí, i s příkazem `mkdir -p`, kterým ho vytvořit
- **přepínat profil za běhu** — profil určí proměnné prostředí
  `DONUT_HOME`/`DONUT_PROFILE` při startu serveru; přepnutí znamená restart
  s jinou hodnotou. GUI jméno profilu jen ukazuje v hlavičce
- **přepnout kámen u existujícího kroku** — vstupy jsou pevné podle kamene
  a formulář nemá jak poznat, které hodnoty patří do nového; jiný kámen je
  jiný krok, tedy smazat a založit znovu
- **otevřít krok `run`, jehož kámen chybí nebo se nedá přečíst** — celý seznam
  vstupů pochází z kamene, takže bez něj není z čeho formulář postavit: krok
  volající kámen, který v `blocks/` není, odpoví 404, a krok volající kámen
  s rozbitým souborem dá stránku s chybovou hláškou a bez formuláře. Takový
  krok jde ve stromu workflow už jen smazat


## Testy a statická analýza

Testy a statická analýza se spouští z kořene repozitáře:

```bash
vendor/bin/tester tests -C
vendor/bin/phpstan analyse
```

`phpstan.neon` běží na `level: max`, stejně jako `phpstan.neon`
v kořeni repozitáře `donut-org/donut` — nula chyb platí pro obojí, ne jen
pro `src/` a `tests/` donutu.
