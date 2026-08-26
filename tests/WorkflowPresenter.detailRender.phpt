<?php

declare(strict_types=1);

use Donut\Gui\Presentation\Workflow\WorkflowPresenter;
use Donut\Profile;
use Latte\Engine;
use Nette\Application\IPresenter;
use Nette\Application\IPresenterFactory;
use Nette\Application\Request;
use Nette\Application\Responses\TextResponse;
use Nette\Application\Routers\SimpleRouter;
use Nette\Application\UI\Control;
use Nette\Bridges\ApplicationLatte\LatteFactory;
use Nette\Bridges\ApplicationLatte\Template;
use Nette\Bridges\ApplicationLatte\TemplateFactory;
use Nette\Bridges\ApplicationLatte\UIExtension;
use Nette\Http\Request as HttpRequest;
use Nette\Http\Response as HttpResponse;
use Nette\Http\UrlScript;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

// I4: nothing in the repository actually rendered a template.
// Latte.TemplatesCompile.phpt compiles, but Latte only checks {define}'s typed
// parameters at run time — dropping one argument from {include steps, …} left
// the compile step green and would have crashed on the user as an HTTP 500.
// WorkflowPresenter.renderDetail.phpt doesn't render the template either, it
// only touches $presenter->template->error. This test is the first thing
// between the test suite and the user that actually renders the template.

/**
 * A presenter outside the DI container needs $template and injectPrimary()
 * set up by hand. Unlike WorkflowPresenter.renderDetail.phpt, this one gets
 * an Engine with a real UIExtension (otherwise n:href in steps.latte wouldn't
 * work) and a temp directory.
 */
function createPresenter(Profile $profile): WorkflowPresenter
{
	$latteFactory = new class implements LatteFactory {
		public function create(?Control $control = null): Engine
		{
			// Without setTempDirectory() Latte just eval()s the compiled code
			// into memory — no file hits disk. Adding a cache directory under
			// gui/tests/ here would let PHPStan (paths: [src, tests]) pick up
			// the compiled .php files on its next run.
			$engine = new Engine;
			$engine->addExtension(new UIExtension($control));

			return $engine;
		}
	};

	// n:href in steps.latte/detail.latte needs a LinkGenerator, which
	// injectPrimary() only builds when it gets both a router and a presenter
	// factory. The presenter factory here is never asked for another
	// presenter, it only needs to be able to return this one.
	$presenterFactory = new class implements IPresenterFactory {
		public function getPresenterClass(string &$name): string
		{
			return WorkflowPresenter::class;
		}


		public function createPresenter(string $name): IPresenter
		{
			throw new \LogicException('unused — a LinkGenerator only builds addresses, it does not create presenters');
		}
	};

	$presenter = new WorkflowPresenter($profile);
	$presenter->injectPrimary(
		new HttpRequest(new UrlScript('http://localhost/')),
		new HttpResponse,
		presenterFactory: $presenterFactory,
		router: new SimpleRouter('Workflow:default'),
		templateFactory: new TemplateFactory($latteFactory),
	);

	// autoCanonicalize would try to redirect to the canonical address after
	// run() — with a manually built Request (without real routing) that would
	// always fire a RedirectResponse instead of a TextResponse with the
	// template.
	$presenter->autoCanonicalize = false;

	return $presenter;
}


/**
 * renderDetail() reads the profile, so the fixture is passed to it as a
 * Profile — the reference workload has real then/else/foreach branches, no
 * need to build one.
 */
function renderDetailIn(string $dir, string $name, ?string $key = null): string
{
	$presenter = createPresenter(new Profile(basename($dir), $dir));
	$params = ['name' => $name] + ($key === null ? [] : ['key' => $key]);
	$request = new Request('Workflow', 'GET', ['action' => 'detail'] + $params);

	$response = null;
	Assert::noError(function () use ($presenter, $request, &$response) {
		$response = $presenter->run($request);
	});

	Assert::type(TextResponse::class, $response);
	$source = $response->getSource();
	Assert::type(Template::class, $source);

	return $source->renderToString();
}


$root = __DIR__ . '/../../docs/workflows/donut';

// --- without a key: both branches of the "Selected key" condition ---

