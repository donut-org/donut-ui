<?php

declare(strict_types=1);

use Donut\Gui\Dirs;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

// Paths are only computed, disk is not touched — that's why the test uses
// a fictional root and fictional home.
$root = '/opt/donut-ui';
$home = ['HOME' => '/home/agent'];

// Explicit variable overrides everything else.
Assert::same('/var/cache/donut', Dirs::cache(['DONUT_GUI_CACHE' => '/var/cache/donut'] + $home, $root));
Assert::same('/var/log/donut', Dirs::log(['DONUT_GUI_LOG' => '/var/log/donut'] + $home, $root));

// Then XDG. The `donut-ui` subdirectory is in both, the name `log` only in the
// state one — cache holds more things and distinguishes them inside.
Assert::same('/x/cache/donut-ui', Dirs::cache(['XDG_CACHE_HOME' => '/x/cache'] + $home, $root));
Assert::same('/x/state/donut-ui/log', Dirs::log(['XDG_STATE_HOME' => '/x/state'] + $home, $root));

// Then HOME with default paths prescribed by XDG.
Assert::same('/home/agent/.cache/donut-ui', Dirs::cache($home, $root));
Assert::same('/home/agent/.local/state/donut-ui/log', Dirs::log($home, $root));

// An empty value is the same as unset.
Assert::same('/home/agent/.cache/donut-ui', Dirs::cache(['DONUT_GUI_CACHE' => '', 'XDG_CACHE_HOME' => ''] + $home, $root));
Assert::same('/home/agent/.local/state/donut-ui/log', Dirs::log(['DONUT_GUI_LOG' => '', 'XDG_STATE_HOME' => ''] + $home, $root));

// Environment without HOME and without XDG is broken, but for log's sake
// the application run is not discarded — unlike Donut\Profile, which throws
// an exception without a profile because it has nothing to show.
Assert::same('/opt/donut-ui/temp', Dirs::cache([], $root));
Assert::same('/opt/donut-ui/log', Dirs::log([], $root));
