<?php

declare(strict_types=1);

use Donut\Gui\Presentation\Workflow\WorkflowPresenter;
use Latte\Engine;
use Nette\Application\UI\Control;
use Nette\Bridges\ApplicationLatte\LatteFactory;
use Nette\Bridges\ApplicationLatte\TemplateFactory;
use Nette\Http\Request;
use Nette\Http\Response;
use Nette\Http\UrlScript;
use Nette\Utils\FileSystem;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

// M1: `name` z query stringu se dřív lepilo do cesty bez kontroly —
// ?name=../blocks/echo otevřelo soubor mimo workflows/. basename() v
// renderDetail() to utne dřív, než se z něj vůbec postaví cesta k souboru.

function createPresenter(): WorkflowPresenter
{
	$latteFactory = new class implements LatteFactory {
		public function create(?Control $control = null): Engine
		{
			return new Engine;
		}
	};

	$presenter = new WorkflowPresenter;
	$presenter->injectPrimary(
		new Request(new UrlScript('http://localhost/')),
		new Response,
		templateFactory: new TemplateFactory($latteFactory),
	);

	return $presenter;
}


$dir = TEMP_DIR . '/name-parameter';
FileSystem::write($dir . '/workflows/w.json', json_encode([
	'name' => 'w',
	'steps' => [],
]));
FileSystem::write($dir . '/blocks/echo.json', json_encode([
	'name' => 'echo',
	'command' => 'echo',
	'args' => [],
]));

$cwd = getcwd();
chdir($dir);

try {
	// Existující workflow se jménem bez lomítek se najde normálně.
	$presenter = createPresenter();
	Assert::noError(fn() => $presenter->renderDetail('w'));
	Assert::null($presenter->template->error);

	// Pokus dostat se lomítky mimo workflows/ dostane stejnou hlášku jako
	// neexistující workflow — ne obsah souboru mimo workflows/.
	$presenter = createPresenter();
	Assert::noError(fn() => $presenter->renderDetail('../blocks/echo'));
	Assert::type('string', $presenter->template->error);
	Assert::contains('neexistuje', $presenter->template->error);
	Assert::notContains('command', $presenter->template->error);

} finally {
	chdir($cwd);
}
