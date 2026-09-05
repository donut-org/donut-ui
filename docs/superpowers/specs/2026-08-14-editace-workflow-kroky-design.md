# Editace workflow — kroky — návrh

Datum: 2026-08-14

## Cíl

Přidávat, upravovat, přesouvat a mazat kroky existujícího workflow v GUI.

Je to první ze dvou projektů, na které se rozpadla editace workflow — třetí
a poslední část vrstvy 3 GUI (builder).

Referenční pravda formátu je `docs/format-specifikace.md` verze 0.3.

## Rozdělení na dva projekty

Návrh vrstvy 3 (`2026-08-05-gui-design.md`) u editace workflow předem řekl:
„Největší kus; až se ukáže jeho skutečná velikost, může se ještě rozpůlit."
Ukázala se: až 37 kroků na workflow, hloubka vnoření 3, čtyři typy kroků.

1. **Kroky** — tenhle dokument. Souborová vrstva a operace nad stromem kroků.
2. **Obálka** — založit a smazat workflow, upravit jméno, popis a vstupy.
   Skoro opis editace kamene.

**Kroky jdou první**, protože hodnotu mají hned: čtyři skutečná workflow
v `docs/workflows/donut/` se dají rovnou editovat a slouží jako zátěž.
Workflow, do kterého se nedají přidat kroky, by samo o sobě bylo k ničemu.

## Co je hotové a na čem se staví

**Editační plocha už existuje.** `detail.latte` a `steps.latte` rekurzivně
vykreslují celý strom kroků, adresovaný přes `StepPath`, s problémy
u dotčených kroků a se zvýrazněním toku klíčů. `WorkflowPresenter` už staví
`Validator`, `ProblemMap` i `KeyMap`. Nestaví se nová stránka, jen se doplní
ovládání.

**Donut se nemění vůbec.** Poprvé za celou vrstvu 3. `WorkflowWriter` vznikl
u serializéru, `Validator::validate(Workflow)` existuje od začátku,
`WorkflowRepository` je v GUI.

## Co vzniká

Čtyři jednotky v `gui/src/`:

| jednotka | co dělá |
|---|---|
| `StepPath::parse()` | rozšíření existující třídy — dnes cestu jen skládá, potřebuje ji umět i rozebrat |
| `StepTree` | čisté strukturální operace nad stromem: `get`, `replace`, `insert`, `remove`, `moveUp`, `moveDown` |
| `StepMapper` | hodnoty formuláře ↔ `Step` pro všechny čtyři typy |
| `WorkflowStore` | `path()` a `save()`. Čtení už umí `WorkflowRepository`, zápis dělá `WorkflowWriter`. |

`WorkflowStore` je záměrně menší než `BlockStore`: `delete()` a zakládání
patří do projektu 2, a čtecí metody by jen přeposílaly na `WorkflowRepository`,
který prezentér už používá. Vzniká proto, že skládat cestu
`<projekt>/workflows/<jméno>.json` má jedno místo — `WorkflowWriter` ji
vědomě neodvozuje, jen ověřuje.

**`StepTree` je těžiště projektu.** `Workflow` i všechny třídy kroků jsou
`readonly`, takže každá operace strom přestaví a vrátí **nový** `Workflow`.
To z ní dělá čistou funkci a nejlépe testovatelnou věc v projektu.

### Proč `StepPath` rozšířit, a ne zavést druhou adresu

Nabízelo by se posílat v URL něco jednoduššího, třeba `0.then.1`. Ale tvar
`steps[2].then[0]` už je smlouva s donutem: skládá ho `Validator`, je připnutý
testem `tests/Donut/Validator.location.phpt` a `ProblemMap` podle něj indexuje.
Druhá adresa pro tutéž věc by byla dvojí výklad jednoho pravidla — přesně to,
čemu se projekt opakovaně vyhýbá (serializér nedostal odvozování cesty ze
jména, tok klíčů se nepočítá v GUI podruhé).

## Editační plocha

Přehled workflow dostane u každého kroku `upravit`, `↑`, `↓`, `×`, a na konci
každého seznamu sourozenců `+ krok` — **včetně seznamů uvnitř `then`, `else`
a `foreach`**, kam se dnes nedá přidat nic.

