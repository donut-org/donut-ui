# Krok `run` — pevné vstupy a výstupy — návrh

Datum: 2026-08-27

## Cíl

Formulář kroku `run` v GUI bude vědět, který kámen krok volá, a podle jeho
deklarace postaví **pevný seznam vstupů** — jeden řádek na vstup, ne prázdná
políčka, do kterých uživatel jméno vstupu opisuje. Výstupy do mapy přestanou
být proměnlivým seznamem selectboxů a stanou se třemi pevnými poli, protože
kanály jsou právě tři.

Referenční pravda formátu je `docs/format-specifikace.md`.

## Co je hotové a na čem se staví

**Přejmenování kanálu už proběhlo.** Kanál `result` se jmenuje `stdout`
(větev `channel-stdout`, zmergováno). Bez toho by tři pevná pole nesla jméno,
které specifikace sama uváděla jako příklad překlepu.

**Stránka kroku existuje.** `WorkflowPresenter::actionStep()` už rozlišuje
editaci od zakládání, `StepPath` adresuje místo ve stromu, `StepMapper`
překládá hodnoty formuláře na `Step` a zpět, `step.latte` vykresluje čtyři
typy kroků. Nestaví se nová sekce, mění se vnitřek jedné karty a přibývá
jedna mezistránka.

**Donut se nemění.** `BlockRepository`, `Validator` i `Format\Block` mají
všechno, co je potřeba. Práce je celá v `gui/`.

## Proč to dnes nestačí

Dnešní formulář se u kroku `run` chová stejně, ať krok volá jakýkoli kámen:
`in` je proměnlivý seznam dvojic *jméno vstupu → hodnota*, `out` proměnlivý
seznam dvojic *kanál → klíč*. Uživatel jméno vstupu **píše ručně**, ačkoli
kámen ho deklaruje a validátor ho hned potom kontroluje. Překlep v jméně
vstupu tak vzniká na místě, kde by vzniknout vůbec neměl.

U výstupů je to horší: kanály jsou tři a formulář kolem nich staví
přidávání a mazání řádků, přestože přidat se dá nanejvýš třetí a víc řádků
než tři je vždycky chyba.

## Nález, který mění zadání

Prázdná hodnota vstupu **není** totéž co chybějící vstup, jakmile má vstup
`default`. `CommandLine::resolveValues()` (`src/Runner/CommandLine.php:69`):

```php
if (isset($in[$name])) {
    $value = $in[$name]->render($map);       // "" → nevyplněno, default se přeskočí
} elseif ($input->default !== null) {
    $value = $input->default;                // klíč chybí → default se použije
}
```

Klíč přítomný s prázdnou hodnotou tedy **umlčí default**, kdežto klíč
chybějící ho pustí ke slovu. Dnešní `StepMapper::toIn()` přitom prázdné
hodnoty do `in` zapisuje.

Dnes to skoro nevadí: prázdné políčko musí uživatel vyrobit tím, že hodnotu
smaže. S pevným seznamem má ale **každý volitelný vstup viditelné prázdné
políčko**, takže by otevřít a uložit libovolný krok znamenalo zapsat prázdný
řetězec ke každému nevyplněnému vstupu — a umlčet tím všechny defaulty
kamene.

