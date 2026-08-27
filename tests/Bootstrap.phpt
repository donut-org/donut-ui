<?php

declare(strict_types=1);

use Donut\Gui\Bootstrap;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

// The GUI has a user who is not its developer: for them it is a finished
// application, and a Tracy bar over it is noise. So production is the
// default and development is the thing you ask for — the opposite of how
// the framework's own detectDebugMode() works, which guesses from the
// client's address and would put every local user in debug mode.
//
// The environment arrives as an array rather than through getenv(), for the
// same reason Profile::fromEnvironment() takes one: otherwise none of this
// could be tested without putenv().

Assert::false(Bootstrap::boot([])->isDebugMode(), 'no variable means production');
Assert::false(Bootstrap::boot(['DONUT_GUI_DEBUG' => ''])->isDebugMode(), 'an empty value is the same as unset');
Assert::false(Bootstrap::boot(['DONUT_GUI_DEBUG' => '0'])->isDebugMode(), '0 means off, not "a value is present"');

Assert::true(Bootstrap::boot(['DONUT_GUI_DEBUG' => '1'])->isDebugMode());

// Anything else truthy also turns it on: the variable is a switch a person
// flips by hand, and refusing "true" or "yes" would only be a puzzle.
Assert::true(Bootstrap::boot(['DONUT_GUI_DEBUG' => 'true'])->isDebugMode());
Assert::true(Bootstrap::boot(['DONUT_GUI_DEBUG' => 'yes'])->isDebugMode());
