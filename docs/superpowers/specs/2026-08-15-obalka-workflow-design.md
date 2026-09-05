# Obálka workflow — návrh

Datum: 2026-08-15

## Cíl

Založit a smazat workflow a upravit jeho jméno, popis a vstupy.

Je to druhý ze dvou projektů, na které se rozpadla editace workflow, a
**poslední projekt vrstvy 3 GUI**. Až bude hotový, je hotový celý krok 5
zadání.

Referenční pravda formátu je `docs/format-specifikace.md` verze 0.3.
První projekt popisuje `2026-08-14-editace-workflow-kroky-design.md`.

## Dvě části

Projekt má dvě poloviny a je poctivé je pojmenovat zvlášť:

1. **Úklid**, který si vyžádala závěrečná revize předchozího projektu:
   `WorkflowPresenter` má 449 řádků a je největší soubor v GUI.
2. **Obálka** sama — zakládání, mazání, formulář hlavičky.

Úklid jde první, protože obálka staví na tom, co z něj vypadne.

## Část 1 — co se vytáhne z prezentéru

Tři přesuny a jedno přestěhování. **Žádná změna chování.**

| co | kam | proč |
|---|---|---|
| tři signály (`moveUp`, `moveDown`, `deleteStep`), `applyToStep()`, stav `$stepError` | nový `StepTreeControl` | má už dnes vlastní šablonu i vlastní stav; jako komponenta si je odnese s sebou |
| `toInputs()` a převod vstupů do hodnot | nový `InputMapper` | vstupy kamene a workflow mají tentýž tvar; dvě implementace jednoho pravidla projekt odmítá |
| `rowShape()`, `rowIndexes()` | nový `RowShape` | potřebuje je formulář kroku i nový formulář hlavičky |
| `keepChildren()` | `StepMapper` | je to znalost formátu, ne stromu |

### Proč Control zrovna u stromu kroků

`Nette\Application\UI\Form` **už Control je**. Zabalit formulář do dalšího
Controlu přidává vrstvu, aniž by nějakou ubralo — na formulářových továrnách
není dlouhý formulář, ale stavění opakujících se kontejnerů, a to chce
obyčejnou továrnu (`RowShape`), ne komponentu.

Strom kroků je jiný případ: má vlastní šablonu (`steps.latte`), vlastní tři
signály, vlastní stav a vykresluje se z jediného místa. Jako `StepTreeControl`
je to soudržný celek a rekurze zůstane v šabloně beze změny.

### Cena přesunu

Signály změní adresu z `?do=moveUp` na `?do=stepTree-moveUp`. Adresy skládá
`{link}`, takže navenek se nic nerozbije, ale **`WorkflowPresenter.controls.phpt`
se bude muset upravit** — v řetězcích, ne v tvrzeních.

`BlockMapper.phpt`, `BlockPresenter.edit.phpt` a `WorkflowPresenter.step.phpt`
musí projít **beze změny**. To je důkaz, že extrakce nic nepřenesla.

Úklid sundá z prezentéru zhruba 160 řádků (ze 449 na ~290). Obálka pak přidá
odhadem 130, takže skončí kolem 420 — pořád velký, ale o třetinu lehčí, než
kdyby se úklid neudělal, a bez znalosti formátu.

## Část 2 — obálka

### Akce `Workflow:edit`

Volitelný parametr `name`: prázdný znamená zakládání, vyplněný úpravu. Přesný
opis `Block:edit`, včetně dvou lekcí, které tam stály Critical a Important:

- **Zakládání nesmí přepsat existující workflow.** `WorkflowWriter` přepisuje
  bez ptaní, takže před uložením musí stát kontrola `exists()`. Bez ní vypadá
  zničení souboru jako úspěch — přesměrování k nerozeznání od úspěšného.
- **Jméno je editovatelné jen při zakládání.** Při úpravě je pole vyplněné
  z načteného workflow, zakázané přes `setDisabled()` a `setOmitted(false)`,
  aby se hodnota dostala do `getValues()`. Pořadí volání je závazné:
  `setDisabled()` maže hodnotu, takže musí předcházet `setDefaultValue()`.

Pole formuláře: jméno, popis, vstupy jako opakující se řádky
(`RowShape` + `InputMapper`, tentýž mechanismus a tentýž JS jako všude jinde).

Převod mezi hodnotami formuláře a objektem dělá nový **`WorkflowMapper`** —
obdoba `BlockMapperu` a `StepMapperu`, jen o poznání menší, protože hlavička
má tři pole. Vstupy deleguje na `InputMapper`. **Kroky nepřevádí ani jedním
směrem**: formulář je needituje a `toWorkflow()` je bere z původního workflow,
aby úprava hlavičky nesmazala celý strom — přesně ta past, kterou u kroku řeší
`keepChildren()`.

