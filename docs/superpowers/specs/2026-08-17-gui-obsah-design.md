# Obsah stránek GUI — návrh

Chybové hlášky jako alerty, strom kroků jako vnořené list-group, sekce
formulářů jako karty.

## Cíl

Projekt vzhledu dal GUI dvousloupcový rám, bootstrapí formuláře a tabulky
s hlavičkou; projekt seznamů udělal z obou přehledů tabulky a dal kamenům
vlastní detail. Uvnitř stránek ale pořád zůstávají tři věci, které vypadají
jako holé HTML uprostřed jinak nastylované aplikace:

- chybové hlášky jsou odstavce a seznamy s vlastní třídou `error`
- strom kroků je číslovaný seznam
- formuláře jsou dlouhé pásy políček bez viditelných hranic sekcí

Tenhle projekt je dorovnává. Je poslední z rozdělení, které zavedl návrh
vzhledu jako „projekt B", a po něm je vrstva 3 vzhledově hotová.

## Rozsah: jeden projekt, tři tasky

Předchozí návrh počítal s tím, že se „obsah stránek" možná bude muset dělit
dál. Nemusí: vychází to na tři tasky, tedy míň než projekt vzhledu, který
jich měl šest. Alerty, strom a karty se navíc dotýkají různých částí šablon,
takže se v jednom diffu nepromíchají.

Pořadí je **alerty → strom → karty**. Strom je nejrizikovější a bude se
lépe psát na stránce, kde už mají hlášky konečnou podobu.

## Tři role chybových hlášek

Klíčové zjištění celého návrhu: `class=error` má dnes tři různé role a jen
dvě z nich jsou alert.

| role | kde | kolikrát | co s tím |
|---|---|---|---|
| stránková hláška | `<p n:if="$error" class=error>` | 8× | `alert alert-danger` |
| seznam chyb formuláře | `<ul n:if="$form->getErrors()" class=error>`, plus `$errors`/`$warnings` v `Block/edit.latte` | 6× | `alert alert-danger` se seznamem uvnitř |
| vložená chyba | `<span class=error>` v buňce tabulky, `<span class=warning>` u vybraného klíče | 3× | `text-danger` / `text-warning`, **ne** alert |

Ta třetí role je důvod, proč to nejde převést plošně: alert box uvnitř
tabulkové buňky nebo uprostřed věty by byl nesmysl.

Značky problémů u kroku (`✕` chyba, `⚠` varování z `{define problem}`)
patří do třetí role — jsou to poznámky uvnitř řádku, ne stránkové hlášky.
Alert uvnitř položky seznamu by řádek kroku rozbil.

## Strom kroků

Rekurzivní `{define steps}` přejde z `<ol>`/`<li>` na vnořené list-group.
Jeden detail rozhoduje o všem ostatním.

### Podbarvení patří na řádek, ne na položku

Dnešní `donut.css` má u toho komentář a ten vysvětluje proč: `<li>` obaluje
i vnořené `<ol>` s tělem `then`/`else`/`foreach`, takže pozadí by obarvilo
celý podstrom místo jednoho kroku. S list-group platí totéž —
`.list-group-item` bude obsahovat i vnořený `.list-group`. Struktura proto
zůstane dvouúrovňová:

```
<div class="list-group">
  <div class="list-group-item">
    <div class="step write read">   ← sem patří podbarvení
      run olw-agent   [upravit][↑][↓][×]
      čte shortId, zapisuje card
    </div>
    <div class="list-group">        ← tělo then/else/foreach
      …
    </div>
  </div>
</div>
```

Selektory v `donut.css` se mění z `li > div.write` na `.step.write`; ten
komentář se přepíše, ať dál vysvětluje totéž ve správných pojmech. Hodnoty
odstínů zůstávají (`#eafbea` zápis, `#eef3fb` čtení, `#f4f0e6` obojí)
a `KeyMap::classAt()` se nedotýká vůbec — vrací dál `write`, `read` nebo
`write read`.

