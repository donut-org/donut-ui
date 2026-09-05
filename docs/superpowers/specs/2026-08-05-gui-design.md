# GUI pro donut — návrh

Datum: 2026-08-05

## Cíl

Prostředí pro **psaní** workflow a kamenů. Je to krok 5 z `docs/zadani.md`.

Ne pro spouštění. Zadání říkalo „GUI později, jako Nette app nad stejnými
service třídami" a předpokládalo, že GUI bude workflow i pouštět — to padlo:
běhy zůstávají na CLI a cronu, GUI je autorské.

Referenční pravda formátu je `docs/format-specifikace.md` verze 0.3.

## Co GUI řeší

Čtyři věci, které zdržují při psaní:

1. **Chyby se poznají až při spuštění.** Validátor existuje, ale volá ho jen
   `Runner`. Při psaní není nic vidět.
2. **Není přehled, co který kámen bere.** Při patnácti kamenech se to nedá
   držet v hlavě a `blocks/*.json` se musí otevírat ručně.
3. **Tok klíčů se u 27 kroků neuhlídá.** Kde vzniká `prUrl`, kdo čte `branch`,
   co když `repo` zůstane prázdné.
4. **Psaní JSONu ručně.** Závorky, uvozovky, vnořené `steps` v `if`
   a `foreach`.

## Proč web a ne desktop

Tři ze čtyř bolestí jsou přímo nad daty, která umí jen PHP: `Validator`
vrací `Problem` objekty s cestou ke kroku, `BlockRepository` má `Block`
objekty s deklarovanými vstupy, a tok klíčů zná průchod uvnitř validátoru.
V PHP jsou zadarmo; z desktopového GUI v jiném jazyce by mezi nimi stála
serializační hranice.

Formulářový builder a vizualizace toku klíčů jsou navíc přesně to, v čem je
HTML produktivnější než desktopový toolkit.

**„Web" tu neznamená vzdálený přístup.** Běží na `127.0.0.1`, vzdálená
správa není potřeba.

## Kde co leží

`gui/` je Nette aplikace, která na donut **závisí jako na balíčku** — nesahá
do `../src`. Během vývoje v repu to zařídí path repository v `composer.json`.
Díky tomu je pozdější osamostatnění do vlastního balíčku a repozitáře přesun,
ne přepis.

### Co donut už veřejně má

`WorkflowParser`, `BlockParser`, `BlockRepository`, `Validator`, `Result`,
`Problem`, `Template` a celý `Donut\Format\*`. Nic z toho se nemění.

### Co donutu přibýt musí — až pro vrstvy 2 a 3

**Vrstva 1 nepotřebuje ani jedno z toho.** Obojí je tu popsané proto, že to
rozhoduje o hranici mezi donutem a GUI, ne proto, že by se to dělalo hned.

**Serializér** — inverze parseru. `Workflow` a `Block` objekty složí zpátky do
pole, které `Nette\Utils\Json::encode()` s formátováním uloží do souboru.
Patří do donutu, protože ty objekty definuje donut; **GUI o JSONu neví
a pracuje jen s objekty**.

Riziko není formátování, ale **tiché zahození pole, na které serializér
zapomene** — `allow_failure`, `timeout`, `stdin`, `description`, defaulty
vstupů. GUI je nemusí ani zobrazovat, uložit je musí. Parser navíc odmítá
neznámé klíče, takže cokoliv zapisovač vyprodukuje, musí parser přijmout
zpátky.

Zavírá to test v donutu: vzít všech 15 kamenů a 4 workflow
z `docs/workflows/donut/`, projít je parser → zapisovač → parser a ověřit, že
druhý objekt je totožný s prvním.

**Evidence, kde klíč vzniká a kdo ho čte.** `KeyFlow` ví, které klíče se
zapsaly a přečetly (`getWritten()`, `getRead()`), ale **ne kde** — vrací jen
jména. `branch()` navíc dělá kopie, takže evidence musí přežít i slučování
větví.

Sbírat to má tentýž průchod, který už v `Validator::checkSteps()` je. Druhý
průchod v GUI by se dřív nebo později rozešel s validátorem přesně v těch
případech, kvůli kterým vzniklo `repo-check`.

### Vazba, kterou je potřeba pojmenovat

`Problem::$location` je **řetězec** ve tvaru `card-dev.json:steps[7].then[0]`.
Aby GUI zvýraznilo ten správný krok, musí ho rozparsovat. Je to
deterministické, ale je to smlouva mezi donutem a GUI — zaslouží si test na
straně donutu, ne mlčky předpokládaný tvar.

## Formát souborů

**GUI je autorita na formát.** Ukládá vždy stejně; ruční zarovnání sloupců,
které dnes v `card-dev.json` a `sync.json` je, se opustí. První uložení
přepíše celý soubor a git diff bude velký — to je vědomá cena.

