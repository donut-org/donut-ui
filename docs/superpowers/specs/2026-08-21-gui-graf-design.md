# Strom kroků jako graf — návrh

Každý krok je bublina, bubliny spojují čáry, `if` se pod sebou dělí na
`then` a `else`, `foreach` své tělo obklopuje.

## Cíl

Projekt obsahu stránek udělal ze stromu kroků vnořené list-group. Je to
seznam v seznamu: čitelnější než původní `<ol>`, ale pořád seznam. Workflow
přitom není seznam, je to graf — `if` se větví, `foreach` cykluje a klíče
tečou z kroku do kroku.

Tenhle projekt dává stromu tvar, který tomu odpovídá. Krok je bublina
s hlavičkou, tělem a patičkou; mezi bublinami vedou čáry; větvení a cyklus
mají každý svůj tvar. Vedle toho se z bubliny dá vyčíst tok klíčů, aniž by
se musela číst řádka drobným písmem: co krok čte, je nahoře, co zapisuje,
dole.

Rozsahem je to přepis jedné šablony a jednoho CSS souboru. Žádné PHP.

## Tvar grafu

### Řetěz

Kroky jednoho seznamu jsou uzly pod sebou, mezi sousedy svislá spojnice.
Poslední uzel řetězu je „+ krok" se čtyřmi odkazy na typy — stejně jako
dnes, jen ve tvaru uzlu.

```
<div class=chain>                    ← řetěz kroků, svislé spojnice mezi sousedy
  <div class=node>                   ← jeden uzel = bublina + případné větve
    <div class="card step …">        ← bublina, sem patří třída z KeyMap::classAt()
      <div class=card-header>          štítek typu + jméno kroku
      <div class=card-body>            pruh „čte", obsah podle typu, pruh „zapisuje", problémy
      <div class=card-footer>          upravit ↑ ↓ ×
    </div>
    <div class=branches>             ← jen u if, MIMO bublinu
      <div class=branch>then + {include steps}</div>
      <div class=branch>else + {include steps}</div>
    </div>
  </div>
  <div class=add>+ krok: run set if foreach</div>
</div>
```

### `if` se dělí pod sebou

Bublina `if` je široká jako každá jiná (25 rem). Pod ní, **mimo její
ohraničení**, visí dva sloupce — `then` vlevo, `else` vpravo. Z bubliny
vede pahýl dolů, z něj vodorovná čára mezi středy sloupců a z ní pahýly do
větví.

Sloupce musí být **stejně široké** (`flex: 1 1 0; min-width: 25rem`), jinak
vodorovná čára nesedí na jejich středy. Prázdná větev tak není zmáčknutá na
šířku odkazu „+ krok" — a „+ krok" v ní zůstává, protože jinak by do
prázdného `else` nešlo nic přidat.

### `foreach` své tělo obklopuje

Tělo cyklu je `<div class=loop-body>` uvnitř `card-body`, tónované
(`--bs-tertiary-bg`), aby bylo vidět, kde cyklus začíná a končí. Bublina
cyklu tedy fyzicky obsahuje podstrom — na rozdíl od `if`, který obaluje jen
sám sebe.

Vnořené bubliny si drží svých 25 rem, takže rám cyklu je o dvě odsazení
širší než jeho obsah. `foreach` ve `foreach` v `sync.json` vychází na
~530 px.

### Úzké okno

Graf se nepřeskládává. Na 390 px si drží tvar a stránka se posouvá
vodorovně; `else` větev je za pravým okrajem. Struktura workflow se tím
nikdy nezkreslí — přeskládání větví pod sebe by z grafu udělalo zpátky
seznam.

## Zvýraznění patří bublině, ne podstromu

Klíčové zjištění celého návrhu, přímé pokračování pasti z projektu obsahu
stránek.

Dnes hrozilo, že podbarvení sedne na `<li>`, které obaluje i vnořené tělo,
a pozadí přeteče na celý podstrom. Teď je to totéž v novém kabátě: `foreach`
obsahuje své děti, takže **prstenec kolem celé bubliny cyklu by tvrdil, že
je vybraný celý podstrom uvnitř**.

Proto:

- třída z `KeyMap::classAt()` sedí na bublině (`class="card step write"`)
- bublina cyklu má navíc třídu `loop`
- CSS kreslí prstenec u běžného kroku kolem celé bubliny, u cyklu **jen na
  hlavičce**

`KeyMap::classAt()` se nemění — vrací dál `write`, `read` nebo `write read`.
Mění se jen to, co ty třídy v CSS dělají.

### Barvy

Prstenec vybraného klíče je **žlutý**, jedna barva bez ohledu na čtení
a zápis — ty rozlišují pruhy. Žlutá proto nesmí být zároveň barvou štítku
`if`; ten dostane tmavou.

