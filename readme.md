# Donut GUI

Autorské prostředí pro workflow a kameny donutu. **Jen pro čtení** — nic
nezapisuje. Ukazuje, co by řekl validátor, ještě než workflow doběhne na
skutečnou kartu.

Návrhový dokument: [`../docs/superpowers/specs/2026-08-05-gui-design.md`](../docs/superpowers/specs/2026-08-05-gui-design.md).


## Instalace

```bash
cd gui
composer install
```

Adresář `gui/temp/` musí existovat a být zapisovatelný — používá ho Nette
pro cache kontejneru a šablon.


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
projektu — tiše, bez chybové hlášky (HTTP 200, „V adresáři nic není").


## Co je vidět

- **seznam workflow** — název a popis každého workflow z `workflows/`
- **detail workflow** — kroky ve stromu (`if`/`foreach` vnořené) a problémy
  z validátoru u kroku, kterého se týkají
- **přehled kamenů** — kameny z `blocks/` s jejich deklarovanými vstupy

Adresy jsou v query stringu, např.
`?presenter=Workflow&action=detail&name=card-dev` — router je Nette
`SimpleRouter`, žádné pěkné URL.


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


## Co zatím není

Tohle je vrstva 1 ze tří (viz `docs/zadani.md`, bod 5). Zatím chybí:

- zápis (uložení workflow/kamene zpátky do JSONu)
- vizualizace toku klíčů (kde klíč vzniká, kdo ho čte)
- builder — formulářové skládání kroků

Podrobnosti a proč jsou tyhle vrstvy odložené: [`../docs/superpowers/specs/2026-08-05-gui-design.md`](../docs/superpowers/specs/2026-08-05-gui-design.md).
