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
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

// C1: a broken block or a missing blocks/ must not knock renderDetail() into
// an unhandled exception — the worst time for that is exactly when the page
// has to show what's broken.

/**
 * A presenter outside the DI container needs $template and injectPrimary()
 * set up by hand, otherwise Presenter::getTemplateFactory() throws "Service
 * TemplateFactory has not been set."
 */
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


/**
 * renderDetail() reads the profile, so the fixture is passed to it as a
 * Profile.
 */
function renderDetailIn(string $dir): WorkflowPresenter
{
	$presenter = createPresenter(new Profile(basename($dir), $dir));
	Assert::noError(fn() => $presenter->renderDetail('w'));

	return $presenter;
}


// A missing blocks/ — BlockRepository throws in its constructor, before it
// even gets to validation.
$dir = TEMP_DIR . '/missing-blocks';
FileSystem::write($dir . '/workflows/w.json', json_encode([
	'name' => 'w',
	'steps' => [],
]));

$presenter = renderDetailIn($dir);

Assert::type('string', $presenter->template->error);
Assert::contains('blocks', $presenter->template->error);

// N2: the page renders in full, but without a single validator finding — it
// must be possible to tell that validation didn't run, not that everything
// is fine.
Assert::contains('Validation did not run', $presenter->template->error);

// N3: a workflow's detail is the first place a fresh user lands after
// creating one. Without a hint an empty project is a dead end — the blocks
// overview has one, this page was missing it.
Assert::contains('mkdir -p ' . $dir . '/blocks', $presenter->template->error);


// A broken block — BlockRepository knows about it from the file listing, but
// parses it lazily and blows up only inside Validator::checkRun().
$dir = TEMP_DIR . '/broken-block';
FileSystem::write($dir . '/workflows/w.json', json_encode([
	'name' => 'w',
	'steps' => [
		['type' => 'run', 'block' => 'broken'],
	],
]));
FileSystem::write($dir . '/blocks/broken.json', '{not valid json');

$presenter = renderDetailIn($dir);

Assert::type('string', $presenter->template->error);

// N2: a broken block file knocks the validator down mid-work, so an empty
// Result doesn't mean "nothing to report" but "validation didn't run".
// Whenever blocks/ has broken JSON, real findings (say, "block does not
// exist") disappear too — the page must admit that, instead of looking
// validated.
Assert::contains('Validation did not run', $presenter->template->error);

// The blocks/ directory exists here, so the `mkdir blocks` hint would be
// nonsense.
Assert::notContains('mkdir', $presenter->template->error);

// And for the record: the validator findings really are gone, the message is
// the only thing about the problem left on the page.
Assert::same([], $presenter->template->workflowProblems);
