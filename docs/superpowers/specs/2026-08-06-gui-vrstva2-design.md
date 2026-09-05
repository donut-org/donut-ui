# GUI vrstva 2 — tok klíčů — návrh

Datum: 2026-08-06

## Cíl

Odpovědět při psaní na otázku „kde tenhle klíč vzniká a kdo ho ještě čte".

Je to druhá ze tří vrstev GUI podle
`docs/superpowers/specs/2026-08-05-gui-design.md`. Vrstva 1 (validace u kroku
a přehled kamenů) je hotová; vrstva 3 (builder) přijde později.

## Oprava předchozího návrhu

Návrh vrstev tvrdí, že vrstva 2 „vyžaduje evidenci cest ve `Validator`u —
mění donut, ne jen GUI". **To bylo napsané špatně** a tenhle dokument to ruší.

Vycházelo to z toho, že `KeyFlow` eviduje jen *které* klíče se zapsaly
a přečetly, ne *kde*. Jenže GUI `KeyFlow` nepotřebuje. Co který krok čte
a zapisuje, je odvoditelné přímo z naparsovaného stromu — `Template::getKeys()`
je veřejná:

| krok | čte | zapisuje |
|---|---|---|
| `run` | klíče v šablonách `in` | hodnoty `out` |
| `set` | klíče ve `value` | `key` |
| `if` | klíče v `condition.left` a `right` | — |
| `foreach` | klíče v `over` | `as` |

**Podmíněnost zápisu taky nepotřebuje analýzu toku.** Cesta obsahující
`.then`, `.else` nebo `.steps` je uvnitř větve z definice. Správnostní soud
navíc dělá validátor a vrstva 1 ho už zobrazuje u čtoucího kroku.

Vrstva 2 je tedy **čistě prezentační**. Do donutu sahá jen kvůli testu, viz
níže.

## Rozsah dat

Referenční `card-dev` má **29 klíčů** na 29 krocích. Není to spleť: většina
klíčů se zapíše jednou a přečte jednou až dvakrát. Výjimky jsou `task`
(2 zápisy, 7 čtení), `curlrc` (5 čtení) a osm vstupů, které nikdo nezapisuje.

Z toho plyne tvar funkce: **odpovědi při psaní, ne mapa**. Graf 29 uzlů by
byl nečitelný, tabulka 29 řádků by odpovídala na otázku, kterou autor zrovna
nemá.

## Co se mění v donutu

`Donut\Validator\Result` dostane `getReadKeys()` a `getWrittenKeys()`.
`Validator::validate()` ty množiny na konci už má (`$flow->getRead()`,
`$flow->getWritten()`) — dnes je zahodí.

**Je to jen kvůli spojovacímu testu**, ne kvůli funkci samotné. GUI je
k vykreslení nepotřebuje.

`Result` je dnes sběrač problémů a přibývá mu druhá role. Je to únosné —
klíčové množiny jsou součástí toho, co validace spočítala, a alternativa
(druhá návratová hodnota z `validate()`) by změnila signaturu, na které stojí
`Runner` i CLI.

Nic jiného se v donutu nemění.

## Co vzniká v `gui/`

Nová jednotka `Donut\Gui\KeyMap` vedle `StepPath` a `ProblemMap`. Projde strom
workflow jednou a odpoví na čtyři otázky:

| | |
|---|---|
| `writesAt($path)` | které klíče zapisuje tenhle krok |
| `readsAt($path)` | které klíče čte |
| `writeSitesOf($key)` | kde všude klíč vzniká |
| `readSitesOf($key)` | kde všude se čte |

Cesty jsou `StepPath` ve stejném tvaru, jaký používá `ProblemMap` — tedy tvar,
který skládá `Validator::checkSteps()` a který připíná
`tests/Donut/Validator.location.phpt`.

Šablona kroků u každého kroku vypíše, co čte a co zapisuje, jako odkazy.

## Zvýraznění bez JavaScriptu

Kliknutí na klíč vede na tutéž stránku s `key` v adrese
(`?presenter=Workflow&action=detail&name=card-dev&key=repo`). Presenter jméno
klíče předá šabloně a kroky, kde ten klíč figuruje, dostanou třídu — **zápis
jinak než čtení**, ať je vidět směr.

Proč tak:

- Sedí to na architekturu vrstvy 1 — nic se necachuje, všechno se renderuje
  při requestu.
- Je to **odkazovatelné**: na konkrétní klíč jde poslat odkaz.
- Jde to testovat bez prohlížeče.

Cena je překreslení stránky při každém kliknutí. Kdyby to vadilo, je to důvod
sáhnout po JavaScriptu **až tehdy**, ne teď.

## Testy

`KeyMap` dostane vlastní testy jako `StepPath` a `ProblemMap`.

**Spojovací test** stejného tvaru jako `gui/tests/StepPath.ValidatorContract.phpt`:
nad všemi čtyřmi workflow referenční zátěže se ověří, že množina klíčů
z `KeyMap` se rovná té z validátoru (`Result::getReadKeys()`,
`getWrittenKeys()`).

Ty dvě strany mají vyjít na kus stejně, protože počítají totéž jiným
průchodem. **Bez toho testu by rozchod prošel zeleně na obou stranách** —
kdyby donut přidal typ kroku nebo nové místo, kde se smí objevit šablona, GUI
by o něm nevědělo a klíč by z mapy tiše zmizel. Přesně to se v téhle sadě už
jednou stalo u smlouvy o tvaru cesty (nález I2 v závěrečné revizi vrstvy 1).

**Zvýraznění se testuje bez prohlížeče**, protože `key` je parametr: stačí
ověřit, že označené kroky odpovídají tomu, co `KeyMap` vrací.

Šablony hlídá `gui/tests/Latte.TemplatesCompile.phpt` z vrstvy 1. Chytá
syntaxi, ne rozbité `{import}` mezi soubory — známé omezení, ne novinka.

## Co se vědomě nedělá

- **Tabulka klíčů ani rejstřík.** Vrstva 2 odpovídá na otázku, kterou autor má
  právě teď, ne na „co všechno tu je".
- **Graf.** Při 29 klíčích nečitelný, při méně zbytečný.
- **Pohled napříč workflow.**
- **JavaScript.** Viz „Zvýraznění bez JavaScriptu".
- **Evidence cest ve `Validator`u.** Původní návrh ji předpokládal; ukázalo se,
  že není potřeba. `KeyFlow` zůstává beze změny.