### Zbytek stromu

- **Větve `then` a `else`** dostanou místo dnešního holého textu malý nadpis
  nad vnořeným seznamem, aby bylo poznat, kde která začíná.
- **Ovládání kroku** (`upravit`, ↑, ↓, ×) se seskupí do `btn-group` malých
  tlačítek. Tři ze čtyř jsou ruční `<form method=post>` a **musí jimi
  zůstat** — Nette komponentu nejde vykreslit uvnitř `n:foreach`, což je
  omezení, na které tenhle projekt narazil už dvakrát. Změní se jen
  `style="display:inline"` za bootstrapí třídu.

## Karty

Každá sekce formuláře, kterou dnes uvozuje `<h2>`, se zabalí do karty
s nadpisem v `card-header`:

| stránka | sekce |
|---|---|
| `Workflow:edit` | Vstupy |
| `Workflow:step` | Vstupy kamene, Výstupy do mapy, Ostatní |
| `Block:edit` | Argumenty, Vstupy, Stdin, Ostatní |

Políčka bez nadpisu — jméno, popis, příkaz — dostanou první kartu bez
hlavičky. Formulář zůstává jeden, karty jsou uvnitř něj.

**Sekce mazání** dostane kartu s `border-danger` a červeným nadpisem.
U kamene se do ní vejde i dnešní věta „Nejde smazat — používá ho: …",
která se ukazuje místo tlačítka. Tlačítko `btn-danger` už má z projektu
vzhledu; tohle je jeho dotažení — hranice nevratné sekce má být vidět
dřív, než do ní uživatel klikne.

## Testy

### Co se rozbije

Aserce na `class=error` a `class=warning` je dnes **třináct** v sedmi
souborech. Mění se ty, které míří na stránkovou hlášku nebo na seznam chyb
formuláře; ty, které míří na vloženou roli, zůstávají nedotčené, protože ta
role alertem nebude.

Přesný výčet patří do plánu — v návrhu by to bylo tvrzení, které nikdo
neověří, a starší odhad („devět asercí") se při přeměření ukázal jako
podhodnocený.

Co platí závazně:

- **Žádná stávající aserce se nesmí oslabit.** `contains('class=error')` se
  nahradí `contains('alert-danger')` — stejně silné tvrzení o novém
  značkování.
- **Hláška si vedle `alert alert-danger` nesmí nést mrtvou třídu `error`**
  jen proto, aby staré aserce prošly. To by nebyl převod, ale obcházení
  testů.
- Každá změněná aserce se v hlášení vyjmenuje i s tím, co tvrdila dřív.

### Nové testy

Projekt je převod značkování, ne nová funkce, takže nových asercí bude málo:

- sekce mazání má `border-danger`
- strom kroků drží podbarvení na řádku, ne na položce seznamu

To druhé je jediná vlastnost celého projektu, kterou lze rozbít tiše:
kdyby třída sedla na `.list-group-item`, podbarvení by přeteklo na celý
podstrom a nikdo by si toho nemusel všimnout.

### Prohlížeč

Ruční kontrola nad **kopií** dat v `/tmp`, nikdy nad `docs/workflows/`,
v širokém i úzkém okně. Musí zahrnout **workflow s vnořeným `if` uvnitř
`foreach`** — tam se pozná, jestli se vnořené list-group nesloučily
a jestli podbarvení nepřeteklo na podstrom.

## Co se vědomě nedělá

- **změna `KeyMap::classAt()`** — vrací dál `write`/`read`/`write read`
- **bootstrapí kontextové varianty** (`list-group-item-success` a spol.)
  místo vlastních odstínů — nemají variantu pro stav „čte i zapisuje"
  a jsou výraznější, takže by vnořený strom zbarvily víc než dnes
- **tmavý režim** — nikdo si o něj neřekl
- **přesun ovládání kroku na Nette komponentu** — nejde uvnitř `n:foreach`
- **řazení a filtrování tabulek** — patřilo by k seznamům, ne sem