### Ovládání jsou ručně psané formuláře, ne komponenty Nette Forms

Komponenta formuláře vykreslená uvnitř `n:foreach` znamená jednu komponentu
mnohokrát, což Nette neumí — narazilo se na to u mazání kamene a řešilo se
přesunem mazání na stránku, kde je jeden objekt. Tady jsou čtyři ovládací
prvky na krok a až 37 kroků, takže se tomu tak vyhnout nedá.

Ovládání jsou proto `<form method=post>` mířící na signál, s cestou ke kroku
ve skrytém poli. **Žádný GET nic nemění** — totéž pravidlo jako u mazání
kamene, ze stejného důvodu (přednačítače v prohlížeči).

### Operace

- `↑` / `↓` prohodí krok se sousedem v témže seznamu. Na kraji seznamu se
  šipka nevykreslí; z větve `then` se ven nedostaneš.
- `×` smaže. U `if` nebo `foreach` s neprázdnými větvemi se nejdřív zeptá,
  protože s krokem mizí celý podstrom a z přehledu není vidět kolik.
- `+ krok` nabídne čtyři typy jako čtyři odkazy. Odkaz vede na stránku kroku
  v režimu „nový", s typem a cílovou cestou v adrese; uložení krok vloží.
  Nic se neukládá, dokud formulář neprojde — nevznikají prázdné kroky, které
  by pak bylo nutné uklízet. `if` vznikne s prázdnými větvemi, `foreach`
  s prázdným tělem; naplní se pak jejich vlastním `+ krok`.

`↑`, `↓` a `×` uloží rovnou a vrátí na přehled, kde jsou vidět důsledky
včetně problémů u dotčených kroků.

**Adresování a vkládání.** Cesta v `StepTree` pojmenovává **pozici**, ne jen
existující krok: `insert()` vloží nový krok na dané místo v seznamu a ostatní
posune. Díky tomu `remove` a `insert` zpátky na tutéž cestu vrátí původní
strom, což je jeden z testovaných invariantů. `+ krok` na konci seznamu je
prostě vložení na pozici za posledním prvkem.

**Přesouvat krok do jiné větve GUI neumí.** Vědomě: `↑`/`↓` mezi sourozenci
pokrývá běžnou práci a přesun mezi větvemi by znamenal druhý režim přehledu
a víc stavů k otestování. Kdo potřebuje přesunout krok do `if`, smaže ho
a napíše znovu.

## Stránka kroku

Odkaz `upravit` vede na `Workflow:step` s cestou v adrese. Jedna stránka,
jeden formulář — stejně jako u kamene.

| typ | pole |
|---|---|
| `run` | kámen z rozbalovacího seznamu, `in` jako *vstup → šablona*, `out` jako *kanál → klíč*, `timeout`, `allow_failure` |
| `set` | klíč, hodnota |
| `if` | levá strana, operátor z rozbalovacího seznamu, pravá strana |
| `foreach` | přes co, pod jakým jménem |

`name` má každý typ.

Jména kamenů do rozbalovacího seznamu zná `BlockRepository`. Operátory zná
`Condition::Operators`; u unárních (`Condition::UnaryOperators` — `empty`,
`not_empty`) se pravá strana ignoruje.

U `run` platí asymetrie ze serializéru: `allow_failure` `null` znamená
nenastaveno, `false` vědomé vypnutí.

**Větve se na téhle stránce needitují** — `then`, `else` a `foreach.steps` se
plní z přehledu.

### in a out jsou opakující se řádky

Tentýž vzorek jako `args` u kamene: **JS řádky nikdy nepřečísluje**, přidání
bere index o jedna vyšší než maximum, smazání nechá díru a server ji srovná.
Ten kód i jeho past (klonování skupiny s víc vstupy) jsou vyzkoušené.

## Validace neblokuje

**Každá změna se uloží a problémy se ukážou** u dotčených kroků na přehledu.

