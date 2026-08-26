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

// C1: vadný kámen nebo chybějící blocks/ nesmí shodit renderDetail() na
// neošetřenou výjimku — nejhorší chvíle na to, je zrovna když má stránka
// ukázat, co je rozbité.

/**
 * Presenter mimo DI kontejner potřebuje $template a injectPrimary() ručně,
 * jinak Presenter::getTemplateFactory() vyhodí "Service TemplateFactory has
 * not been set."
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
 * renderDetail() čte profil, fixtura se mu tedy předává jako Profile.
 */
function renderDetailIn(string $dir): WorkflowPresenter
{
	$presenter = createPresenter(new Profile(basename($dir), $dir));
	Assert::noError(fn() => $presenter->renderDetail('w'));

	return $presenter;
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

// N2: stránka se vykreslí celá, ale bez jediného nálezu validátoru — musí
// být poznat, že se nevalidovalo, ne že je všechno v pořádku.
Assert::contains('Validation did not run', $presenter->template->error);

// N3: detail workflow je po založení první místo, kam se čerstvý uživatel
// dostane. Bez návodu je prázdný projekt slepá ulička — přehled kamenů ho má,
// tady chyběl.
Assert::contains('mkdir -p ' . $dir . '/blocks', $presenter->template->error);


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

// N2: rozbitý soubor kamene shodí validátor uprostřed práce, takže prázdný
// Result neznamená „nic k hlášení", ale „nevalidovalo se". Kdykoli je v
// blocks/ rozbitý JSON, mizí i skutečné nálezy (třeba „kámen neexistuje") —
// stránka to musí přiznat, jinak vypadá zvalidovaně.
Assert::contains('Validation did not run', $presenter->template->error);

// Adresář blocks/ tady existuje, takže rada `mkdir blocks` by byla nesmysl.
Assert::notContains('mkdir', $presenter->template->error);

// A pro pořádek: nálezy validátoru jsou opravdu pryč, hláška je jediné, co
// o problému na stránce zbývá.
Assert::same([], $presenter->template->workflowProblems);
