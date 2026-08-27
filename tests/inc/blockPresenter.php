<?php

declare(strict_types=1);

use Donut\Gui\Presentation\Block\BlockPresenter;
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
use Nette\Bridges\FormsLatte\FormsExtension;
use Nette\Http\Request as HttpRequest;
use Nette\Http\Response as HttpResponse;
use Nette\Http\UrlScript;
use Tester\Assert;

require_once __DIR__ . '/session.php';


/**
 * A BlockPresenter outside the DI container. Copies the setup from
 * WorkflowPresenter.detailRender.phpt; additionally registers FormsExtension,
 * without which edit.latte wouldn't compile.
 *
 * @param array<string, mixed> $post
 * @param bool                 $sameOrigin send the sec-fetch-site header as if
 *                                         the request came from the same site?
 * @param Profile              $profile   a fixture standing in for the working directory
 */
function createBlockPresenter(array $post, bool $sameOrigin, Profile $profile): BlockPresenter
{
	$latteFactory = new class implements LatteFactory {
		public function create(?Control $control = null): Engine
		{
			// Without setTempDirectory() Latte just eval()s the compiled code
			// into memory. A cache directory under gui/tests/ would let
			// PHPStan (paths: [src, tests]) pick it up on its next run.
			$engine = new Engine;
			$engine->addExtension(new UIExtension($control));
			$engine->addExtension(new FormsExtension);

			return $engine;
		}
	};

	$presenterFactory = new class implements IPresenterFactory {
		public function getPresenterClass(string &$name): string
		{
			// An honest mapping from name to class: without it isLinkCurrent()
			// in the template returns true for every section and the
			// assertion on the active nav item would be vacuous. Literally
			// like in workflowPresenter.php.
			return $name === 'Workflow'
				? WorkflowPresenter::class
				: BlockPresenter::class;
		}


		public function createPresenter(string $name): IPresenter
		{
			throw new \LogicException('unused — a LinkGenerator only builds addresses');
		}
	};

	$presenter = new BlockPresenter($profile);
	$httpResponse = new HttpResponse;

	$presenter->injectPrimary(
		new HttpRequest(
			new UrlScript('http://localhost/'),
			post: $post,
			// Nette\Application\UI\Form rejects the "submit" signal if the
			// request doesn't look same-origin (CSRF protection without a
			// session, based on Fetch Metadata) — a real browser sends the
			// header itself on every same-site navigation, starting with GET,
			// so it has to be simulated here, otherwise the form silently
			// falls into detectedCsrf() and onSuccess never fires — and a GET
			// with a signal in the address (`do=…`) veers off into a redirect
			// instead of rendering. That veer-off is exactly what
			// $sameOrigin: false models — a request from a foreign site.
			headers: $sameOrigin ? ['sec-fetch-site' => 'same-origin'] : [],
			method: $post === [] ? 'GET' : 'POST',
		),
		$httpResponse,
		presenterFactory: $presenterFactory,
		router: new SimpleRouter('Block:default'),
		// Only flash messages need it. A session that never reaches PHP's
		// session module keeps Tester's output handler out of the way; see
		// inc/session.php.
		session: new MemorySession($httpResponse),
		templateFactory: new TemplateFactory($latteFactory),
	);

	// Without this, autoCanonicalize would return a RedirectResponse after
	// run() instead of the template — the Request is built by hand, not
	// through real routing.
	$presenter->autoCanonicalize = false;

	return $presenter;
}


/**
 * The presenter reads the profile, not the working directory — the fixture
 * is passed to it as a Profile. The profile name is the fixture directory's
 * name, so it's possible to assert on what the GUI shows in the header too.
 *
 * @param  array<string, mixed> $params
 * @param  array<string, mixed> $post
 * @param  bool                 $sameOrigin see createBlockPresenter()
 * @return array{0: mixed, 1: string} the response and the rendered HTML ('' for a redirect)
 */
function runBlockPresenterIn(string $dir, array $params, array $post = [], bool $sameOrigin = true): array
{
	$presenter = createBlockPresenter($post, $sameOrigin, new Profile(\basename($dir), $dir));
	$request = new Request('Block', $post === [] ? 'GET' : 'POST', $params, $post);

	$response = null;
	Assert::noError(function () use ($presenter, $request, &$response) {
		$response = $presenter->run($request);
	});

	if (!$response instanceof TextResponse) {
		return [$response, ''];
	}

	$source = $response->getSource();
	Assert::type(Template::class, $source);

	// getSource() has a return type of mixed — Assert::type() verifies that
	// at run time, but it doesn't narrow the type for PHPStan. The instanceof
	// is here only for that.
	if (!$source instanceof Template) {
		throw new \LogicException('unreachable — Assert::type() would already have failed');
	}

	return [$response, $source->renderToString()];
}