Je to **vědomý rozdíl oproti kameni**, kde chyba uložení blokuje. Kámen je
malý a soudržný — dá se dopsat do platného stavu na jedno posezení. Workflow
o 37 krocích se přerovnává po jednom kroku a mezistavy jsou skoro vždycky
neplatné; přesunout krok často vyžaduje dva tahy po sobě. Blokovat mezistavy
by znamenalo, že se nepřesuneš nikam.

Riziko, že na disku leží workflow, které neprojde validací, nese GUI vědomě —
právě proto, že problémy jsou hned vidět, což byla bolest č. 1, kvůli které
GUI vzniklo.

## Testy

Zátěž je bohatá zadarmo: **96 kroků** ve čtyřech workflow — 75 `run`,
9 `set`, 5 `if`, 7 `foreach`, do hloubky 3.

### StepTree se testuje invarianty, ne výčtem případů

Pro každou z 96 cest ve všech čtyřech workflow:

- `replace(w, path, get(w, path)) == w` — dokazuje, že `get` a `replace` míří
  na tentýž uzel. Kdyby se rozešly o jeden index nebo si spletly větev,
  chytne to všude naráz.
- `moveDown` a hned `moveUp` vrátí původní workflow; totéž opačně.
- `remove` a `insert` zpátky na totéž místo vrátí původní workflow.

Porovnává se přes `serialize()` — typově přesné a odolné vůči hloubce, jak se
ukázalo u round-tripu serializéru.

### StepMapper se testuje round-tripem

`Step → hodnoty → Step` přes všech 96 kroků. Kdyby mapper zahodil `timeout`,
`allow_failure` nebo `name`, tohle to odhalí.

Doplněné o ruční případy na tvary, které zátěž nemá: `if` bez `right`,
prázdné `out`, děravé indexy v `in` a `out`.

### StepPath::parse

Pro každou cestu ze zátěže musí `parse()` a zpátky dát tentýž řetězec.

### Prezentér

Přes sdílenou továrnu `gui/tests/inc/blockPresenter.php` z editace kamene —
prezentér se sestaví v procesu, žádný HTTP server. Pro `WorkflowPresenter`
vznikne její obdoba.

### Sdílený JS

Ten JS bude potřetí: `args` u kamene, teď `in` a `out` u kroku `run`.
Kopírovat ho do druhé šablony je duplikace. Externí `.js` se nedoručí
(ověřeno u editace kamene — GUI běží z adresáře projektu, takže vestavěný
server hledá statické soubory tam, ne v `gui/www`).

Řešení je Latte: `{define}` v samostatném souboru a `{include}` v obou
šablonách, přesně jak to dělá `steps.latte`. **Součástí projektu je vytáhnout
ten JS z `edit.latte` do sdíleného bloku.**

### Co testy nepokryjí

**Ten JS.** Stejná vědomá díra jako u kamene, se stejným zmírněním: klonuje
řádky a přiděluje indexy, žádné pravidlo formátu nezná, a všechno podstatné
srovnává server. Když se pokazí, formulář vypadá špatně okamžitě — nemůže
vyrobit tiše špatný soubor.

## Co se vědomě nedělá

- **Přesun kroku mezi větvemi.** Viz „Operace".
- **Editace větví na stránce kroku.** `then`, `else` a `foreach.steps` se
  plní z přehledu, kde je vidět strom.
- **Založení a smazání workflow, hlavička a vstupy.** To je projekt 2.
- **Detekce souběhu.** Převzato ze serializéru: soubor upravený v editoru
  mezi vykreslením a uložením se přepíše bez varování.
- **CSRF ochrana a session.** Převzato z editace kamene: `Form::addProtection()`
  potřebuje session, kterou GUI nemá. Důsledek „`127.0.0.1`, žádná
  autentizace" z návrhu GUI. Ručně psané formuláře (`steps.latte` — přesun,
  mazání) přesto nejsou bez ochrany úplně: Nette `AccessPolicy::applyInternalRules()`
  připojí `Requires(sameOrigin: true)` ke každé `handle*` metodě automaticky,
  pokud si signál sám nevyžádá jinak — Fetch Metadata (`Sec-Fetch-Site`)
  kontrola tedy platí i tady, bez session. Nedoplňuj proto `Form::addProtection()`
  ani session — už jsou pokryté.
- **Prohlížečové testy.** Viz výše.
