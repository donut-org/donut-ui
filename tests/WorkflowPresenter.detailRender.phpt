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
use Nette\Http\Request as HttpRequest;
use Nette\Http\Response as HttpResponse;
use Nette\Http\UrlScript;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

// I4: nic v repozitáři šablony doopravdy nerenderovalo. Latte.TemplatesCompile.phpt
// kompiluje, ale typované parametry {define} kontroluje Latte až za běhu —
// vypuštění jednoho argumentu z {include steps, …} tak nechalo kompilaci
// zelenou a spadlo by až uživateli jako HTTP 500. WorkflowPresenter.renderDetail.phpt
// šablonu taky nerenderuje, sahá jen na $presenter->template->error. Tenhle
// test je mezi testem a uživatelem první věc, co šablonu doopravdy vyrenderuje.

/**
 * Presenter mimo DI kontejner potřebuje $template a injectPrimary() ručně.
 * Na rozdíl od WorkflowPresenter.renderDetail.phpt dostane Engine se skutečnou
 * UIExtension (jinak by n:href v steps.latte nefungovalo) a temp adresář.
 */
function createPresenter(): WorkflowPresenter
{
	$latteFactory = new class implements LatteFactory {
		public function create(?Control $control = null): Engine
		{
			// Bez setTempDirectory() Latte zkompilovaný kód jen eval()uje
			// do paměti — žádný soubor na disk. Kdyby se sem přidal cache
			// adresář pod gui/tests/, PHPStan (paths: [src, tests]) by
			// vyzkompilované .php soubory sebral k analýze při dalším běhu.
			$engine = new Engine;
			$engine->addExtension(new UIExtension($control));

			return $engine;
		}
	};

	// n:href v steps.latte/detail.latte potřebuje LinkGenerator, ten se
	// v injectPrimary() postaví, jen když dostane router i presenter factory
	// zároveň. Presenter factory se tu nikdy nezeptá na jiný presenter,
	// stačí jí umět vrátit tenhle jediný.
	$presenterFactory = new class implements IPresenterFactory {
		public function getPresenterClass(string &$name): string
		{
			return WorkflowPresenter::class;
		}


		public function createPresenter(string $name): IPresenter
		{
			throw new \LogicException('nepoužito — LinkGenerator jen skládá adresy, nevytváří presentery');
		}
	};

	$presenter = new WorkflowPresenter;
	$presenter->injectPrimary(
		new HttpRequest(new UrlScript('http://localhost/')),
		new HttpResponse,
		presenterFactory: $presenterFactory,
		router: new SimpleRouter('Workflow:default'),
		templateFactory: new TemplateFactory($latteFactory),
	);

	// autoCanonicalize by po run() zkoušelo přesměrovat na kanonickou adresu
	// — s ručně sestaveným Requestem (bez skutečného routování) by to
	// vždycky spustilo RedirectResponse místo TextResponse se šablonou.
	$presenter->autoCanonicalize = false;

	return $presenter;
}


/**
 * renderDetail() čte cwd (WorkflowRepository::projectDir()), fixtura tedy
 * musí být aktuálním adresářem po dobu volání — referenční zátěž má reálné
 * then/else/foreach větve, není potřeba stavět vlastní.
 */
function renderDetailIn(string $dir, string $name, ?string $key = null): string
{
	$cwd = getcwd();
	chdir($dir);

	try {
		$presenter = createPresenter();
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

	} finally {
		chdir($cwd);
	}
}


$root = __DIR__ . '/../../docs/workflows/donut';

// --- bez key: obě větve podmínky u "Vybraný klíč" ---

$html = renderDetailIn($root, 'card-dev');
Assert::contains('card-dev', $html);
Assert::contains('čte', $html);
Assert::contains('zapisuje', $html);
Assert::notContains('Vybraný klíč', $html);

$html = renderDetailIn($root, 'sync');
Assert::contains('sync', $html);
Assert::contains('čte', $html);
Assert::contains('zapisuje', $html);
Assert::notContains('Vybraný klíč', $html);

// --- s key: druhá větev podmínky, zvýrazněný krok ---

$html = renderDetailIn($root, 'card-dev', 'repo');
Assert::contains('Vybraný klíč: <code>repo</code>', $html);
Assert::contains('class="write"', $html);
Assert::contains('class="read"', $html);

$html = renderDetailIn($root, 'sync', 'cards');
Assert::contains('Vybraný klíč: <code>cards</code>', $html);
Assert::contains('class="write"', $html);
Assert::contains('class="read"', $html);
