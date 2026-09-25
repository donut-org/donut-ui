<?php

declare(strict_types=1);

use Donut\Gui\Dirs;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

// Cesty se jen počítají, na disk se nesahá — proto je v testu smyšlený
// kořen a smyšlený domov.
$root = '/opt/donut-ui';
$home = ['HOME' => '/home/agent'];

// Výslovná proměnná přebíjí všechno ostatní.
Assert::same('/var/cache/donut', Dirs::cache(['DONUT_GUI_CACHE' => '/var/cache/donut'] + $home, $root));
Assert::same('/var/log/donut', Dirs::log(['DONUT_GUI_LOG' => '/var/log/donut'] + $home, $root));

// Pak XDG. Podadresář `donut-ui` je v obou, jméno `log` jen v tom stavovém —
// cache drží víc věcí a rozlišuje je až uvnitř.
Assert::same('/x/cache/donut-ui', Dirs::cache(['XDG_CACHE_HOME' => '/x/cache'] + $home, $root));
Assert::same('/x/state/donut-ui/log', Dirs::log(['XDG_STATE_HOME' => '/x/state'] + $home, $root));

// Pak HOME s výchozími cestami, které XDG předepisuje.
Assert::same('/home/agent/.cache/donut-ui', Dirs::cache($home, $root));
Assert::same('/home/agent/.local/state/donut-ui/log', Dirs::log($home, $root));

// Prázdná hodnota je totéž co nenastavená.
Assert::same('/home/agent/.cache/donut-ui', Dirs::cache(['DONUT_GUI_CACHE' => '', 'XDG_CACHE_HOME' => ''] + $home, $root));
Assert::same('/home/agent/.local/state/donut-ui/log', Dirs::log(['DONUT_GUI_LOG' => '', 'XDG_STATE_HOME' => ''] + $home, $root));

// Prostředí bez HOME i bez XDG je rozbité, ale kvůli logu se nezahazuje
// běh aplikace — na rozdíl od Donut\Profile, který bez profilu vyhodí
// výjimku, protože bez něj nemá co ukázat.
Assert::same('/opt/donut-ui/temp', Dirs::cache([], $root));
Assert::same('/opt/donut-ui/log', Dirs::log([], $root));
