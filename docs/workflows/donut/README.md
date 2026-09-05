# Přepis workflow do formátu donut

Ověřovací přepis existujících bashových workflow z `../jpw/` do formátu
popsaného v `../../format-specifikace.md`. Slouží k ověření formátu —
engine (`bin/donut`), který to spouští, viz níže.

Návrh a jeho zdůvodnění: `../../superpowers/specs/2026-07-31-prepis-workflow-design.md`

```
blocks/      15 kamenů — curl a jq obslouží většinu, zbytek jsou
             vlastní příkazy odvozené z olw-* skriptů
workflows/   card-dev   (29 kroků) ← jpw-indev-developer, -developer-haiku,
                                      -assistent, -assistent-haiku
             card-spec  (25 kroků) ← jpw-indev-techlead
             sync       (37 kroků) ← jpw-indev-sync
             repo-check  (5 kroků) — není přepis, viz níže
```

`jpw-queue-consume` přepsaný není — dohled nad dlouho běžícím procesem
není workflow a zůstává v bashi.

## `repo-check` a proč tu je

Jediné workflow, které nevzniklo přepisem bashe. Zjišťuje, jestli
k projektu existuje GitHub repozitář — stejným hledáním, jaké dělá
`card-dev` — a odpovídá tím na otázku, proč karta skončila ve workspace
bez gitu.

Hlavní důvod je ale jiný: **žádný ze tří přepsaných workflow neobsahuje
větev `else`.** Bash, ze kterého vznikly, se nikde nevětví na dvě strany.
Díky tomu prošel formátem i implementací chybný předpoklad, že klíč
zapsaný v obou větvích `if` je za `if` jen „možná" — validátor pak odmítal
korektní workflow s hláškou, která nebyla pravdivá. Sedm recenzí i zelený
přijímací test to minuly, protože tu cestu nebylo čím pokrýt.

`repo-check` zapisuje `message` v obou větvích a čte ho za `if`. Proti
opravené implementaci dává nula chyb a nula varování; proti té chybné
přijímací test spadne. Ta větev `else` není ozdoba — je to jediné, co
tenhle druh chyby v přepisu odhalí.

## Spuštění

`donut` hledá `blocks/` a `workflows/` **v aktuálním pracovním adresáři** —
tyhle příkazy je proto potřeba spouštět z `docs/workflows/donut/`.

```
donut sync      --queueFile=… --promptsDir=… --workRoot=… --curlrc=…
donut card-dev  --shortId=… --expectStatus=ReadyToDev --model=sonnet \
                --systemPrompt=… --targetList=Testing --tag=#developer \
                --workRoot=… --curlrc=…
```

`sync` sám sestavuje tahle volání a zařazuje je do fronty přes `jptq`.

## Předpoklady

- `~/.config/donut/trello.curlrc` s přihlašovací hlavičkou pro Trello API;
  cesta se předává jako `--curlrc`. Engine o credentials neví nic.
- Přepsané `olw-*` příkazy podle rozhraní v návrhovém dokumentu — bez JSON
  obálky, argumenty vstup, stdout výstup.
