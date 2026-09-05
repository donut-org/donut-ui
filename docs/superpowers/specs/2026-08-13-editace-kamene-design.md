# Editace kamene — návrh

Datum: 2026-08-13

## Cíl

Zakládat, upravovat a mazat kameny v GUI. Je to druhý ze tří projektů, na
které se rozpadla vrstva 3 GUI (builder).

Kámen jde první před workflow, protože je to nejmenší objekt bez vnořených
kroků — ověří celou zápisovou cestu formulář → objekt → serializér → soubor
→ znovu naparsovat na tvaru, který se dá udržet v hlavě.

Referenční pravda formátu je `docs/format-specifikace.md` verze 0.3.
Serializér, na kterém to stojí, popisuje
`2026-08-06-serializer-design.md` v repozitáři `donut-org/donut`.

## Rozsah

Upravit existující kámen, založit nový, smazat.

## Co přibude v donutu

`Donut\Validator\BlockValidator` s jedinou metodou `validate(Block): Result`.

Donut ty kontroly **už má** — `Validator::checkStdinNotInArgs()`
a `checkArgsInputsDeclared()` se dívají jen na kámen a na nic jiného. Jsou
ale privátní a dostupné jen přes `validate(Workflow)`, který potřebuje
workflow, jež kámen volá. Samotný kámen se dnes zvalidovat nedá.

Obě se přestěhují do `BlockValidator` **doslova** a `Validator::checkRun()`
je začne volat tam. Je to přesun, ne přepis; ověří ho fakt, že celá dnešní
sada donutu projde beze změny.

### Proč vlastní třída, a ne `Validator::validateBlock()`

`Validator::__construct()` bere `BlockRepository`, protože workflow musí
kameny dohledávat podle jména. Kontrola samotného kamene nepotřebuje nic než
ten kámen. Statická metoda na třídě, která jinak žije z konstruktoru, by tu
závislost jen zamlžila.

### Proč to nenapsat v GUI znovu

Dvě kopie téhož pravidla se dřív nebo později rozejdou. Je to tentýž
argument, kterým návrh GUI odmítl počítat tok klíčů v GUI podruhé, a kterým
serializér skončil v donutu místo v GUI.

### Cesty k souborům se v donutu neřeší

`BlockRepository` nedostane `getPath()`. Nový kámen žádnou cestu nemá, takže
i kdyby existoval, GUI by ji u zakládání muselo složit stejně. Skládá se
proto na jednom místě na straně GUI — viz `BlockStore`.

## Tvar GUI

Čtyři nové jednotky v `gui/src/`:

| jednotka | co dělá |
|---|---|
| `BlockStore` | jediné místo, které ví, že kámen `curl-get` bydlí v `<projekt>/blocks/curl-get.json`. `path()`, `save()`, `delete()`, `exists()`. Čtení deleguje na `Donut\BlockRepository`, zápis na `Donut\Writer\BlockWriter`. |
| `BlockMapper` | čistá obousměrná konverze `toBlock(array $values): Block` a `toValues(Block $block): array`. Nezná Nette ani HTTP. |
| `BlockUsage` | projde stromy kroků všech workflow a vrátí mapu *jméno kamene → workflow, která ho používají*. |
| `BlockPresenter` | rozšíří se o `renderEdit()`, `createComponentBlockForm()` a `actionDelete()`. |

K tomu šablona `edit.latte` a `www/block-form.js`.

**`BlockMapper` je jednotka, která si zaslouží testy.** Všechno ostatní je
formulář, šablona a delegace.

## Formulář

Přidá se `nette/forms` do `gui/composer.json`.

**Nette Forms na všechno, ne jen na plochá pole.** `args` i `inputs` budou
dynamicky vytvořené kontejnery, jejichž počet se při POSTu odvodí z došlých
dat a při GETu z načteného kamene. Číst opakující se pole ze `$_POST`
napřímo by bylo méně kódu, ale znamenalo by to dvě cesty validace a dvě
cesty pro znovunaplnění formuláře po chybě.

### JS nikdy nepřečísluje řádky

Tohle je jádro návrhu formuláře.

Přidání řádku dostane index o jedna vyšší, než je současné maximum. Smazání
řádku odstraní uzel z DOMu a nechá v číslování díru. Server pole srovná přes
`array_values()`.

Přečíslovávání `name` atributů po smazání prostředního řádku je klasický
zdroj tichých chyb — prohodí se dva argumenty a nikde to není vidět. Když se
nepřečíslovává, ta chyba nemá kde vzniknout.

