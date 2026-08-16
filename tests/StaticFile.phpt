<?php

declare(strict_types=1);

use Donut\Gui\StaticFile;
use Nette\Utils\FileSystem;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

$root = TEMP_DIR . '/www';
FileSystem::createDir($root . '/assets');
FileSystem::write($root . '/assets/bootstrap.min.css', 'body{}');
FileSystem::write($root . '/index.php', '<?php');
FileSystem::write(TEMP_DIR . '/tajne.txt', 'TAJEMSTVI');

// existující soubor pod docrootem se má nechat serveru
Assert::true(StaticFile::shouldServe($root, '/assets/bootstrap.min.css'));
Assert::true(StaticFile::shouldServe($root, '/assets/bootstrap.min.css?v=1'));

// router sám sobě se nevydává. Soubor existuje a leží pod docrootem, ale
// přenechat ho serveru znamená prázdnou dvoustovku: server ho spustí jako
// požadovaný skript a `return false` na nejvyšší úrovni ho ukončí bez výstupu.
// Rozhoduje se podle rozhodnuté cesty, ne podle řetězce z URI.
Assert::false(StaticFile::shouldServe($root, '/index.php'));
Assert::false(StaticFile::shouldServe($root, '/./index.php'));
Assert::false(StaticFile::shouldServe($root, '/assets/../index.php'));

// jiný .php pod docrootem router neřeší — vydává se jako každý soubor
FileSystem::write($root . '/jiny.php', '<?php');
Assert::true(StaticFile::shouldServe($root, '/jiny.php'));

// neexistující soubor patří aplikaci
Assert::false(StaticFile::shouldServe($root, '/'));
Assert::false(StaticFile::shouldServe($root, '/?presenter=Workflow&action=edit'));
Assert::false(StaticFile::shouldServe($root, '/assets/neni.css'));

// adresář není soubor
Assert::false(StaticFile::shouldServe($root, '/assets'));

// průchod cestou ven z docrootu. Bez téhle kontroly vrací vestavěný server
// prázdnou dvoustovku: soubor existuje, router mu ho pustí, server ho pak
// odmítne vydat. Obsah neunikne, ale odpověď 200 s prázdným tělem je nesmysl.
Assert::false(StaticFile::shouldServe($root, '/../tajne.txt'));
Assert::false(StaticFile::shouldServe($root, '/assets/../../tajne.txt'));
Assert::false(StaticFile::shouldServe($root, '/..%2ftajne.txt'));
Assert::false(StaticFile::shouldServe($root, '/etc/passwd'));

// nulový byte v cestě. realpath() na něm hází ValueError, a stráž běží před
// Bootstrap::boot(), takže by z něj byla holá pětistovka místo chybové stránky.
Assert::false(StaticFile::shouldServe($root, '/assets/x%00.css'));
Assert::false(StaticFile::shouldServe($root, '/assets/bootstrap.min.css%00.txt'));
Assert::false(StaticFile::shouldServe($root, "/assets/x\0.css"));

// prefix docrootu se musí porovnávat i s oddělovačem — sousední adresář se
// stejným začátkem jména nesmí projít
FileSystem::write(TEMP_DIR . '/wwwjine/soubor.txt', 'x');
Assert::false(StaticFile::shouldServe($root, '/../wwwjine/soubor.txt'));