$html = renderDetailIn($root, 'card-dev');
Assert::contains('card-dev', $html);
Assert::contains('reads', $html);
Assert::contains('writes', $html);
Assert::notContains('Selected key', $html);
Assert::contains('result →', $html, 'run must show which key it writes to');

$html = renderDetailIn($root, 'sync');
Assert::contains('sync', $html);
Assert::contains('reads', $html);
Assert::contains('writes', $html);
Assert::notContains('Selected key', $html);

// --- with a key: the condition's other branch, a highlighted step ---

$html = renderDetailIn($root, 'card-dev', 'repo');
Assert::contains('Selected key: <code>repo</code>', $html);
Assert::contains('class="card step write"', $html);
Assert::contains('class="card step read"', $html);

$html = renderDetailIn($root, 'sync', 'cards');
Assert::contains('Selected key: <code>cards</code>', $html);
Assert::contains('class="card step write"', $html);
Assert::contains('class="card step loop read"', $html, 'cards is read only by the foreach over {%cards%}, so its bubble carries loop too');

// --- the step tree is a chain of bubbles, and the highlight sits on the
// bubble, not on the node ---
// If the class landed on .node, the ring would overflow onto the whole
// then/else/foreach body.

Assert::contains('class="chain"', $html);
Assert::match('~<div class="node">\s*<div class="card step~', $html);

// And now the important part: the highlight sits on the bubble, not on the
// loop body. The assertion below would be vacuous if no step were
// highlighted at all — that's why the page is rendered with a selected key,
// and we first check that something got highlighted at all.
Assert::match('~<div class="card step [^"]*\b(write|read)\b~', $html, 'at least one bubble must be highlighted, otherwise the assertion below asserts nothing');

// This is the one property of the project that can break silently — it
// would just look "somehow off": the loop's ring may frame only its header,
// never the whole body, otherwise it would claim the whole subtree is
// selected.
Assert::notMatch('~<div class="loop-body[^"]*\b(write|read)\b~', $html, 'the highlight must not land on the loop body — that would claim the whole subtree is selected');

// --- the bars highlight only when a key is selected ---

Assert::contains('flow-on', $html, 'the bar with the selected key must intensify');
Assert::contains('flow-dim', $html, 'the other bars must dim');

$syncWithoutKey = renderDetailIn($root, 'sync');
Assert::notContains('flow-on', $syncWithoutKey, 'without a selected key the bar state has no meaning');
Assert::notContains('flow-dim', $syncWithoutKey);

// --- M7: a key that isn't in the workflow ---

$html = renderDetailIn($root, 'card-dev', 'nonsense');
Assert::contains('Selected key: <code>nonsense</code>', $html);
Assert::contains('this key does not occur in the workflow', $html);

// --- if has two branches, even with an empty else ---
// card-dev has two conditions and both have an empty else. If the empty
// branch didn't render, nothing could be added to else — and it would only
// show up as the user missing a link, not as a failing test.

$html = renderDetailIn($root, 'card-dev');
Assert::same(4, substr_count($html, '<div class="branch">'), 'two conditions × two branches');

$pos = strpos($html, '>else</span>');
Assert::type('int', $pos, 'without the branch label the assertion below asserts nothing');
$branch = substr($html, $pos, 400);
Assert::contains('class="add"', $branch, 'an empty else branch must offer "+ step"');
Assert::notContains('class="card step', $branch, 'an empty branch must not contain a bubble');

// --- the block card opens only for a step that works with the selected key ---
// Without this, the user would have to click through bubbles after clicking a
// key to find where it's used. Opening all of them would be just as useless
// as opening none, so both are tested.

$cardDevWithoutKey = renderDetailIn($root, 'card-dev');
Assert::match('~<details~', $cardDevWithoutKey, 'without block cards the assertion below asserts nothing');
Assert::notMatch('~<details[^>]*\bopen\b~', $cardDevWithoutKey, 'without a selected key none may open');

$html = renderDetailIn($root, 'card-dev', 'meJson');
$opened = preg_match_all('~<details[^>]*\bopen\b~', $html);
$all = preg_match_all('~<details~', $html);
Assert::true($opened > 0, 'with a selected key at least one must open');
Assert::true($opened < $all, 'only steps with the selected key may open, not all of them');
