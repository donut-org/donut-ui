# Vzhled GUI — rám a formuláře — návrh

Bootstrap 5.3 a vanilla JS, dvousloupcový layout, `FormFactory` a opakující
se řádky jako tabulky s hlavičkou.

## Cíl

GUI je od vrstvy 3 kompletní autorské prostředí, ale vypadá jako holé HTML
a na jednom místě rovnou zamlčuje informaci: řádky vstupů workflow jsou
čtyři nepopsaná políčka vedle sebe. V `createComponentHeaderForm()` stojí
doslova `$row->addText('name')` bez druhého argumentu, takže Nette nemá
z čeho popisek vyrobit. Totéž mají vstupy kamene a `in`/`out` kroku.

Cílem je dát stránkám rám, formulářům bootstrapí vzhled a opakujícím se
řádkům hlavičku, ze které je poznat, co do kterého sloupce patří.

## Dva projekty

Zásah do všech deseti šablon, obou prezentérů, způsobu spouštění, readme
i testů je na jeden plán moc a dlouho by neexistoval stav, kdy je GUI
použitelné. Dělí se proto na dva projekty; tenhle návrh popisuje první.

**Projekt A — rám a formuláře** (tento návrh). Assety a nové spouštění,
dvousloupcový layout s offcanvas navigací a drobečky, `FormFactory`,
a opakující se řádky jako tabulky. Po něm GUI vypadá jako aplikace a je
z něj poznat, co do kterého políčka patří. Vnitřek stránek zůstává:
seznamy `<ul>`, strom kroků vnořené seznamy.

**Projekt B — obsah stránek** (samostatný návrh, později). Seznamy
workflow a kamenů jako tabulky s akcemi, strom kroků jako list-group
s odsazením, chybové hlášky jako alerty, sekce formulářů jako karty.

Dělící čára vede tudy schválně: A se dotýká layoutu a formulářů, tedy
věcí soustředěných na jednom místě. B se dotýká vnitřku každé šablony
zvlášť a s ním i těch tří asercí, které se ptají na strukturu HTML.
Kdyby se to dělalo naráz, mísila by se dvě různá rizika v jednom diffu.

## Assety a spouštění

### Proč to není triviální

`php -S` statické soubory servírovat umí — ale ne zároveň s router
scriptem. Naměřeno:

| spuštění | statický soubor | pracovní adresář |
|---|---|---|
| `php -S -t gui/www` | servíruje, `text/css` | `gui/www` |
| `php -S -t gui/www …/index.php` | jde do routeru, `text/html` | adresář projektu |

Router dostane **každý** požadavek; server po souboru sáhne jen tehdy,
když router vrátí `return false`. A router je ve hře právě proto, aby
nenastal `chdir()` do docrootu — bez něj se pracovní adresář přesune do
`gui/www` a GUI by tam hledalo `blocks/` a `workflows/`.

### Rozhodnutí

Router zůstává, dostane stráž. Zvažovaná alternativa — zahodit router
a předat projekt proměnnou `DONUT_PROJECT` — padla na tom, že by
vyměnila dnešní kontrakt „GUI hledá v pracovním adresáři" za proměnnou,
na kterou se dá zapomenout; kdo by na ni zapomněl, dostal by pracovní
adresář `gui/www` a k tomu radu `mkdir workflows`, která by mu adresář
založila na špatném místě.

### Docroot se přesune

```
cd /muj/projekt
php -S 127.0.0.1:8000 -t /cesta/k/donut/gui/www /cesta/k/donut/gui/www/index.php
```

`-t` míří nově na `gui/www`, ne na projekt. Pracovní adresář to nemění —
router `chdir()` potlačí, ověřeno měřením.

Je to zároveň bezpečnostní zlepšení. Dnes je docroot *projekt uživatele*,
takže jakmile by v `index.php` kdykoli přibylo `return false`, byla by
přes HTTP stažitelná syrová `workflows/card-dev.json` i cokoli dalšího.
S docrootem na `gui/www` se k projektu přes web nedostane nic.

Readme dostane nový příkaz a s ním opravu tvrzení, proč je router nutný:
dnes tam stojí, že kvůli `chdir()` do docrootu, což platí jen bez routeru.
Ve skutečnosti je router nutný proto, aby `chdir()` **nenastal**.

### Soubory

```
gui/www/
├── index.php          ← zavolá StaticFile::shouldServe()
└── assets/
    ├── bootstrap.min.css        ~230 kB, 5.3.8
    ├── bootstrap.bundle.min.js  ~80 kB, kvůli offcanvas
    ├── donut.css                dnešní inline <style> z @layout.latte
    └── rows.js                  dnešní inline <script> z rows.latte
```

