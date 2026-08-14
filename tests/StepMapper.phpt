<?php

declare(strict_types=1);

use Donut\Format\Condition;
use Donut\Format\ForeachStep;
use Donut\Format\IfStep;
use Donut\Format\RunStep;
use Donut\Format\SetStep;
use Donut\Gui\StepMapper;
use Donut\Parser\WorkflowParser;
use Donut\Template;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

// --- round-trip nad referenční zátěží ---
//
// 96 kroků: 75 run, 9 set, 5 if, 7 foreach. Kdyby mapper zahodil timeout,
// allow_failure nebo name, tohle to odhalí.

$parser = new WorkflowParser;
$files = \glob(__DIR__ . '/../../docs/workflows/donut/workflows/*.json');
Assert::count(4, $files === false ? [] : $files);

$checked = 0;

// IfStep a ForeachStep nesou vnořené kroky, které toValues() do hodnot
// formuláře nedává — stránka kroku větve needituje, ty se plní z přehledu.
// toStep() proto vrací krok s prázdnými větvemi a round-trip se u nich musí
// porovnávat proti kroku zbavenému dětí.
$bare = function (Donut\Format\Step $step): Donut\Format\Step {
	if ($step instanceof IfStep) {
		return new IfStep($step->condition, [], [], $step->name);
	}

	if ($step instanceof ForeachStep) {
		return new ForeachStep($step->over, $step->as, [], $step->name);
	}

	return $step;
};

$walk = function (array $steps) use (&$walk, &$checked, $bare): void {
	foreach ($steps as $step) {
		$checked++;

		Assert::same(
			\serialize($bare($step)),
			\serialize(StepMapper::toStep(StepMapper::toValues($step))),
			'round-trip ' . $step::class,
		);

		if ($step instanceof IfStep) {
			$walk($step->then);
			$walk($step->else);

		} elseif ($step instanceof ForeachStep) {
			$walk($step->steps);
		}
	}
};

foreach ($files === false ? [] : $files as $file) {
	$walk($parser->parseFile($file)->steps);
}

Assert::same(96, $checked, 'referenční zátěž má 96 kroků');

// --- run: všechna volitelná pole ---
$run = StepMapper::toStep([
	'type' => 'run',
	'name' => 'pojmenovaný',
	'block' => 'jq',
	// Díry v indexech schválně — JS řádky nepřečísluje.
	'in' => [
		0 => ['key' => 'stdin', 'value' => '{%payload%}'],
		2 => ['key' => 'filter', 'value' => '.id'],
	],
	'out' => [1 => ['channel' => 'result', 'value' => 'cardId']],
	'timeout' => '90',
	'allowFailure' => 'list',
	'allowFailureCodes' => '0, 1',
]);

Assert::type(RunStep::class, $run);
Assert::same('jq', $run->block);
Assert::same('pojmenovaný', $run->name);
Assert::same(['stdin', 'filter'], \array_keys($run->in));
Assert::same('{%payload%}', $run->in['stdin']->getSource());
Assert::same(['result' => 'cardId'], $run->out);
Assert::same(90, $run->timeout);
Assert::same([0, 1], $run->allowFailure);

// --- run: čtyři stavy allow_failure ---

$base = ['type' => 'run', 'name' => '', 'block' => 'echo', 'in' => [], 'out' => [],
	'timeout' => '', 'allowFailure' => 'inherit', 'allowFailureCodes' => ''];

Assert::null(StepMapper::toStep($base)->allowFailure);
Assert::false(StepMapper::toStep(['allowFailure' => 'none'] + $base)->allowFailure);
Assert::true(StepMapper::toStep(['allowFailure' => 'any'] + $base)->allowFailure);
Assert::same([2], StepMapper::toStep(['allowFailure' => 'list', 'allowFailureCodes' => '2, x'] + $base)->allowFailure);

// Prázdný výčet u 'list' spadne na inherit — pole [] by parser odmítl.
Assert::null(StepMapper::toStep(['allowFailure' => 'list'] + $base)->allowFailure);

// --- prázdné řádky vypadnou ---

