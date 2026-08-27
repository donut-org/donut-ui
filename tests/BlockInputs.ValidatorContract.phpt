<?php

declare(strict_types=1);

use Donut\BlockRepository;
use Donut\Format\RunStep;
use Donut\Format\Workflow;
use Donut\Gui\BlockInputs;
use Donut\Validator\Validator;
use Nette\Utils\FileSystem;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

// A slot marked required is exactly an input the validator reports as
// unfilled when the step leaves it out. BlockInputs copies the rule from
// Validator::checkRunStep(); this test is what keeps the copy honest.

$dir = TEMP_DIR . '/blocks';
FileSystem::createDir($dir);
FileSystem::write($dir . '/jq.json', json_encode([
	'name' => 'jq',
	'command' => 'jq',
	'args' => [['{%filter%}'], ['{%compact%}']],
	'inputs' => [
		'filter' => ['required' => true],
		'compact' => ['required' => true, 'default' => '-c'],
		'flags' => ['required' => false],
	],
	'stdin' => ['required' => true],
]));

$blocks = new BlockRepository($dir);
$block = $blocks->get('jq');

// A step that fills in nothing — every unfilled input the validator can
// complain about, it complains about here.
$workflow = new Workflow(name: 'w', steps: [new RunStep(block: 'jq')]);
$problems = (new Validator($blocks))->validate($workflow)->getErrors();

$reported = [];

foreach ($problems as $problem) {
	if (preg_match('~required input "([^"]+)"~', $problem->message, $m) === 1) {
		$reported[$m[1]] = true;
	}

	if (str_contains($problem->message, 'requires stdin')) {
		$reported['stdin'] = true;
	}
}

$required = [];

foreach (BlockInputs::slots($block, null) as $slot) {
	if ($slot->required) {
		$required[$slot->name] = true;
	}
}

ksort($reported);
ksort($required);

Assert::same(['filter' => true, 'stdin' => true], $reported, 'the validator complains about exactly these');
Assert::same($reported, $required, 'and the form marks exactly those as required');

FileSystem::delete(TEMP_DIR);