Bootstrap se vendoruje ručně; do readme jde verze a zdroj, aby bylo při
aktualizaci jasné, co nahradit. Composer balík `twbs/bootstrap` se
nepoužije — skončil by ve `vendor/`, kam docroot nedosáhne, takže by se
stejně musel kopírovat, a to by znamenalo build krok, který projekt nemá.

### `StaticFile`

Stráž nesmí být inline v `index.php` — sady staví prezentéry v procesu
a přes `index.php` neprojdou, takže by nešla otestovat. Půjde tedy do
`gui/src` jako čistá funkce:

```php
Donut\Gui\StaticFile::shouldServe(string $root, string $uri): bool
```

Uvnitř `realpath()` a kontrola, že výsledek leží pod `$root`. `index.php`
podle návratu udělá `return false`; co neprojde, propadne do aplikace
a dostane normální chybovou stránku.

Bez té kontroly vrací průchod cestou ven z docrootu prázdnou dvoustovku —
ověřeno na `/assets/../../projekt/tajne.txt`, `..%2f` variantě i na
`/etc/passwd`. Obsah souboru přitom neunikl (PHP server má vlastní
kontrolu docrootu), ale odpověď 200 s prázdným tělem je nesmysl.

### Vedlejší úklid

Jakmile je `rows.js` normální soubor, zmizí důvod pro `n:syntax="off"`
v `rows.latte` i celý blok `{define rows}`; šablony místo něj dostanou
`<script src>` v layoutu. Test `Latte.TemplatesCompile.phpt` zůstává,
protože hlídá i ostatní šablony.

## Layout

```
<div class="container-fluid">
  <div class="row">
    <nav class="offcanvas-md offcanvas-start col-md-3 col-lg-2 …">
      Donut
      Workflow
      Kameny
    </nav>
    <main class="col">
      <nav aria-label=breadcrumb> Workflow / card-dev </nav>
      {include content}
    </main>
  </div>
</div>
```

Levý sloupec je jeden prvek, ne dva. `offcanvas-md` znamená: pod `md`
vysouvací panel, od `md` výš obyčejný sloupec. Navigace se tedy nepíše
dvakrát a nic se nepřepíná v JS — jen se nad `md` skryje tlačítko, které
panel otevírá. Kvůli offcanvas je v assetech i `bootstrap.bundle.min.js`;
bez něj by stačilo CSS.

Navigace má dvě položky, protože GUI má dvě sekce: **Workflow** a
**Kameny**. Aktivní se pozná z `$presenter->isLinkCurrent()`, takže se
nikde neudržuje seznam „která stránka patří do které sekce".

V levém sloupci bude zatím **textový název**, ne logo. `docs/logo.png`
v repozitáři leží, ale je netrackovaný a mezi položkami, které se
necommitují; až bude logo potřeba, je to jeden `<img>`.

### Drobečky

Zůstávají na šablonách, ne na prezentérech. Každá stránka nadefinuje
`{block breadcrumbs}`, layout ho vloží a sám má výchozí podobu pro
případ, že ho stránka nepřepíše.

Důvod: drobečky jsou navigační text, který zná právě kreslená šablona,
a všechny potřebné hodnoty (`$name`, cesta ke kroku) v ní už jsou.
Varianta „prezentér naplní `$template->breadcrumbs`" by znamenala novou
vlastnost v pěti šablonových třídách a nový řádek v každé akci obou
prezentérů — víc kódu za tutéž větu na obrazovce.

Cesty:

```
Workflow                              seznam
Workflow / card-dev                   detail
Workflow / card-dev / hlavička        úprava obálky
Workflow / card-dev / krok            úprava kroku
Kameny                                seznam
Kameny / jq                           úprava kamene
```

Dnešní zpáteční odkazy `← workflow` a `← card-dev` na začátku každé
stránky tím zmizí; drobečky dělají totéž srozumitelněji.

## FormFactory

`FormFactory::create()` je statická továrna vedle `RowShape::of()`
a `Text::of()`, tedy ve stylu, který projekt používá. Vrátí `Form`
s jedním `onRender`, který podle typu prvku doplní třídu: `form-control`
textovým polím, `form-select` výběrům, `form-check-input` zaškrtávacím,
`btn` tlačítkům.

Třídu doplní **jen tam, kde žádná není**. Díky tomu může mazací tlačítko
dostat `btn btn-danger` už při vzniku a továrna mu to nepřepíše.

