<?php

declare(strict_types=1);

use Donut\Gui\Bootstrap;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

// Revision is a static parameter, and those are entirely part of the
// container's cache key (Configurator::generateContainerKey()). A different
// revision therefore means a different class, i.e. a new file — which is
// exactly what rebuilds the container after an upgrade without anyone
// having to come along and run something.
$env = ['DONUT_GUI_CACHE' => TEMP_DIR . '/cache', 'DONUT_GUI_LOG' => TEMP_DIR . '/log'];

$first = Bootstrap::boot($env)->addStaticParameters(['revision' => 'a'])->createContainer(initialize: false);
$again = Bootstrap::boot($env)->addStaticParameters(['revision' => 'a'])->createContainer(initialize: false);
$other = Bootstrap::boot($env)->addStaticParameters(['revision' => 'b'])->createContainer(initialize: false);

Assert::same(\get_class($first), \get_class($again));
Assert::notSame(\get_class($first), \get_class($other));

// And the revision really does reach the container, rather than quietly
// getting lost along the way.
Assert::same('b', $other->getParameter('revision'));
