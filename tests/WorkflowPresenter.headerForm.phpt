<?php

declare(strict_types=1);

use Nette\Application\Responses\RedirectResponse;
use Nette\Utils\FileSystem;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/inc/workflowPresenter.php';

// I2: formulář hlavičky se sestavuje i při POSTu, který mu nepatří — dřív se
// v takovém případě překreslil prázdný a „Uložit" zapsalo description: null
// a inputs: []. Otázka nezní „je to POST?", ale „patří ten POST tomuhle
// formuláři?".

$project = TEMP_DIR . '/header';
FileSystem::createDir($project . '/blocks');
FileSystem::createDir($project . '/workflows');

FileSystem::write($project . '/workflows/w.json', json_encode([
	'name' => 'w',
	'description' => 'Duležitý popis',
	'inputs' => ['repo' => ['required' => true, 'description' => 'Repozitář']],
	'steps' => [['type' => 'set', 'key' => 'a', 'value' => '1']],
]));

// --- cizí POST (neúspěšné mazání) nesmí formulář hlavičky vyprázdnit ---

[$response, $html] = runWorkflowPresenterIn(
	$project,
	['action' => 'edit', 'name' => 'w', 'do' => 'deleteWorkflowForm-submit'],
	['name' => '', 'save' => 'Smazat'],
);

Assert::false($response instanceof RedirectResponse, 'mazání selhalo, stránka se překreslila');
Assert::contains('value="w"', $html, 'jméno drží setDefaultValue()');
Assert::contains('Duležitý popis', $html, 'popis se nesmí ztratit jen proto, že POST patřil jinému formuláři');
Assert::contains('value="repo"', $html, 'vstupy se nesmí ztratit');
Assert::contains('Repozitář', $html);

FileSystem::delete(TEMP_DIR);
