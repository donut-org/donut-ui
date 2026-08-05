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

// C1: vadný kámen nebo chybějící blocks/ nesmí shodit renderDetail() na
// neošetřenou výjimku — nejhorší chvíle na to, je zrovna když má stránka
// ukázat, co je rozbité.

/**
 * Presenter mimo DI kontejner potřebuje $template a injectPrimary() ručně,
 * jinak Presenter::getTemplateFactory() vyhodí "Service TemplateFactory has
 * not been set."
 */
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


/**
 * renderDetail() čte cwd (WorkflowPresenter::projectDir()), fixtura tedy
 * musí být aktuálním adresářem po dobu volání.
 */
function renderDetailIn(string $dir): WorkflowPresenter
{
	$cwd = getcwd();
	chdir($dir);

	try {
		$presenter = createPresenter();
		Assert::noError(fn() => $presenter->renderDetail('w'));

		return $presenter;

	} finally {
		chdir($cwd);
	}
}


// Chybějící blocks/ — BlockRepository hází v konstruktoru dřív, než se
// vůbec dostane k validaci.
$dir = TEMP_DIR . '/missing-blocks';
FileSystem::write($dir . '/workflows/w.json', json_encode([
	'name' => 'w',
	'steps' => [],
]));

$presenter = renderDetailIn($dir);

Assert::type('string', $presenter->template->error);
Assert::contains('blocks', $presenter->template->error);


// Vadný kámen — BlockRepository ho zná ze seznamu souborů, ale parsuje ho
// líně a vyletí až uvnitř Validator::checkRun().
$dir = TEMP_DIR . '/broken-block';
FileSystem::write($dir . '/workflows/w.json', json_encode([
	'name' => 'w',
	'steps' => [
		['type' => 'run', 'block' => 'broken'],
	],
]));
FileSystem::write($dir . '/blocks/broken.json', '{ toto neni platny json');

$presenter = renderDetailIn($dir);

Assert::type('string', $presenter->template->error);
