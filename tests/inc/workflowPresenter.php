<?php

declare(strict_types=1);

use Donut\Gui\Presentation\Workflow\WorkflowPresenter;
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


/**
 * WorkflowPresenter mimo DI kontejner. Kopíruje uspořádání z blockPresenter.php;
 * liší se jen třídou prezenteru, routerem a tím, že přehled žádný formulář
 * Nette nemá — FormsExtension se přesto registruje, protože ji bude
 * potřebovat stránka kroku z Tasku 6.
 *
 * @param array<string, mixed> $post
 */
function createWorkflowPresenter(array $post = []): WorkflowPresenter
{
	$latteFactory = new class implements LatteFactory {
		public function create(?Control $control = null): Engine
		{
			// Bez setTempDirectory() Latte zkompilovaný kód jen eval()uje do
			// paměti. Cache adresář pod gui/tests/ by PHPStan (paths: [src,
			// tests]) sebral k analýze při dalším běhu.
			$engine = new Engine;
			$engine->addExtension(new UIExtension($control));
			$engine->addExtension(new FormsExtension);

			return $engine;
		}
	};

	$presenterFactory = new class implements IPresenterFactory {
		public function getPresenterClass(string &$name): string
		{
			return WorkflowPresenter::class;
		}


		public function createPresenter(string $name): IPresenter
		{
			throw new \LogicException('nepoužito — LinkGenerator jen skládá adresy');
		}
	};

	$presenter = new WorkflowPresenter;
	$presenter->injectPrimary(
		new HttpRequest(
			new UrlScript('http://localhost/'),
			post: $post,
			// Nette\Application\UI\Form odmítá signál, pokud request nevypadá
			// jako same-origin (ochrana proti CSRF bez session, založená na
			// Fetch Metadata) — skutečný prohlížeč hlavičku posílá sám, tady
			// ji musíme simulovat, jinak by signál tiše selhal.
			headers: $post === [] ? [] : ['sec-fetch-site' => 'same-origin'],
			method: $post === [] ? 'GET' : 'POST',
		),
		new HttpResponse,
		presenterFactory: $presenterFactory,
		router: new SimpleRouter('Workflow:default'),
		templateFactory: new TemplateFactory($latteFactory),
	);

	// Bez tohohle by autoCanonicalize po run() vracelo RedirectResponse
	// místo šablony — Request je sestavený ručně, ne skutečným routováním.
	$presenter->autoCanonicalize = false;

	return $presenter;
}


/**
 * Prezenter čte pracovní adresář (WorkflowRepository::projectDir()), fixtura
 * tedy musí být aktuálním adresářem po dobu volání.
 *
 * @param  array<string, mixed> $params
 * @param  array<string, mixed> $post
 * @return array{0: mixed, 1: string} odpověď a vyrenderované HTML ('' u redirectu)
 */
function runWorkflowPresenterIn(string $dir, array $params, array $post = []): array
{
	$cwd = \getcwd();
	\chdir($dir);

	try {
		$presenter = createWorkflowPresenter($post);
		$request = new Request('Workflow', $post === [] ? 'GET' : 'POST', $params, $post);

		$response = null;
		Assert::noError(function () use ($presenter, $request, &$response) {
			$response = $presenter->run($request);
		});

		if (!$response instanceof TextResponse) {
			return [$response, ''];
		}

		$source = $response->getSource();
		Assert::type(Template::class, $source);

		// getSource() má návratový typ mixed — Assert::type() to ověří za
		// běhu, ale PHPStanu typ nezúží. Instanceof je tu jen kvůli tomu.
		if (!$source instanceof Template) {
			throw new \LogicException('nedosažitelné — Assert::type() by už selhalo');
		}

		return [$response, $source->renderToString()];

	} finally {
		\chdir((string) $cwd);
	}
}
