# Seznamy a detail kamene — návrh

Přehled workflow a přehled kamenů jako tabulky s akcemi, nová stránka
`Block:detail`.

## Cíl

Předchozí projekt dal GUI dvousloupcový rám, bootstrapí formuláře
a tabulky s hlavičkou u opakujících se řádků. Vnitřek stránek zůstal
nedotčený a nejvíc je to vidět na dvou přehledech:

- **Workflow** je `<ul>` se jménem, popisem a dvěma odkazy na řádku.
- **Kameny** není seznam vůbec — je to úplný výpis *každého* kamene na
  jedné stránce: popis, příkaz, stdin, všechny vstupy, timeout,
  allow_failure. Při dvaceti kamenech je to dlouhé rolování, ve kterém
  se nedá nic najít.

Cílem je udělat z obou přehledy, ve kterých se dá hledat, a dát kamenům
stejný tvar, jaký má workflow: přehled → detail → editace.

## Rozdělení

Původní návrh vzhledu (`2026-08-16-gui-vzhled-design.md`) počítal
s jedním projektem B „obsah stránek". Ukázalo se, že je na jeden plán
příliš velký, a hlavně že by v jednom diffu mísil práci, která nerozbije
nic, s prací, která přepisuje devět asercí. Dělí se proto na dva; tenhle
návrh popisuje první.

**B1 — seznamy a detail kamene** (tento návrh). Obě tabulky, nová
stránka `Block:detail`. Mazání zůstává na `Block:edit`, kontrola
`$usedBy` se nemění, chybové hlášky zůstávají jak jsou.

**B2 — strom, hlášky a karty** (samostatný návrh, později). Strom kroků
jako vnořené list-group, chybové hlášky jako alerty, sekce formulářů
jako karty. Sem spadne celý zásah do devíti asercí, které se ptají na
`class=error`, i přesun podbarvení toku klíčů z `li > div.write` na
položku seznamu.

Dělící čára vede tudy schválně: B1 se skoro nedotkne toho, co je dnes
otestované na struktuře HTML, kdežto B2 dělá skoro jen to. Kdyby se to
dělalo naráz, nešlo by u spadlého testu poznat, jestli za to může nová
tabulka, nebo nový alert.

## Přehled workflow

Sloupce **Jméno · Popis · akce**.

```
┌──────────────┬────────────────────────────────┬─────────┐
│ Jméno        │ Popis                          │         │
├──────────────┼────────────────────────────────┼─────────┤
│ card-dev     │ Zpracuje kartu agentem…        │ upravit │
│ rozbite      │ ✕ Soubor není platný JSON      │ upravit │
│ sync         │                                │ upravit │
└──────────────┴────────────────────────────────┴─────────┘
```

Jméno je odkaz na detail. U nenaparsovatelného workflow odkaz na detail
nedává smysl — zůstane text a chybová hláška — ale **odkaz „upravit"
dostane i rozbitý řádek**. Je to jediná cesta k jeho opravě a smazání
a je to vlastnost, kterou předchozí projekt musel doplňovat jako
Important nález; komentář, který ji v šabloně vysvětluje, se přenese
do tabulky.

## Přehled kamenů

Sloupce **Jméno · Příkaz · Používá · akce**. Jméno vede na nový detail,
„upravit" na editaci. Sloupec „Používá" vypíše workflow, která kámen
volají — dnes je ta informace schovaná v odstavci pod nadpisem kamene.

Rozbitý kámen se chová jako rozbité workflow: text místo odkazu, chybová
hláška, a odkaz „upravit" zůstává.

Prázdný stav i chybová hláška o chybějícím adresáři zůstávají jako
dnešní odstavce — alerty jsou až B2.

## Stránka `Block:detail`

Nová akce v `BlockPresenter`, nová `BlockDetailTemplate`, nová
`Presentation/Block/detail.latte`, drobečky `Kameny / <jméno>`.

Ukáže to, co dnes vysypává přehled — popis, příkaz, vstupy s příznakem
povinnosti a výchozí hodnotou, stdin, timeout, allow_failure a seznam
workflow, která kámen používají — plus odkaz na editaci.

**Navíc vypíše argumenty.** Dnešní přehled je nevypisuje vůbec, což je
u kamene, jehož celý smysl je „jeden příkaz s argumenty", zvláštní:
detail bez nich by zamlčoval to hlavní. Je to přírůstek nad rámec
„přestěhovat výpis" a je vědomý.

**Rozbitý kámen musí jít otevřít.** Když se soubor nenaparsuje, detail
ukáže chybu a odkaz na editaci — stejně jako to od minulého projektu
dělá rozbité workflow. Bez toho by nová stránka zopakovala přesně tu
vadu, kterou u workflow našla závěrečná revize.

