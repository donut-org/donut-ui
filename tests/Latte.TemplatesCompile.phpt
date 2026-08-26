<?php

declare(strict_types=1);

use Latte\Engine;
use Nette\Bridges\ApplicationLatte\UIExtension;
use Nette\Bridges\FormsLatte\FormsExtension;
use Nette\Utils\Finder;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

// Nothing in CI or PHPStan ever opens a .latte file — PHPStan reads only
// .php, and the presenter tests don't render templates. Compiling every
// template is therefore the only gate that catches a syntax error like
// `{block title n:if=…}` (an n: attribute on a tag that must not have one)
// before the user sees it as an HTTP 500. Compiling is enough, no need to
// render — an error like this already fails at compile time.

$srcDir = \dirname(__DIR__) . '/src';

$templates = [];
foreach (Finder::findFiles('*.latte')->from($srcDir) as $file) {
	$templates[] = (string) $file;
}

sort($templates);
Assert::true(\count($templates) > 0, 'there are no .latte files in gui/src');

foreach ($templates as $file) {
	// A new Engine for every file — one file's state must not affect the next
	// one's compilation. compile() doesn't write to disk, so no cache
	// directory is needed. UIExtension without a Control is the same
	// extension that Nette\Bridges\ApplicationDI\LatteExtension registers at
	// run time — it's what makes n:href and other nette/application tags
	// work.
	$engine = new Engine;
	$engine->addExtension(new UIExtension(null));

	// {form} and n:name come from nette/forms; without this extension
	// edit.latte wouldn't compile.
	$engine->addExtension(new FormsExtension);

	try {
		$engine->compile($file);

	} catch (\Throwable $e) {
		Assert::fail("$file failed to compile: {$e->getMessage()}");
	}
}