$sPrazdnymi = StepMapper::toStep([
	// Řádek 2: klíč vyplněný, hodnota ne — na rozdíl od out (viz níž) se
	// nezahazuje, jen dostane prázdnou šablonu. Klíč bez hodnoty je platný
	// vstup, sekce 6 specifikace: prázdný řetězec a nevyplněno je totéž.
	'in' => [0 => ['key' => '', 'value' => 'nikam'], 1 => ['key' => 'a', 'value' => 'x'], 2 => ['key' => 'b', 'value' => '']],
	'out' => [0 => ['channel' => 'result', 'value' => '']],
] + $base);

Assert::same(['a', 'b'], \array_keys($sPrazdnymi->in));
Assert::same('', $sPrazdnymi->in['b']->getSource(), 'vyplněný klíč s prázdnou hodnotou se nezahazuje');
Assert::same([], $sPrazdnymi->out);

// --- pořadí klíčů z POSTu není zaručené, na pořadí in i out záleží ---
//
// Bez ksort() v rows() by se sestupné pořadí klíčů projevilo obráceným
// pořadím řádků — testovaná díra v indexech jinde v souboru je vzestupná,
// takže by mutaci neodhalila.

$reversed = StepMapper::toStep([
	'in' => [
		1 => ['key' => 'druhy', 'value' => 'b'],
		0 => ['key' => 'prvni', 'value' => 'a'],
	],
	'out' => [
		1 => ['channel' => 'stderr', 'value' => 'err'],
		0 => ['channel' => 'result', 'value' => 'res'],
	],
] + $base);

Assert::same(['prvni', 'druhy'], \array_keys($reversed->in));
Assert::same(['result' => 'res', 'stderr' => 'err'], $reversed->out);

// --- '' znamená nevyplněno ---

Assert::null(StepMapper::toStep($base)->name);
Assert::null(StepMapper::toStep($base)->timeout);

// --- set ---

$set = StepMapper::toStep(['type' => 'set', 'name' => 'jméno', 'key' => 'branch', 'value' => 'f/{%id%}']);
Assert::type(SetStep::class, $set);
Assert::same('branch', $set->key);
Assert::same('f/{%id%}', $set->value->getSource());
Assert::same('jméno', $set->name);

// --- if, včetně unárního operátoru ---

$if = StepMapper::toStep(['type' => 'if', 'name' => '', 'left' => '{%a%}', 'op' => 'eq', 'right' => '{%b%}']);
Assert::type(IfStep::class, $if);
Assert::same('{%b%}', $if->condition->right?->getSource());
Assert::same([], $if->then);
Assert::same([], $if->else);

// U unárních operátorů se pravá strana zahodí, i když ve formuláři něco zůstalo.
$unarni = StepMapper::toStep(['type' => 'if', 'name' => '', 'left' => '{%a%}', 'op' => 'not_empty', 'right' => 'zbytek']);
Assert::null($unarni->condition->right);

// --- foreach ---

$foreach = StepMapper::toStep(['type' => 'foreach', 'name' => '', 'over' => '{%cards%}', 'as' => 'card']);
Assert::type(ForeachStep::class, $foreach);
Assert::same('{%cards%}', $foreach->over->getSource());
Assert::same('card', $foreach->as);
Assert::same([], $foreach->steps);

// --- neznámý typ ---

Assert::exception(
	fn() => StepMapper::toStep(['type' => 'nesmysl']),
	InvalidArgumentException::class,
);

// --- toValues dává tvar, který formulář očekává ---

$values = StepMapper::toValues(new RunStep(
	block: 'jq',
	in: ['stdin' => Template::parse('{%p%}')],
	out: ['result' => 'id'],
	timeout: 30,
	allowFailure: [0, 1],
	name: 'jméno',
));

Assert::same('run', $values['type']);
Assert::same('jq', $values['block']);
Assert::same([['key' => 'stdin', 'value' => '{%p%}']], $values['in']);
Assert::same([['channel' => 'result', 'value' => 'id']], $values['out']);
Assert::same('30', $values['timeout']);
Assert::same('list', $values['allowFailure']);
Assert::same('0, 1', $values['allowFailureCodes']);

// Nevyplněná pole vyjdou jako '' a inherit, ne jako null.
$holy = StepMapper::toValues(new RunStep(block: 'echo'));
Assert::same('', $holy['name']);
Assert::same('', $holy['timeout']);
Assert::same('inherit', $holy['allowFailure']);