| prvek | barva |
|---|---|
| štítek `run` | šedá (`text-bg-secondary`) |
| štítek `set` | zelená (`text-bg-success`) |
| štítek `if` | tmavá (`text-bg-dark`) |
| štítek `foreach` | modrá (`text-bg-primary`) |
| pruh „čte" | zelený alert |
| pruh „zapisuje" | modrý alert |
| prstenec vybraného klíče | žlutá |

## Obsah bubliny

| typ | hlavička | tělo |
|---|---|---|
| `run` | štítek `run` + jméno kroku | pruh „čte", `<details>` s kamenem a jeho vstupy, pruh „zapisuje" ve tvaru `kanál → klíč` |
| `set` | štítek `set` + jméno | hodnota jako `<code>`, pruh „zapisuje" s klíčem |
| `if` | štítek `if` + jméno | podmínka `levá op pravá`, pruh „čte" |
| `foreach` | štítek `foreach` + jméno + `{%over%} as jméno` | pruh „čte", pruh „zapisuje" s iterační proměnnou, pod tím `loop-body` s vnořeným řetězem |

**Klíče žijí v pruzích, ne v těle.** U `set` je v těle jen hodnota;
zapisovaný klíč je v modrém pruhu, jinak by byl na stránce dvakrát — jednou
jako odkaz a jednou ne. Totéž platí pro iterační proměnnou `foreach`: je to
klíč jako každý jiný.

Problémy z validátoru zůstávají jako řádka `✕`/`⚠` v těle bubliny přes
dnešní `{define problem}`, beze změny.

### Karta kamene je `<details>`

Vnitřní karta s kamenem a jeho vstupy je ve výchozím stavu sbalená —
v souhrnu je jméno kamene a počet vstupů (`curl-get · 2 vstupy`). Krok
s deseti vstupy tak nezabírá půl obrazovky.

Je to `<details>/<summary>`, ne bootstrapí `collapse`: odpadá závislost na
`bootstrap.bundle.min.js` a stav „otevřeno" je jen atribut v šabloně, ne
skript.

**Otevře se sama, když je vybraný klíč a krok s ním pracuje** — tedy když
`classAt()` vrátí něco jiného než prázdno. Bez toho by uživatel po kliknutí
na klíč proklikával bubliny, aby našel, kde se používá.

Stejná podmínka řídí pruhy: pruh s vybraným klíčem dostane `flow-on`, pruh
bez něj `flow-dim`. Bez vybraného klíče nemá ani jeden stav smysl a pruhy
jsou normální.

## CSS

Všechno jde do `gui/www/assets/donut.css`, žádný nový soubor ani knihovna.
Spojnice jsou `::before` pseudoelementy, ne SVG.

```css
.chain { display: flex; flex-direction: column; align-items: center }
.chain > * + *::before { content: ''; display: block; width: 2px; height: 1.75rem;
	margin: 0 auto; background: var(--bs-border-color) }

.node { display: flex; flex-direction: column; align-items: center }
.step { width: 25rem; max-width: 100% }
.step.loop { width: auto }

.branches { --branch-gap: 2.5rem;
	display: flex; gap: var(--branch-gap); align-items: flex-start;
	position: relative; padding-top: 3rem }
.branch { position: relative; flex: 1 1 0; min-width: 25rem }

/* pahýl z bubliny dolů */
.branches::before { content: ''; position: absolute; top: 0; left: 50%;
	width: 2px; height: 1.5rem; background: var(--bs-border-color) }
/* pahýl do každé větve */
.branch::before { content: ''; position: absolute; top: -1.5rem; left: 50%;
	width: 2px; height: 1.5rem; background: var(--bs-border-color) }
/* Vodorovná spojnice po polovinách, každá ukotvená ve své větvi a zataženo
   do mezery mezi sloupci. Jedna čára přes celé .branches s left/right v
   procentech by nesedla: procenta počítají i s mezerou, takže by konce
   spojnice minuly středy sloupců o čtvrtinu mezery. */
.branch::after { content: ''; position: absolute; top: -1.5rem; height: 2px;
	background: var(--bs-border-color) }
.branch:first-child::after { left: 50%; right: calc(var(--branch-gap) / -2) }
.branch:last-child::after { right: 50%; left: calc(var(--branch-gap) / -2) }

.loop-body { padding: 1rem; background: var(--bs-tertiary-bg);
	border-radius: var(--bs-border-radius) }

.step.write, .step.read { box-shadow: 0 0 0 2px var(--bs-warning) }
.step.loop.write, .step.loop.read { box-shadow: none }
.step.loop.write > .card-header, .step.loop.read > .card-header {
	box-shadow: inset 0 0 0 2px var(--bs-warning) }

.flow { padding: .35rem .6rem; font-size: .875rem; margin-bottom: .75rem }
.flow-dim { opacity: .45 }
.flow-on { box-shadow: 0 0 0 .2rem rgba(var(--bs-warning-rgb), .35) }

details.block > summary { cursor: pointer; padding: .35rem .6rem; font-size: .875rem }
```

