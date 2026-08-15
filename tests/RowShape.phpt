<?php

declare(strict_types=1);

use Donut\Gui\RowShape;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

// --- bez POSTu: řádky podle načteného objektu, plus jeden prázdný navíc ---
//
// Ten prázdný je proto, aby měl uživatel kam psát i bez JS.

Assert::same([0], RowShape::of(null, 0));
Assert::same([0, 1], RowShape::of(null, 1));
Assert::same([0, 1, 2, 3], RowShape::of(null, 3));

// --- s POSTem: přesně ty klíče, které dorazily ---
//
// JS řádky nepřečísluje, takže v číslování můžou být díry a kontejnery musí
// vzniknout pro ně, ne pro souvislou řadu.

Assert::same([0, 2, 5], RowShape::of([0 => [], 2 => [], 5 => []], 99));

// Pořadí klíčů z POSTu není zaručené.
Assert::same([0, 1], RowShape::of([1 => [], 0 => []], 99));

// --- klíče se filtrují na číslice ---
//
// Jméno komponenty v Nette musí odpovídat [a-zA-Z0-9_]+ a nic jiného sem
// stejně nepatří.

Assert::same([1], RowShape::of([1 => [], 'x' => [], '../y' => []], 99));

// --- prázdný POST se chová jako žádný ---

Assert::same([0, 1], RowShape::of([], 1));

// --- co polem není, se chová jako žádný POST ---

Assert::same([0], RowShape::of('nesmysl', 0));
Assert::same([0], RowShape::of(null, 0));