Díra v poli je navíc přesně ten případ, který serializér od opravné vlny
ošetřuje a na který má testy, takže druhá strana je hotová.

### Prázdné řádky se zahazují

Argument, který je prázdný řetězec, a skupina, ve které nezbyl žádný
argument, do souboru nejdou. Bez toho by každé „přidal jsem řádek a rozmyslel
si to" nechalo v souboru `""`.

### stdin

Zaškrtávátko „kámen čte stdin", k tomu povinnost a popis. Nezaškrtnuté
znamená, že objekt v souboru není.

## Validace při uložení

**Chyba blokuje, varování ne.** `Problem::Error` uložení odmítne a vypíše se
u formuláře; `Problem::Warning` se ukáže, ale uloží se.

GUI si nevymýšlí vlastní závažnosti — `Problem` a `Result::hasErrors()`
v donutu existují a tohle je přesně jejich rozdělení.

## Mazání

**Kontroluje odkazy a při nálezu odmítne.** Když `BlockUsage` najde
workflow, které kámen používá, mazání se neprovede a vypíše se, kdo ho drží.

Je to stejné pravidlo jako u ukládání. Smazat kámen, na který se odkazuje
workflow, není varování — je to rozbití něčeho, co fungovalo.

Mazání jde **přes POST s potvrzením**, ne přes odkaz. GET, který maže
soubor, si dřív nebo později najde přednačítač v prohlížeči.

Mapa z `BlockUsage` slouží dvakrát: pro tuhle kontrolu a pro přehled kamenů,
kde u každého bude vidět, kdo ho používá.

## Testy

Těžiště je `BlockMapper`, protože je to čistá funkce a všechna zajímavá
pravidla jsou v ní:

- **Round-trip nad referenční zátěží** — vzít všech patnáct skutečných
  kamenů z `docs/workflows/donut/blocks/`, projít je `Block → values →
  Block` a porovnat. Stejný trik jako u serializéru a stejně silný: kdyby
  mapper zahodil `timeout` nebo `default` u vstupu, tohle to chytí.
- Díry v indexech se srovnají, prázdné řádky a prázdné skupiny vypadnou,
  `stdin` se objeví a zmizí podle zaškrtávátka.

K tomu `BlockUsage` nad vnořenými kroky (`then`, `else`, `foreach`),
`BlockStore` na skládání cesty a na mazání, a v donutu test, že přesunuté
kontroly hlásí totéž co dřív.

`Latte.TemplatesCompile.phpt` novou šablonu pochytá sám, bez zásahu.

### Co testy nepokryjí

**Ten JS.** V projektu není nic, co by JS spouštělo, a kvůli čtyřiceti
řádkům se prohlížečový harness zavádět nebude.

Zmírňuje to tvar návrhu: JS jen klonuje řádky a přiděluje indexy, žádné
pravidlo formátu nezná. Když se pokazí, formulář vypadá špatně okamžitě —
nemůže vyrobit tiše špatný soubor, protože všechno, na čem záleží, srovnává
server.

Je to vědomá díra, ne přehlédnutí.

## Co se vědomě nedělá

- **Detekce souběhu.** Převzato ze serializéru: soubor upravený v editoru
  mezi vykreslením formuláře a uložením se přepíše bez varování. Lokální
  nástroj pro jednoho člověka, soubory jsou v gitu.
- **CSRF ochrana a session.** `Form::addProtection()` potřebuje session, kterou
  GUI nemá. Ze stejného důvodu se nepoužijí ani flash zprávy — odmítnutí se
  ukáže jako chyba formuláře. Je to důsledek téhož rozhodnutí jako
  „`127.0.0.1`, žádná autentizace" v návrhu GUI. Cena je reálná: stránka
  otevřená v témž prohlížeči by teoreticky mohla poslat POST na localhost.
  U nástroje pro jednoho člověka, jehož data jsou v gitu, se to přijímá.
- **Přejmenování kamene.** Změna jména by znamenala přesun souboru a úpravu
  všech workflow, která na kámen odkazují. Jméno je ve formuláři jen při
  zakládání.
- **Validace jmen vstupů proti formátu.** `docs/format-specifikace.md`
  povoluje `[A-Za-z0-9_]+`, což zahrnuje `"0"`; PHP by z toho udělalo
  celočíselný klíč a `inputs` by se zakódovaly jako pole. Parser má tutéž
  slepou skvrnu, takže žádný *soubor* takový objekt nevyrobí — vyrobit ho
  umí až GUI. Je to skutečná díra, ale patří do samostatné kontroly jmen
  napříč formátem, ne do editace kamene.
- **Prohlížečové testy.** Viz výše.
