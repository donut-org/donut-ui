<?php

declare(strict_types=1);

use Donut\Gui\Presentation\Workflow\WorkflowPresenter;
use Donut\Profile;
use Latte\Engine;
use Nette\Application\UI\Control;
use Nette\Bridges\ApplicationLatte\LatteFactory;
use Nette\Bridges\ApplicationLatte\TemplateFactory;
use Nette\Http\Request;
use Nette\Http\Response;
use Nette\Http\UrlScript;
use Nette\Utils\FileSystem;
use Nette\Application\BadRequestException;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

// M1: `name` from the query string used to get glued into the path without
// checking — ?name=../blocks/echo opened a file outside workflows/.
// basename() in renderDetail() cuts that off before a path to the file is
// even built from it.

function createPresenter(Profile $profile): WorkflowPresenter
{
	$latteFactory = new class implements LatteFactory {
		public function create(?Control $control = null): Engine
		{
			return new Engine;
		}
	};

	$presenter = new WorkflowPresenter($profile);
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

$profile = new Profile(basename($dir), $dir);

// An existing workflow with a name without slashes is found normally.
$presenter = createPresenter($profile);
Assert::noError(fn() => $presenter->renderDetail('w'));
Assert::null($presenter->template->error);

// An attempt to escape workflows/ with slashes ends exactly like a
// nonexistent workflow — a 404 — and never as the content of a file outside
// workflows/. The 404 is what makes the two indistinguishable to the caller:
// a different answer here would tell an attacker the file is there.
$presenter = createPresenter($profile);
$e = Assert::exception(fn() => $presenter->renderDetail('../blocks/echo'), BadRequestException::class);
Assert::same(404, $e->getHttpCode());
Assert::contains('does not exist', $e->getMessage());
Assert::notContains('command', $e->getMessage());

// The step form resolves the same workflow every other entry point would.
// actionStep() takes `name` from the query string too, and used not to
// basename() it: the path's own workflow name then failed to match and the
// answer was a 400 about the address, instead of the 404 that says the
// workflow isn't there. Same rule, same answer, whichever page is asked.
$presenter = createPresenter($profile);
$e = Assert::exception(
	fn() => $presenter->actionStep('../blocks/echo', 'echo.json:steps[0]'),
	BadRequestException::class,
);
Assert::same(404, $e->getHttpCode());
Assert::contains('does not exist', $e->getMessage());
Assert::notContains('command', $e->getMessage(), 'never the contents of a file outside workflows/');
