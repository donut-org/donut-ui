<?php

declare(strict_types=1);

use Donut\Format\ForeachStep;
use Donut\Format\IfStep;
use Donut\Format\SetStep;
use Donut\Format\Workflow;
use Donut\Gui\StepPath;
use Donut\Gui\StepTree;
use Donut\Parser\WorkflowParser;
use Donut\Template;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

$parser = new WorkflowParser;
$files = \glob(__DIR__ . '/../../docs/workflows/donut/workflows/*.json');
Assert::count(4, $files === false ? [] : $files);

/** @return list<StepPath> */
$allPaths = function (Workflow $workflow): array {
	$paths = [];

	$walk = function (array $steps, StepPath $path) use (&$walk, &$paths): void {
		foreach ($steps as $i => $step) {
			$at = $path->index($i);
			$paths[] = $at;

			if ($step instanceof IfStep) {
				$walk($step->then, $at->child('then'));
				$walk($step->else, $at->child('else'));

			} elseif ($step instanceof ForeachStep) {
				$walk($step->steps, $at->child('steps'));
			}
		}
	};

	$walk($workflow->steps, StepPath::root($workflow->name));

	return $paths;
};

// --- invarianty nad celou referenční zátěží ---
//
// Ruční případy by pokryly pár tvarů; tohle pokryje 96 kroků do hloubky 3
// naráz. Kdyby se get() a replace() rozešly o jeden index nebo si spletly
// větev, spadne to hned na prvním workflow.

$checked = 0;

foreach ($files === false ? [] : $files as $file) {
	$workflow = $parser->parseFile($file);
	$before = \serialize($workflow);

	foreach ($allPaths($workflow) as $at) {
		$checked++;

		// get + replace míří na tentýž uzel
		Assert::same(
			$before,
			\serialize(StepTree::replace($workflow, $at, StepTree::get($workflow, $at))),
			"replace(get) na {$at}",
		);

		// remove a insert zpátky vrátí původní strom
		Assert::same(
			$before,
			\serialize(StepTree::insert(
				StepTree::remove($workflow, $at),
				$at,
				StepTree::get($workflow, $at),
			)),
			"remove+insert na {$at}",
		);

		// moveUp a moveDown jsou involuce — swap dvakrát na stejné pozici
		// vrátí původní strom. (Kombinace moveDown+moveUp na stejné cestě
		// to nezaručuje: cesta míří na pozici, ne na krok, takže druhý swap
		// už míří na jiného souseda, než odkud první swap krok odsunul.)
		Assert::same(
			$before,
			\serialize(StepTree::moveDown(StepTree::moveDown($workflow, $at), $at)),
			"moveDown dvakrát na {$at}",
		);
		Assert::same(
			$before,
			\serialize(StepTree::moveUp(StepTree::moveUp($workflow, $at), $at)),
			"moveUp dvakrát na {$at}",
		);
	}
}

Assert::same(96, $checked, 'referenční zátěž má 96 kroků');

// --- konkrétní chování na malém stromě ---

$set = fn(string $key): SetStep => new SetStep(key: $key, value: Template::parse('x'));

$workflow = new Workflow(name: 'w', steps: [
	$set('a'),
	new IfStep(
		condition: new Donut\Format\Condition(left: Template::parse('{%x%}'), op: 'not_empty'),
		then: [$set('t1'), $set('t2')],
	),
	$set('b'),
]);

$keys = function (Workflow $w): array {
	return \array_map(
		fn($s): string => $s instanceof SetStep ? $s->key : 'if',
		$w->steps,
	);
};

// moveUp prohodí se sousedem
Assert::same(['if', 'a', 'b'], $keys(StepTree::moveUp($workflow, StepPath::parse('w.json:steps[1]'))));

// na kraji je to no-op, ne chyba — šablona šipku nevykreslí, ale ručně
// poslaný POST nesmí spadnout
Assert::same(['a', 'if', 'b'], $keys(StepTree::moveUp($workflow, StepPath::parse('w.json:steps[0]'))));
Assert::same(['a', 'if', 'b'], $keys(StepTree::moveDown($workflow, StepPath::parse('w.json:steps[2]'))));

// insert doprostřed posune ostatní
Assert::same(
	['a', 'novy', 'if', 'b'],
	$keys(StepTree::insert($workflow, StepPath::parse('w.json:steps[1]'), $set('novy'))),
);

// insert na konec
Assert::same(
	['a', 'if', 'b', 'novy'],
	$keys(StepTree::insert($workflow, StepPath::parse('w.json:steps[3]'), $set('novy'))),
);

// remove uzavře díru
Assert::same(['a', 'b'], $keys(StepTree::remove($workflow, StepPath::parse('w.json:steps[1]'))));

// smazání if vezme celou větev s sebou
Assert::count(2, StepTree::remove($workflow, StepPath::parse('w.json:steps[1]'))->steps);

// --- práce uvnitř větve ---

$vetev = StepTree::insert($workflow, StepPath::parse('w.json:steps[1].then[0]'), $set('t0'));
$if = $vetev->steps[1];
Assert::type(IfStep::class, $if);
Assert::same(['t0', 't1', 't2'], \array_map(fn($s): string => $s->key, $if->then));

// prázdná větev else — vložení do ní je jediná cesta, jak ji naplnit
$doElse = StepTree::insert($workflow, StepPath::parse('w.json:steps[1].else[0]'), $set('e0'));
Assert::same(['e0'], \array_map(fn($s): string => $s->key, $doElse->steps[1]->else));

// --- neplatné cesty ---

Assert::exception(
	fn() => StepTree::get($workflow, StepPath::parse('w.json:steps[9]')),
	OutOfRangeException::class,
);

// sestup do větve u kroku, který ji nemá
Assert::exception(
	fn() => StepTree::get($workflow, StepPath::parse('w.json:steps[0].then[0]')),
	OutOfRangeException::class,
);

// insert za konec seznamu je v pořádku, dál už ne
Assert::exception(
	fn() => StepTree::insert($workflow, StepPath::parse('w.json:steps[4]'), $set('x')),
	OutOfRangeException::class,
);

// --- hlavička workflow zůstane netknutá ---

$sHlavickou = new Workflow(
	name: 'w',
	inputs: ['a' => new Donut\Format\Input(name: 'a')],
	steps: [$set('a')],
	description: 'Popis',
);

$po = StepTree::remove($sHlavickou, StepPath::parse('w.json:steps[0]'));
Assert::same('w', $po->name);
Assert::same('Popis', $po->description);
Assert::same(['a'], \array_keys($po->inputs));
Assert::same([], $po->steps);