Do seznamu workflow přibude odkaz **„+ nové workflow"**, obdoba toho, co má
přehled kamenů.

**Přejmenování GUI neumí.** Znamenalo by přesun souboru a workflow se spouští
jménem z cronu a z CLI, což GUI nevidí.

### Mazání

**Na stránce hlavičky, ne v seznamu** — v seznamu by komponenta formuláře
byla uvnitř `n:foreach`, což Nette neumí. Potvrzení, POST, žádný GET.

**Odkazy se nekontrolují**, na rozdíl od mazání kamene: uvnitř formátu na
workflow neodkazuje nic (volání workflow jako kroku je ve „vědomě odloženo"
v zadání).

Je to ale nebezpečnější v jiném směru: workflow se spouští z cronu a z CLI,
což GUI nevidí a ověřit nemůže. **Potvrzení je jediná pojistka** a jeho text
to má říct nahlas.

### Nové workflow vzniká prázdné

Bez kroků. Kroky se do něj přidají z detailu, který to od minulého projektu
umí.

### Validace neblokuje ani tady

Je to třetí odpověď na tutéž otázku v jednom GUI, takže ať je vidět proč:
u kamene chyba blokuje, u workflow ne, a hlavička je součástí workflow.
Kdyby se týž soubor dal uložit z jedné stránky a z druhé ne, bylo by to
nevysvětlitelné. **Jedno pravidlo na objekt.**

### WorkflowStore

Dostane `exists()` a `delete()` — to, co se do něj v minulém projektu vědomě
nedalo, protože to patřilo sem.

## Testy

### Extrakce se dokazuje tím, co se nezmění

`BlockMapper.phpt`, `BlockPresenter.edit.phpt` a `WorkflowPresenter.step.phpt`
musí projít beze změny. Jediný test, který se smí upravit, je
`WorkflowPresenter.controls.phpt`, a jen v řetězcích signálů.

### InputMapper

Round-trip nad **všemi 57 vstupy referenční zátěže** — 36 u kamenů, 21
u workflow — `Input → hodnoty → Input`, porovnání přes `serialize()`.

Je to bohatší zátěž, než jakou mělo `toInputs()` uvnitř `BlockMapperu`
k dispozici, protože teď zahrnuje i vstupy workflow.

### RowShape a WorkflowMapper

Vlastní testy: díry v indexech, prázdné řádky, `''` jako nevyplněno.

`WorkflowMapper` round-trip nad čtyřmi skutečnými workflow, porovnaný na
hlavičce a vstupech — kroky formulář needituje, takže se z porovnání vynechají
stejně, jako to u `StepMapperu` dělá pomocná `$bare()`.

### keepChildren dostane přímý test

Dnes jde vyzkoušet jen přes HTTP-tvar testu, protože leží v prezentéru. Po
přesunu do `StepMapperu` je to čistá funkce. Závěrečná revize předchozího
projektu na to výslovně ukázala.

### Dvě lekce z editace kamene dostanou vlastní testy

Ne jen kód:

- uložení přes existující jméno nechá původní soubor bajt po bajtu netknutý
  a **nepřesměruje**;
- odeslání změněného jména při úpravě zapíše původní jméno a nevyrobí druhý
  soubor.

### Prohlížeč

Ověří, co testy neumí: že se nové workflow založí, dá se do něj z detailu
přidat krok, a že smazané zmizí ze seznamu.

### Co testy nepokryjí

**Ten JS**, potřetí a naposledy, se stejným zmírněním: klonuje řádky
a přiděluje indexy, žádné pravidlo formátu nezná, a všechno podstatné srovnává
server. Když se pokazí, formulář vypadá špatně okamžitě — nemůže vyrobit tiše
špatný soubor.

## Co se vědomě nedělá

- **Přejmenování workflow.** Viz výše.
- **Kontrola odkazů při mazání.** Uvnitř formátu není co kontrolovat; vně
  formátu (cron, CLI) to GUI nevidí.
- **Detekce souběhu.** Převzato ze serializéru: soubor upravený v editoru
  mezi vykreslením a uložením se přepíše bez varování.
- **CSRF ochrana a session.** Převzato z předchozích projektů. Nette navíc
  připojuje `Requires(sameOrigin: true)` ke každé metodě `handle*`, takže
  signály jsou kryté kontrolou Fetch-Metadata i bez session.
- **Rozdělení prezentéru na dva.** V Nette určuje prezentér adresu; rozdělení
  by změnilo URL. Vytažení logiky stačí.
- **Prohlížečové testy.**