**Ruční editace se neřeší.** Soubor upravený v editoru se buď trefí do
stejného tvaru, nebo ne; naparsuje se tak jako tak a GUI ho při dalším uložení
srovná. Žádný `donut fmt` nevzniká — jednotný formát není cíl sám o sobě.

## Tvar aplikace

Minimální Nette aplikace: `nette/application` a Latte. Žádná databáze, žádné
DI kolem ničeho.

**Kde hledá `blocks/` a `workflows/`: v pracovním adresáři serveru**, stejně
jako CLI. Spustí se z `docs/workflows/donut/` a vidí totéž co `donut`. Vlastní
pravidlo by znamenalo dvě pravdy o tom, co je „projekt", a jedna by byla vždy
ta špatná.

**Žádný cache, žádný stav.** Při každém requestu se soubory přečtou a workflow
zvaliduje znovu. Je jich devatenáct a jsou malé. Cache by teď jen zaváděla
nesoulad mezi tím, co je na disku, a tím, co je vidět.

**Spouštění:** `php -S 127.0.0.1:8000 -t . <cesta k repu>/gui/www/index.php`,
spuštěné z adresáře projektu, s `index.php` jako router scriptem (bez něj by
vestavěný server udělal `chdir()` do docrootu a GUI by hledalo `blocks/` a
`workflows/` v `gui/www`). Žádný Docker, žádný build.

## Vrstvy

Staví se po vrstvách; každá je použitelná sama o sobě.

**Tenhle dokument je návrhem vrstvy 1** a zároveň rozcestníkem pro zbytek.
Vrstvy 2 a 3 jsou tu jen načrtnuté — až na ně dojde, dostane každá vlastní
návrh a plán. Načrtnuté jsou proto, že určují, kde vede hranice mezi donutem
a GUI; kdyby se to rozhodovalo až u nich, vrstva 1 by se stavěla naslepo.

### Vrstva 1 — čtení: validace a přehled kamenů

Seznam workflow, detail workflow s kroky a problémy u toho kroku, kterého se
týkají, a přehled kamenů s jejich deklarovanými vstupy.

Potřebuje jen to, co donut už má. **Nic nezapisuje.**

Jediný netriviální kus je přiřazení problémů ke krokům — z `Problem::$location`
udělat cestu do stromu kroků. To je jedna malá jednotka a jediné místo první
vrstvy, které si zaslouží testy.

Zbytek jsou šablony. Testovat je znamená psát testy na HTML; místo toho se
vrstva ověří spuštěním nad referenční zátěží v `docs/workflows/donut/`, kde je
známý výsledek: čtyři workflow, patnáct kamenů, nula chyb a nula varování.

### Vrstva 2 — tok klíčů

Vizualizace, kde klíč vzniká a kdo ho čte.

**Oprava:** tenhle odstavec původně tvrdil, že vrstva 2 vyžaduje evidenci cest
ve `Validator`u a mění donut. Nevyžaduje — `Template::getKeys()` je veřejná
a GUI si mapu odvodí z naparsovaného stromu samo. Podrobnosti a skutečný
rozsah viz `2026-08-06-gui-vrstva2-design.md`.

### Vrstva 3 — builder

Formulářové skládání kroků. Vyžaduje serializér (viz výše). Největší kus;
teprve tady GUI začne zapisovat.

**Rozpadá se na tři projekty**, protože je na jeden spec příliš velká —
každý má vlastní návrh, plán a použitelný výsledek:

1. **Serializér** — `2026-08-06-serializer-design.md` v repozitáři
   `donut-org/donut`. Žádné GUI.
2. **Editace kamene** — nejmenší objekt bez vnořování; ověří celou zápisovou
   cestu na tvaru, který se dá udržet v hlavě.
3. **Editace workflow** — hlavička, vstupy, kroky (přidat, upravit, přesunout,
   smazat). Až se ukáže jeho skutečná velikost, může se ještě rozpůlit.

Pořadí není libovolné: serializér musí být první, kámen před workflow.

## Co se vědomě nedělá

- **Spouštění workflow z GUI.** Běhy zůstávají na CLI a cronu. `Runner::run()`
  je blokující a `Reporter` má jen `step()` a `warning()` — žádné „krok
  skončil", žádný exit code, žádný výstup. Stdout kroku bez `out` navíc teče
  rovnou na terminál procesu, ne přes `Reporter`. Živý průběh v GUI by
  znamenal rozšířit obojí.
- **Přehled front a historie běhů.** Historie nemá kde být uložená — zadání
  říká „bez databáze".
- **Vzdálený přístup.** `127.0.0.1`, žádná autentizace.
- **`donut fmt`.** Viz „Formát souborů".
