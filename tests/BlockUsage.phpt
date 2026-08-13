<?php

declare(strict_types=1);

use Donut\Format\Condition;
use Donut\Format\ForeachStep;
use Donut\Format\IfStep;
use Donut\Format\RunStep;
use Donut\Format\SetStep;
use Donut\Format\Workflow;
use Donut\Gui\BlockUsage;
use Donut\Template;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

// Kameny schované ve všech třech úrovních vnoření: přímo v steps,
// ve větvi then, ve větvi else a uvnitř foreach.
$w1 = new Workflow(name: 'prvni', steps: [
	new RunStep(block: 'echo'),
	new IfStep(
		condition: new Condition(left: Template::parse('{%a%}'), op: 'not_empty'),
		then: [new RunStep(block: 'jq')],
		else: [new ForeachStep(
			over: Template::parse('{%seznam%}'),
			as: 'radek',
			steps: [new RunStep(block: 'curl-get')],
		)],
	),
	new SetStep(key: 'x', value: Template::parse('1')),
]);

$w2 = new Workflow(name: 'druhe', steps: [
	new RunStep(block: 'echo'),
	new RunStep(block: 'echo'),
]);

$usage = BlockUsage::of(['prvni' => $w1, 'druhe' => $w2]);

// Kámen v obou workflow je uvedený jednou za každé, ne za každý krok.
Assert::same(['druhe', 'prvni'], $usage['echo']);

// Kámen ve vnořené větvi se najde.
Assert::same(['prvni'], $usage['jq']);
Assert::same(['prvni'], $usage['curl-get']);

// Vnější mapa je seřazená podle jména kamene, ne podle pořadí objevení.
Assert::same(['curl-get', 'echo', 'jq'], \array_keys($usage));

// Nepoužitý kámen v mapě vůbec není.
Assert::false(\array_key_exists('fail', $usage));

// Prázdný vstup dá prázdnou mapu, ne chybu.
Assert::same([], BlockUsage::of([]));

// Workflow bez jediného kroku run taky.
Assert::same([], BlockUsage::of(['x' => new Workflow(name: 'x')]));

// Jméno kamene i workflow smí být čistě číselné (žádný formát to
// nezakazuje) — PHP by takový klíč pole tiše převedlo na int. Hodnoty
// uvnitř vnitřního seznamu musí zůstat stringy i pro tenhle vstup.
$cisla = BlockUsage::of(['123' => new Workflow(name: '123', steps: [new RunStep(block: '456')])]);
Assert::same(['123'], $cisla['456']);
