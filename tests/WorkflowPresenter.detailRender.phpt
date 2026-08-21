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
Assert::contains('class="card step write"', $html);
Assert::contains('class="card step read"', $html);

$html = renderDetailIn($root, 'sync', 'cards');
Assert::contains('Vybraný klíč: <code>cards</code>', $html);
Assert::contains('class="card step write"', $html);
Assert::contains('class="card step loop read"', $html, 'cards čte jen foreach nad {%cards%}, takže jeho bublina nese i loop');

// --- strom kroků je řetěz bublin a podbarvení sedí na bublině, ne na uzlu ---
// Kdyby třída sedla na .node, přeteklo by pozadí na celé tělo
// then/else/foreach. Tohle je jediná vlastnost projektu, která se dá rozbít
// tiše — vypadalo by to jen „nějak divně".

Assert::contains('class="chain"', $html);
Assert::match('~<div class="node">\s*<div class="card step~', $html);

// A teď to podstatné: podbarvení sedí na bublině, ne na tělu cyklu.
// Aserce níž by byla vakuová, kdyby žádný krok podbarvený nebyl —
// proto se stránka renderuje s vybraným klíčem a nejdřív se ověří,
// že se vůbec něco podbarvilo.
Assert::match('~<div class="card step [^"]*\b(write|read)\b~', $html, 'aspoň jedna bublina musí být zvýrazněná, jinak aserce níž nic netvrdí');
Assert::notMatch('~<div class="loop-body[^"]*\b(write|read)\b~', $html, 'zvýraznění nesmí sednout na tělo cyklu — tvrdilo by, že je vybraný celý podstrom');

// --- M7: klíč, který ve workflow není ---

$html = renderDetailIn($root, 'card-dev', 'nesmysl');
Assert::contains('Vybraný klíč: <code>nesmysl</code>', $html);
Assert::contains('tento klíč se ve workflow nevyskytuje', $html);

// --- if má dvě větve i s prázdným else ---
// card-dev má dvě podmínky a obě mají prázdný else. Kdyby se prázdná větev
// nevykreslila, nešlo by do else nic přidat — a poznalo by se to až tím, že
// uživateli chybí odkaz, ne pádem testu.

$html = renderDetailIn($root, 'card-dev');
Assert::same(4, substr_count($html, '<div class="branch">'), 'dvě podmínky × dvě větve');

$pos = strpos($html, '>else</span>');
Assert::type('int', $pos, 'bez popisku větve by aserce níž nic netvrdila');
$vetev = substr($html, $pos, 400);
Assert::contains('class="add"', $vetev, 'prázdná větev else musí nabízet „+ krok"');
Assert::notContains('class="card step', $vetev, 'prázdná větev nesmí obsahovat bublinu');
