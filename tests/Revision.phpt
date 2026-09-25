<?php

declare(strict_types=1);

use Donut\Gui\Revision;
use Nette\Utils\FileSystem;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

$root = TEMP_DIR . '/install';
$installed = $root . '/vendor/composer/installed.php';
FileSystem::write($installed, '<?php return [];');

\touch($installed, 1_600_000_000);
Assert::same('1600000000', Revision::of($root));

// Reinstallation overwrites that file, and that's the whole point: a different
// revision means a different container key, so a new one will be built.
\touch($installed, 1_700_000_000);
Assert::same('1700000000', Revision::of($root));

// A broken installation is no reason to rebuild the container on every request,
// which would happen if it returned, say, time.
Assert::same('', Revision::of(TEMP_DIR . '/nowhere'));
