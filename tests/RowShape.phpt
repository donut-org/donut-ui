<?php

declare(strict_types=1);

use Donut\Gui\RowShape;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

// --- without POST: rows according to the loaded object, plus one extra empty one ---
//
// The empty one is so the user has somewhere to write even without JS.

Assert::same([0], RowShape::of(null, 0));
Assert::same([0, 1], RowShape::of(null, 1));
Assert::same([0, 1, 2, 3], RowShape::of(null, 3));

// --- with POST: exactly the keys that arrived ---
//
// JS doesn't renumber rows, so the numbering can have holes, and containers
// must be created for those, not for a contiguous run.

Assert::same([0, 2, 5], RowShape::of([0 => [], 2 => [], 5 => []], 99));

// The order of keys from POST isn't guaranteed.
Assert::same([0, 1], RowShape::of([1 => [], 0 => []], 99));

// --- keys are filtered down to digits ---
//
// A component name in Nette must match [a-zA-Z0-9_]+ and nothing else
// belongs here anyway.

Assert::same([1], RowShape::of([1 => [], 'x' => [], '../y' => []], 99));

// --- an empty container in POST isn't the same as no POST ---
//
// An empty list would be a dead end — JS clones the last row, so a
// container with no rows could no longer be extended. Hence one empty row.

Assert::same([0], RowShape::of([], 1));
Assert::same([0], RowShape::of([], 0));

// Keys that pass the digit filter but leave nothing behind are the same.
Assert::same([0], RowShape::of(['x' => [], '../y' => []], 3));

// With no POST, it's still derived from the object.
Assert::same([0, 1], RowShape::of(null, 1));

// --- what isn't an array behaves like no POST ---

Assert::same([0], RowShape::of('nonsense', 0));
Assert::same([0], RowShape::of(null, 0));