Přes továrnu půjdou **všechny** formuláře v GUI — `headerForm`,
`deleteWorkflowForm` a `stepForm` ve `WorkflowPresenteru`, `blockForm`
a `deleteForm` v `BlockPresenteru`. Každé `new Form` v obou prezentérech
se nahradí voláním továrny; jinde v GUI se formulář nevyrábí.

Šablony vykreslují políčka ručně přes `{input name}`, ne přes
`{control form}`, takže standardní bootstrapí recept přes
`$renderer->wrappers` by se vůbec neuplatnil. `onRender` ano — tag
`{form}` ho spouští na začátku (`FormsLatte\Runtime::begin()` volá
`fireRenderEvents()`). Ověřeno:

```
před:  <input type="text" name="jmeno" id="frm-jmeno">
po:    <input type="text" name="jmeno" id="frm-jmeno" class="form-control">
```

Továrna tedy nesahá na šablony ani na renderer.

## Opakující se řádky

Čtyři místa, dvojího druhu:

| místo | dnes | sloupce tabulky |
|---|---|---|
| vstupy workflow | čtyři holá políčka | Jméno · Povinný · Výchozí · Popis |
| vstupy kamene | čtyři holá políčka | Jméno · Povinný · Výchozí · Popis |
| `in` kroku | `key` → `value` | Vstup kamene · Hodnota nebo `{%klíč%}` |
| `out` kroku | `channel` → `value` | Co z kamene · Pod jakým klíčem do mapy |

Hlavičky jsou samy o sobě odpovědí na „co do toho patří" — proto tabulka,
ne popisek u každého políčka. Pod tabulkou vstupů zůstane věta o tom, že
se výchozí hodnota použije, když ji volající nepředá, a že se vstup na
CLI zadává jako `--jmeno=hodnota`.

**Argumenty kamene do tohohle projektu nepatří.** Mají vlastní vnořenou
strukturu (`arg-group`, skupiny po argumentech) a sdílený mechanismus
řádků nepoužívají; dostanou jen bootstrapí třídy na tlačítka.
Vysvětlující větu o skupinách už mají.

### Kolize `.row`

`rows.latte` používá CSS třídu `.row`, a to je zároveň Bootstrap grid —
jakmile se načte Bootstrap, začne se každý řádek formuláře chovat jako
flexový grid řádek. `<div class=row>` se proto stane `<tr class=js-row>`
uvnitř `<tbody id=inputs>`. Prefix `js-` říká, že na tu třídu sahá
skript, ne stylopis.

Kontrakt toho skriptu zůstává beze změny: **indexy se nikdy
nepřečíslovávají**, nový řádek dostane o jedna vyšší než dosavadní
maximum a smazání nechá v číslování díru. Stojí na něm `RowShape::of()`
a dřívější revize ho opakovaně prověřovaly.

## Testy

### Co se rozbije

Změřeno: napříč šesti soubory je čtrnáct asercí ptajících se na HTML
a z nich na strukturu sahají tři — `class=arg-group`
v `BlockPresenter.delete.phpt` a dvojice `<ul class=error>` /
`<ul class=warning>` v `BlockPresenter.edit.phpt`. Na zpáteční odkazy se
neptá ani jedna.

Projekt A tedy neshodí nic: argumenty se v něm nemění a chybové seznamy
taky ne. Ty se promění v alerty až v projektu B a tehdy se ty dvě aserce
přepíšou spolu s nimi.

Platí pravidlo, které se osvědčilo na předchozí větvi: **žádná stávající
aserce se nesmí oslabit**. Kde se značkování opravdu mění, aserce se
přepíše na nové — a v hlášení se každá taková změna vyjmenuje.

### Nové testy

- `StaticFile.phpt` — jednotkový, včetně cest `/assets/../../projekt/tajne.txt`,
  `..%2f` varianty a `/etc/passwd`
- `FormFactory.phpt` — třída podle typu prvku, a hlavně že **nepřepíše**
  třídu, kterou už prvek má
- layout — že se vykreslí navigace, drobečky a offcanvas značkování

### Prohlížeč

Ruční kontrola v headless Chrome nad **kopií** dat v `/tmp`, nikdy nad
`docs/workflows/`. Musí zahrnout i úzké okno — offcanvas se jinak
neprojeví.

## Co se vědomě nedělá

- **tmavý režim** — Bootstrap 5.3 ho umí přes `data-bs-theme`, ale nikdo
  si o něj neřekl
- **build krok** — žádný sass, žádná minifikace, žádný `npm`; assety jsou
  hotové soubory
- **logo** — zatím textový název
- **argumenty kamene jako tabulka** — mají jinou strukturu, patří jinam
- **obsah stránek** — seznamy, strom kroků, alerty a karty jsou projekt B