Zvýraznění je `box-shadow`, ne `border` — border by o dva pixely změnil
rozměr bubliny a graf by při kliknutí na klíč poskočil.

Ze souboru **zmizí** dnešní `li > div.write`, `li > div.read`
a `li > div.write.read` s odstíny pozadí. Podbarvení nahrazuje prstenec,
takže by to byla mrtvá pravidla. Jejich komentář o tom, proč třída patří
řádku a ne položce, se přepíše na verzi o cyklu.

`.table-responsive > table { min-width: 34rem }` zůstává beze změny.

## Testy

### Devět asercí se přepíše

| kde | dnes tvrdí | nově |
|---|---|---|
| `detailRender:139,140,144,145` | `class="step write"` / `"step read"` | `class="card step write"` / `"card step read"` |
| `detailRender:152` | strom je `list-group` | strom je `chain` s uzly `node` |
| `detailRender:153` | `list-group-item` obaluje `.step` | `node` obaluje `card step` |
| `detailRender:159` | aspoň jeden krok je podbarvený | aspoň jedna bublina má prstenec |
| `detailRender:160` | podbarvení nesedí na `list-group-item` | zvýraznění nesedí na `loop-body` |
| `broken.phpt:99` | typ kroku je `<strong>set</strong>` | typ kroku je štítek `badge` s textem `set` |

`contains('čte')` a `contains('zapisuje')` (`detailRender:125–132`) přežijí
beze změny — pruhy ta slova nesou dál.

### Čtyři nové aserce

Každá kvůli vadě, která by jinak prošla tiše:

1. **Zvýraznění cyklu neobepne podstrom.** Nad `sync` s klíčem `cards` musí
   vzniknout `class="card step loop read"` a zároveň nesmí žádný `loop-body`
   nést `write` ani `read`. První aserce je pojistka proti vakuu druhé.
2. **`<details>` se otevře jen u kroku, který s vybraným klíčem pracuje.**
   Bez klíče v HTML není ani jeden `open`; s klíčem je aspoň jeden a krok,
   který s klíčem nepracuje, ho nemá.
3. **`if` má dvě větve i když je jedna prázdná.** V `card-dev` má `if`
   prázdný `else` — musí vzniknout dva `.branch` a v prázdném dál „+ krok".
4. **Pruhy se zvýrazňují jen při vybraném klíči.** Bez klíče žádné `flow-on`
   ani `flow-dim`.

### Mutace

Dvě, obě míří na tiché vady:

- přesun třídy z bubliny na `loop-body` musí shodit aserci 1
- smazání `open` z `<details>` musí shodit aserci 2

Geometrii (šířky sloupců, spojnice, prstenec) testy neuvidí — na to je
průchod prohlížečem nad kopií dat, v širokém i úzkém okně.

## Rozsah

**Mění se:** `gui/src/Presentation/Workflow/steps.latte`,
`gui/www/assets/donut.css`, aserce v `gui/tests/WorkflowPresenter.detailRender.phpt`
a `gui/tests/WorkflowPresenter.broken.phpt`.

**Nemění se, vědomě:**

- **žádné PHP** — `KeyMap`, `ProblemMap`, `StepPath`, `StepCount`,
  `StepTree`, `StepTreeControl` ani presenter. Kdyby šablona potřebovala
  novou metodu, je to signál, že návrh něco podcenil.
- **ovládání kroku** — `upravit`, `↑`, `↓`, `×` zůstávají ručními
  `<form method=post>` se stejnými signály a stejným potvrzením u mazání
  podstromu; Nette komponentu pořád nejde renderovat uvnitř `n:foreach`.
  Jen se stěhují do `card-footer`.
- **přidávání kroků jen na konec řetězu** — `StepTree::insert()` by vložení
  doprostřed zvládl, ale nabízet ho je jiný projekt.
- **žádný JavaScript** — accordion je `<details>`, spojnice jsou CSS.
- **`detail.latte` a `stepTree.latte`** — volají `{include steps}` a to
  volání se nemění.
- **nadpisy `then`/`else`** zůstanou `<h3>`, jen se stylují jako štítek nad
  sloupcem. Struktura pro čtečku obrazovky se tím neztratí.

## Riziko

`sync.json` má `foreach` ve `foreach` s deseti vstupy v nejhlubším kroku —
je to nejhlubší strom, který v datech je. V mockupu to vychází na ~530 px
a čte se to dobře, ale jestli je rám v rámu v rámu únosný, se pozná až
v prohlížeči, ne z testů. Průchod nad `sync` je proto povinný bod.
