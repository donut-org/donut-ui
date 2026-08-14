<?php

declare(strict_types=1);

use Donut\Gui\StepPath;
use Donut\Parser\WorkflowParser;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

// --- parse a zpátky dá tentýž řetězec ---

foreach ([
	'card-dev.json:steps[0]',
	'card-dev.json:steps[7].then[0]',
	'sync.json:steps[3].steps[1].steps[0]',
	'x.json:steps[2].else[10]',
] as $path) {
	Assert::same($path, (string) StepPath::parse($path), $path);
}

// --- segments ---

Assert::same(
	[['steps', 7], ['then', 0]],
	StepPath::parse('card-dev.json:steps[7].then[0]')->segments(),
);

Assert::same([['steps', 0]], StepPath::parse('card-dev.json:steps[0]')->segments());

// --- jméno workflow ---

Assert::same('card-dev', StepPath::parse('card-dev.json:steps[7].then[0]')->workflowName());
Assert::same('card-dev', StepPath::workflow('card-dev')->workflowName());

// Cesta na celé workflow nemá žádný krok.
Assert::same([], StepPath::workflow('card-dev')->segments());

// --- co cestou ke kroku není ---

foreach ([
	'card-dev.json',              // celé workflow, ne krok
	'card-dev.json:steps',        // seznam, ne krok
	'card-dev.json:steps[7].then', // taky seznam
	'card-dev.json:steps[]',
	'card-dev.json:steps[a]',
	'card-dev.json:kroky[0]',
	'steps[0]',
	'',
	'card-dev.json:steps[0];rm -rf /',
	"card-dev.json:steps[0]\n", // $ v PCRE povolí koncový \n, chceme \z
	' card-dev.json:steps[0]',  // obklopující mezera by se dostala do jména
] as $bad) {
	Assert::exception(
		fn() => StepPath::parse($bad),
		InvalidArgumentException::class,
		"\"{$bad}\" není cesta ke kroku.",
	);
}

// --- cesty, které skládá šablona, musí jít rozebrat ---
//
// Pojistka proti tomu, že by se skládání a rozebírání rozešlo: projdi strom
// všech čtyř skutečných workflow, slož cestu tak, jak to dělá steps.latte,
// a ověř, že ji parse() přijme a segments() vrátí totéž, z čeho vznikla.

$parser = new WorkflowParser;
$files = \glob(__DIR__ . '/../../docs/workflows/donut/workflows/*.json');
Assert::count(4, $files === false ? [] : $files);

$checked = 0;

$walk = function (array $steps, StepPath $path, array $segments) use (&$walk, &$checked): void {
	foreach ($steps as $i => $step) {
		$at = $path->index($i);
		$here = [...$segments];
		$here[\count($here) - 1][1] = $i;

		Assert::same((string) $at, (string) StepPath::parse((string) $at));
		Assert::same($here, $at->segments(), (string) $at);
		$checked++;

		if ($step instanceof Donut\Format\IfStep) {
			$walk($step->then, $at->child('then'), [...$here, ['then', 0]]);
			$walk($step->else, $at->child('else'), [...$here, ['else', 0]]);

		} elseif ($step instanceof Donut\Format\ForeachStep) {
			$walk($step->steps, $at->child('steps'), [...$here, ['steps', 0]]);
		}
	}
};

foreach ($files === false ? [] : $files as $file) {
	$workflow = $parser->parseFile($file);
	$walk($workflow->steps, StepPath::root($workflow->name), [['steps', 0]]);
}

Assert::same(96, $checked, 'referenční zátěž má 96 kroků');