`BlockUsage::of()` už `BlockPresenter` volá na dvou místech
(`renderDefault()` a cesta k editaci); detail použije totéž.

### Drobečky se tím posunou i na editaci

Workflow má dnes `Workflow / card-dev` na detailu a
`Workflow / card-dev / hlavička` na editaci, kde prostřední článek
odkazuje na detail. Kámen má zatím jen `Kameny / jq`, protože detail
neexistoval.

Jakmile vznikne, dostane `Kameny / jq` detail a **editace se změní na
`Kameny / jq / úprava`**, kde `jq` odkazuje na nový detail. Bez toho by
byly dvě různé stránky pod týmž drobečkem a z editace by nevedla cesta
na detail.

Je to změna existujícího drobečku, takže se dotkne aserce
v `Layout.phpt`, která dnes u `Block:edit` očekává jméno kamene jako
poslední aktivní položku.

## Mazání zůstává na editaci

Workflow se maže na `Workflow:edit`, tedy na stránce obálky. Kámen se
proto dál maže na `Block:edit` a detail je jen ke čtení.

Důvod není jen symetrie: mazání kamene chrání kontrola `$usedBy`, a dvě
cesty k téže nevratné operaci znamenají dvě místa, kde ta kontrola může
chybět. Přesně taková asymetrie mezi kamenem a workflow už v tomhle GUI
dvakrát způsobila ztrátu dat.

## Testy

### Co se rozbije

Změřeno: **tři aserce**.

| soubor | aserce | co se stane |
|---|---|---|
| `BlockPresenter.delete.phpt:32` | `contains('používá')` na přehledu | spadne — malé „p" zmizí |
| `BlockPresenter.default.phpt:25` | `notContains('používá')` na přehledu | projde dál, ale **stane se vakuovou** |
| `Layout.phpt:96` | `k` jako poslední aktivní drobeček u `Block:edit` | jméno se stane odkazem, poslední bude `úprava` |

První dvě visí na českém slově „používá", které dnes stojí ve větě pod
nadpisem kamene (`Block/default.latte:23`) a v tabulce se z něj stane
hlavička sloupce „Používá". Přepíšou se na něco silnějšího než shoda
českého slova: na **obsah buňky ve sloupci Používá**. Třetí je důsledek
posunu drobečků a přepíše se na nový tvar.

Dvě aserce, které by se rozbít mohly zdát, se **nerozbijí** — obě jsou
na stránce editace, ne na přehledu:

- `BlockPresenter.delete.phpt:46` (`contains('používá')`) míří na větu
  „Nejde smazat — používá ho: w." z `Block/edit.latte:134`
- `BlockPresenter.delete.phpt:89` (`contains('class=error')`) míří na
  chybu rozbitého kamene při editaci

### Vakuová aserce, která tu je už dnes

`BlockPresenter.delete.phpt:33` je `Assert::contains('w', $html)` — jméno
testovacího workflow je jediné písmeno a to je v HTML všude (`workflow`,
`www`, `switch`). Aserce tedy neříká nic. Při přepisu se zpřesní na
obsah buňky, takže projekt tuhle díru zavře mimochodem.

Platí pravidlo z předchozích projektů: **žádná stávající aserce se nesmí
oslabit**, a každá změněná se v hlášení vyjmenuje i s tím, co tvrdila
dřív.

### Nové testy

- `BlockPresenter.detail.phpt` — příkaz, argumenty, vstupy s povinností
  a výchozí hodnotou, stdin, timeout, allow_failure, seznam workflow,
  odkaz na editaci; a zvlášť rozbitý kámen, který musí jít otevřít
- rozšíření `Layout.phpt` o drobeček `Kameny / <jméno>` — jinak by nová
  stránka byla jedinou bez pokrytí drobečků, což si závěrečná revize
  předchozího projektu vyžádala
- aserce na obě tabulky: hlavičky sloupců, jméno jako odkaz na detail,
  a hlavně že **rozbitý řádek si odkaz „upravit" udrží**

Mutačně se ověří hlavně poslední bod a nová stránka.

### Prohlížeč

Ruční kontrola nad **kopií** dat v `/tmp`, nikdy nad `docs/workflows/`,
v širokém i úzkém okně. Tabulky se do 390 px nevejdou, takže musí být
v `.table-responsive` jako ty ve formulářích.

## Co se vědomě nedělá

- **chybové hlášky jako alerty** — projekt B2
- **strom kroků** — projekt B2
- **sekce formulářů jako karty** — projekt B2
- **mazání kamene z detailu** — zůstává na editaci, viz výše
- **stránka `Workflow:detail`** — už existuje a nemění se
- **řazení a filtrování tabulek** — nikdo si o ně neřekl