**Formulář proto prázdný slot do `in` nezapíše vůbec.** Specifikace říká, že
prázdno a nevyplněno je jedna a tatáž věc (sekce „Zrušení rozdílu
»nevyplněno« vs `""`"), takže vynechání klíče o nic nepřichází a chování
defaultů zůstane celé.

Ta asymetrie v runneru je nejspíš přehlédnutí, ne záměr — specifikace ji
nikde nepopisuje. Tenhle projekt ji neřeší, jen ji obchází tím, že prázdný
klíč nikdy nevyrobí. Stojí za samostatný pohled.

## Co vzniká

### `Donut\Gui\BlockInputs` — sloty formuláře

Čistý překlad *kámen + krok → seřazený seznam slotů*, bez Nette a bez HTTP,
stejně jako `BlockMapper`, `InputMapper`, `RowShape` a `KeyMap`. Logika
nejde do `WorkflowPresenter`, který má už teď 620 řádků a je největším
souborem v `gui/`; a nejde ani do `StepMapper`, který dnes umí jen
`hodnoty ↔ Step` a o kamenech nic neví.

Jeden slot nese `name`, `required`, `default`, `description`, `value`
a `declared`. Pořadí je:

1. **deklarované vstupy** v pořadí, v jakém je kámen deklaruje
   (`Block::$inputs` drží pořadí z JSONu),
2. **`stdin`**, když `Block::$stdin !== null`,
3. **nedeklarované klíče** z `RunStep::$in`, které do 1 ani 2 nepatří,
   v pořadí ze souboru.

Skupina 3 je u zakládání vždy prázdná — nový krok žádné `in` nemá.

`required` slotu se řídí **přesně pravidlem validátoru**
(`src/Validator/Validator.php:160,168`):

| slot | povinný, když |
|---|---|
| deklarovaný vstup | `$input->required && $input->default === null` |
| `stdin` | `$block->stdin->required` |
| nedeklarovaný | nikdy — naopak musí být prázdný |

Formulář tím nepustí uložení kroku, který by validátor odmítl jako
nevyplněný povinný vstup.

Třída má dvě statické metody — jednu pro každý směr:

```php
/** @return list<Slot> sloty i s hodnotou z kroku */
public static function slots(Block $block, ?RunStep $step): array;

/** @return list<array{key: string, value: string}> řádky pro StepMapper */
public static function rows(array $slots, mixed $post): array;
```

`slots()` staví formulář: popisek, `setRequired()`, placeholder i výchozí
hodnota políčka pocházejí ze slotu, takže `in` se do `setDefaults()` vůbec
nedostane.

`rows()` spojí POST zpátky se jmény — `$post[$i]` patří ke `$slots[$i]` —
a **vynechá sloty s prázdnou hodnotou**. Pravidlo z „Nálezu, který mění
zadání" tak žije na jednom místě; `StepMapper::toIn()` se nemění a prázdnou
šablonu nemá jak dostat.

### Mezistránka `Workflow:pickBlock`

Zakládaný krok `run` musí kámen znát dřív, než se formulář postaví.
Odkaz „+ step: **run**" ve `steps.latte` proto nově míří na `pickBlock`
s parametry `name` a `at`; `set`, `if` a `foreach` míří na `step` jako dosud.

Stránka vypíše **karty kamenů** — jméno, popis a počet vstupů. Karta je
odkaz na `Workflow:step` s `type=run` a `block=<jméno>`.

Rozbitý soubor kamene ostatní neschová a hlásí se u své karty; to je pravidlo,
které `Block:default` i `donut --list` už dodržují. Chybějící adresář
`blocks/` se ohlásí s `ProfileDir::hint()`.

## Co se mění

### `WorkflowPresenter::actionStep()`

Přibývá parametr `block` a resolvování kamene:

- **nový krok** — jméno kamene z parametru `block`,
- **editace** — jméno kamene z `$editedStep->block`; parametr `block`
  se ignoruje, stejně jako se dnes ignoruje `type`.

Kámen, který v `blocks/` **není nebo se nepodaří načíst**, ukončí akci přes
`$this->error()` — formulář se neotevře. Krok jde pořád smazat ze stromu
a validace na něj upozorňuje už dnes; otevřít pro něj formulář by znamenalo
mít režim, ve kterém jde omylem uložit půlku dat.

Zakládání `run` **bez** parametru `block` je chyba adresy, ne 404: odpovídá
`S400_BadRequest`, stejně jako `at`, které se nedá rozparsovat.

### `createComponentStepForm()` — větev `run`

Kontejner `in` má jeden podkontejner na slot, **číslovaný od nuly**
(`in[0]`, `in[1]`, …). Jméno vstupu se do POSTu neposílá.

Jména vstupů totiž nejsou nijak omezená: `JsonSource::parseInputs()` bere
jako jméno libovolný string, zatímco jméno komponenty v Nette musí odpovídat
`[a-zA-Z0-9_]+`. Kámen se vstupem `api-key` je legální a kontejner klíčovaný
jménem vstupu by na něm spadl. Server si jméno přiřadí podle pořadí sám —
je to i idiom, který v tom souboru už je: *„The server decides the type, not
the hidden input from the POST."*

Slot skupiny 3 (nedeklarovaný) dostane `addRule(Form::Blank, …)`. Uložit tedy
jde až po vyprázdnění pole — a protože prázdný slot se do `in` nezapíše, tím
ze souboru zmizí. Mlčky se neztratí nic: hodnota je vidět, dokud ji uživatel
sám nesmaže.

Kontejner `out` má tři pevná textová pole pojmenovaná `stdout`, `stderr`
a `exit_code`. Prázdné pole znamená, že se kanál nemapuje.

Select `block` z formuláře **mizí**. V editaci je kámen jen text s odkazem na
`Block:detail` — přepnout kámen by nechalo stát vstupy toho původního a
formulář nemá jak poznat, které hodnoty do nového patří. Jiný kámen znamená
jiný krok: smazat a založit znovu.

`rows.js` a `RowShape` se z větve `run` **odpojí**. Pro editaci kamene
(`args`, `inputs`) a pro hlavičku workflow zůstávají beze změny — tam je
seznam skutečně proměnlivý.

### `StepMapper`

`toIn()` zůstává, jak je — dostává řádky `{key, value}`, jen je tentokrát
staví `BlockInputs::rows()`, ne uživatel.

`toValues()` přestane vracet `in`. Výchozí hodnoty vstupů nese slot, a klíč
`in` v tom poli by `setDefaults()` posílal na kontejner s jinou strukturou,
než jakou dnes `toValues()` vyrábí.

`toOut()` a `out` v `toValues()` se přepisují z řádků `{channel, value}` na
tři pojmenované klíče:

```php
'out' => ['stdout' => 'id', 'stderr' => '', 'exit_code' => 'rc']
```

Prázdný klíč znamená, že se kanál nemapuje. Řádek s kanálem a bez klíče
přestane existovat jako pojem — prázdné pole je jediný způsob, jak říct
„nemapovat".

### `step.latte`

Karta „Block inputs" je tabulka bez tlačítek na přidání a mazání řádku.
Sloupce: jméno vstupu (text, ne `<input>`), hodnota, poznámka. `description`
vstupu je nápověda pod polem, `default` je placeholder. Kámen bez vstupů
řekne „The block has no inputs." — stejnou větou, jakou už používá strom.

Nedeklarované sloty stojí pod tabulkou ve vlastním bloku s vysvětlením, proč
tam jsou a proč musí být prázdné.

Karta „Outputs to the map" vypíše tři pole pod sebou, každé se jménem kanálu
jako popiskem.

## Testy

| soubor | co hlídá |
|---|---|
| `BlockInputs.phpt` | pořadí slotů, `stdin` na svém místě, `required` podle validátoru, nedeklarované klíče na konci, kámen bez vstupů |
| `StepMapper.phpt` | prázdný slot se do `in` nezapíše; tři kanály tam a zpět |
| `WorkflowPresenter.pickBlock.phpt` | karty kamenů, odkaz nese `block`, rozbitý soubor ostatní neschová, chybějící `blocks/` s hintem |
| `WorkflowPresenter.step.phpt` | pevné sloty podle kamene, `setRequired()` jen kde má být, `Blank` na nedeklarovaném, chybějící kámen nevydá formulář, `run` bez `block` je 400 |

`BlockInputs.phpt` je nová smlouva mezi GUI a validátorem — patří k němu
proto stejná pojistka, jakou má `KeyMap.ValidatorContract.phpt`: aserce, že
slot označený jako povinný odpovídá tomu, co validátor u nevyplněného vstupu
hlásí jako chybu. Bez ní by se obě pravidla mohla rozejít, aniž by cokoli
spadlo.

## Co se vědomě neřeší

- **Přepnutí kamene u existujícího kroku.** Viz výše — jiný kámen je jiný
  krok.
- **Napovídání klíčů mapy do hodnot vstupů.** Hodnota je šablona
  a `KeyMap` ví, které klíče v tom místě existují; je to ale samostatná věc
  a bez ní formulář funguje.
- **Asymetrie prázdné hodnoty a defaultu v runneru.** Tenhle projekt ji
  obchází, neopravuje.
- **Kontrola, že jméno vstupu kamene je použitelné v šabloně.** Kámen se
  vstupem `api-key` je dnes legální, i když ho `{%api-key%}` nikdy
  nezreferencuje. Formulář si s ním poradí; jestli to má formát zakázat, je
  otázka na formát, ne na GUI.
