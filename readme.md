# Donut GUI

Autorské prostředí pro workflow a kameny donutu. Ukazuje, co by řekl
validátor, ještě než workflow doběhne na skutečnou kartu, a umí kameny,
celá workflow i jejich jednotlivé kroky založit, upravit a smazat.

Návrhový dokument: „2026-08-05-gui-design.md" ve specifikacích repozitáře
`donut-org/donut` (`docs/superpowers/specs/`).


## Instalace

```bash
cd gui
composer install
```

`gui/temp/` si Nette vytvoří samo, stačí aby adresář `gui/` byl zapisovatelný.
Používá ho pro cache kontejneru a šablon.


## Spuštění

GUI hledá `blocks/` a `workflows/` v **pracovním adresáři serveru**, stejně
jako CLI. Spouští se tedy z adresáře projektu, ne z `gui/`:

```bash
cd docs/workflows/donut
php -S 127.0.0.1:8000 -t . /cesta/k/donut/gui/www/index.php
```

(cesta k `index.php` je absolutní nebo relativní k adresáři, ze kterého
příkaz spouštíte)

a otevřít <http://127.0.0.1:8000/>.

Router script (`gui/www/index.php` jako poslední argument) je nutný,
protože bez něj vestavěný PHP server udělá `chdir()` do docrootu (`-t`) a
GUI by pak hledalo `blocks/` a `workflows/` v `gui/www` místo v adresáři
projektu — a tam žádný z těch adresářů není, takže obě stránky ohlásí
„Adresář … neexistuje.".


## Co je vidět

- **seznam workflow** — název a popis každého workflow z `workflows/`
- **detail workflow** — kroky ve stromu (`if`/`foreach` vnořené) a problémy
  z validátoru u kroku, kterého se týkají
- **editace kroku** — u každého kroku odkaz „upravit" na formulář podle jeho
  typu (`run`, `set`, `if`, `foreach`); pod stromem i v každé vnořené větvi
  jde krok daného typu přidat, přesunout nahoru/dolů nebo smazat (mazání
  krokem s podstromem se ptá na potvrzení)
- **přehled kamenů** — kameny z `blocks/` s jejich deklarovanými vstupy,
  založení, editace a mazání kamene formulářem
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
- **CSRF ochranu a session** — Nette připojuje `Requires(sameOrigin: true)`
  ke každé metodě `handle*`, takže signály kryje kontrola Fetch-Metadata
  i bez session
- **zakládat adresáře `workflows/` a `blocks/`** — server běží v tom
  pracovním adresáři, ze kterého ho někdo spustil; chybějící adresář se
  ohlásí i s příkazem, kterým ho vytvořit


## Testy a statická analýza

`gui/` je samostatný composer projekt (viz `gui/composer.json`), testy
a PHPStan se proto spouští z `gui/`, ne z kořene repozitáře:

```bash
cd gui
vendor/bin/tester tests -C
vendor/bin/phpstan analyse
```

`gui/phpstan.neon` běží na `level: max`, stejně jako kořenový
`phpstan.neon` donutu — nula chyb platí pro obojí, ne jen pro `src/`
a `tests/` donutu.

CI matice donutu `gui/` zatím nespouští — je to jiný composer projekt
a chystá se do vlastního repozitáře.
