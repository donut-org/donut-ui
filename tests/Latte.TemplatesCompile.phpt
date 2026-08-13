<?php

declare(strict_types=1);

use Latte\Engine;
use Nette\Bridges\ApplicationLatte\UIExtension;
use Nette\Bridges\FormsLatte\FormsExtension;
use Nette\Utils\Finder;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

// Nic v CI ani v PHPStanu nikdy neotevře .latte soubor — PHPStan čte jen
// .php, testy prezenterů šablony nerenderují. Kompilace každé šablony je
// tedy jediná brána, která chytne syntaktickou chybu typu `{block title
// n:if=…}` (n: atribut na tagu, který ho nesmí mít) dřív, než ji uvidí
// uživatel jako HTTP 500. Stačí zkompilovat, ne vyrenderovat — chyby jako
// tahle padají už při kompilaci.

$srcDir = \dirname(__DIR__) . '/src';

$templates = [];
foreach (Finder::findFiles('*.latte')->from($srcDir) as $file) {
	$templates[] = (string) $file;
}

sort($templates);
Assert::true(\count($templates) > 0, 'v gui/src nejsou žádné .latte soubory');

foreach ($templates as $file) {
	// Nová Engine pro každý soubor — stav jednoho souboru nesmí ovlivnit
	// kompilaci dalšího. compile() nepíše na disk, cache adresář tedy
	// netřeba. UIExtension bez Control je stejná extension, jakou za běhu
	// registruje Nette\Bridges\ApplicationDI\LatteExtension — díky ní
	// fungují n:href a další tagy z nette/application.
	$engine = new Engine;
	$engine->addExtension(new UIExtension(null));

	// {form} a n:name pocházejí z nette/forms; bez téhle extension by
	// edit.latte neprošlo kompilací.
	$engine->addExtension(new FormsExtension);

	try {
		$engine->compile($file);

	} catch (\Throwable $e) {
		Assert::fail("$file se nedá zkompilovat: {$e->getMessage()}");
	}
}
