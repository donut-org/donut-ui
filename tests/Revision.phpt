<?php

declare(strict_types=1);

use Donut\Gui\Revision;
use Nette\Utils\FileSystem;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

$root = TEMP_DIR . '/install';
$installed = $root . '/vendor/composer/installed.php';
$config = $root . '/config/common.neon';
FileSystem::write($installed, '<?php return [];');
FileSystem::write($config, "services:\n");

\touch($installed, 1_600_000_000);
\touch($config, 1_500_000_000);
Assert::same('1600000000', Revision::of($root));

// Reinstallation overwrites installed.php, and that's the whole point: a
// different revision means a different container key, so a new one will be
// built.
\touch($installed, 1_700_000_000);
Assert::same('1700000000', Revision::of($root));

// The configuration counts too, and on its own. `git pull` rewrites it without
// touching installed.php — Composer only rewrites that file when something
// actually changes, so an install with nothing to do leaves it alone. Were the
// configuration left out, a pulled change to it would keep running on the
// container compiled for the previous one, and nothing would say so.
\touch($config, 1_800_000_000);
Assert::same('1800000000', Revision::of($root));

// Whichever moved last wins, so an upgrade that touches both is one
// recompilation, not two.
\touch($installed, 1_900_000_000);
Assert::same('1900000000', Revision::of($root));

// Either file alone is enough to date the installation.
FileSystem::delete($installed);
Assert::same('1800000000', Revision::of($root));

// A broken installation is no reason to rebuild the container on every request,
// which would happen if it returned, say, time.
Assert::same('', Revision::of(TEMP_DIR . '/nowhere'));
