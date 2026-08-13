<?php

declare(strict_types=1);

use Donut\Format\Block;
use Donut\Format\Input;
use Donut\Format\StdinSpec;
use Donut\Gui\BlockMapper;
use Donut\Parser\BlockParser;
use Donut\Template;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

$mapper = new BlockMapper;

// --- round-trip nad referenční zátěží ---
//
// Patnáct skutečných kamenů projde Block → values → Block. Kdyby mapper
// zahodil timeout, default u vstupu nebo allow_failure, tohle to chytí.
// Porovnává se přes serialize(): typově přesné a odolné vůči hloubce,
// stejně jako v round-tripu serializéru.

$parser = new BlockParser;
$blocks = \glob(__DIR__ . '/../../docs/workflows/donut/blocks/*.json');
Assert::count(15, $blocks === false ? [] : $blocks);

foreach ($blocks === false ? [] : $blocks as $path) {
	$puvodni = $parser->parseFile($path);
	$znovu = $mapper->toBlock($mapper->toValues($puvodni));

	Assert::same(
		\serialize($puvodni),
		\serialize($znovu),
		'round-trip kamene ' . \basename($path),
	);
}

// --- díry v indexech se srovnají ---
//
// JS řádky nikdy nepřečísluje: přidá index o jedna vyšší než maximum
// a smazání nechá díru. Srovnání je úkol mapperu.

$sDirou = $mapper->toBlock([
	'name' => 'dira',
	'description' => '',
	'command' => 'curl',
	'args' => [
		0 => [0 => '-sS', 2 => '--fail'],
		3 => [1 => '{%url%}'],
	],
	'inputs' => [
		1 => ['name' => 'url', 'required' => true, 'default' => '', 'description' => ''],
	],
	'hasStdin' => false,
	'stdinRequired' => false,
	'stdinDescription' => '',
	'timeout' => '',
	'allowFailure' => 'none',
	'allowFailureCodes' => '',
]);

Assert::same(
	[['-sS', '--fail'], ['{%url%}']],
	\array_map(
		fn(array $g): array => \array_map(fn(Template $t): string => $t->getSource(), $g),
		$sDirou->args,
	),
);
Assert::same(['url'], \array_keys($sDirou->inputs));

// --- prázdné řádky a prázdné skupiny vypadnou ---

$sPrazdnymi = $mapper->toBlock([
	'name' => 'prazdne',
	'description' => '',
	'command' => 'echo',
	'args' => [
		0 => [0 => 'ahoj', 1 => ''],
		1 => [0 => '', 1 => ''],
		2 => [0 => 'svete'],
	],
	'inputs' => [
		0 => ['name' => '', 'required' => true, 'default' => '', 'description' => 'nikdo'],
		1 => ['name' => 'kdo', 'required' => true, 'default' => '', 'description' => ''],
	],
	'hasStdin' => false,
	'stdinRequired' => false,
	'stdinDescription' => '',
	'timeout' => '',
	'allowFailure' => 'none',
	'allowFailureCodes' => '',
]);

// Skupina 1 byla celá prázdná — zmizela, nezůstala po ní prázdná skupina.
Assert::count(2, $sPrazdnymi->args);
Assert::same('ahoj', $sPrazdnymi->args[0][0]->getSource());
Assert::count(1, $sPrazdnymi->args[0]);
Assert::same('svete', $sPrazdnymi->args[1][0]->getSource());

// Řádek vstupu bez jména se zahodí i s vyplněným popisem.
Assert::same(['kdo'], \array_keys($sPrazdnymi->inputs));

// --- '' znamená nevyplněno, ne prázdný řetězec ---
// Sekce 6 specifikace formátu: nevyplněno a "" je totéž.

Assert::null($sPrazdnymi->description);
Assert::null($sPrazdnymi->timeout);
Assert::null($sPrazdnymi->inputs['kdo']->default);
Assert::null($sPrazdnymi->inputs['kdo']->description);

// --- stdin se objeví a zmizí podle zaškrtávátka ---

$zaklad = [
	'name' => 'x', 'description' => '', 'command' => 'cat',
	'args' => [], 'inputs' => [],
	'hasStdin' => true, 'stdinRequired' => false, 'stdinDescription' => 'Tělo',
	'timeout' => '', 'allowFailure' => 'none', 'allowFailureCodes' => '',
];

$sStdin = $mapper->toBlock($zaklad);
Assert::type(StdinSpec::class, $sStdin->stdin);
Assert::false($sStdin->stdin->required);
Assert::same('Tělo', $sStdin->stdin->description);

// Nezaškrtnuté = objekt není, i když popis zůstal vyplněný ve formuláři.
Assert::null($mapper->toBlock(['hasStdin' => false] + $zaklad)->stdin);

// --- allow_failure má tři stavy ---

Assert::false($mapper->toBlock($zaklad)->allowFailure);
Assert::true($mapper->toBlock(['allowFailure' => 'any'] + $zaklad)->allowFailure);
Assert::same(
	[0, 1],
	$mapper->toBlock(['allowFailure' => 'list', 'allowFailureCodes' => '0, 1'] + $zaklad)->allowFailure,
);

// Nečíselný kód se ignoruje — formulář ho odmítne dřív, mapper nesmí spadnout.
Assert::same(
	[2],
	$mapper->toBlock(['allowFailure' => 'list', 'allowFailureCodes' => '2, x, '] + $zaklad)->allowFailure,
);

// Prázdný seznam u 'list' spadne zpátky na false — pole [] by parser odmítl.
Assert::false($mapper->toBlock(['allowFailure' => 'list', 'allowFailureCodes' => ''] + $zaklad)->allowFailure);

// --- timeout se převede na int ---

Assert::same(30, $mapper->toBlock(['timeout' => '30'] + $zaklad)->timeout);

// --- toValues() dává tvar, který formulář očekává ---

$values = $mapper->toValues(new Block(
	name: 'plny',
	command: 'curl',
	args: [[Template::parse('-sS'), Template::parse('--fail')]],
	inputs: ['url' => new Input(name: 'url', required: false, default: '/tmp/x', description: 'Adresa')],
	stdin: new StdinSpec(required: true, description: 'Tělo'),
	timeout: 30,
	allowFailure: [0, 1],
	description: 'Popis',
));

Assert::same('plny', $values['name']);
Assert::same('Popis', $values['description']);
Assert::same([['-sS', '--fail']], $values['args']);
Assert::same(
	[['name' => 'url', 'required' => false, 'default' => '/tmp/x', 'description' => 'Adresa']],
	$values['inputs'],
);
Assert::true($values['hasStdin']);
Assert::true($values['stdinRequired']);
Assert::same('Tělo', $values['stdinDescription']);
Assert::same('30', $values['timeout']);
Assert::same('list', $values['allowFailure']);
Assert::same('0, 1', $values['allowFailureCodes']);

// Nevyplněná pole vyjdou jako '', ne jako null — formulář chce řetězce.
$holy = $mapper->toValues(new Block(name: 'holy', command: 'echo', args: []));
Assert::same('', $holy['description']);
Assert::same('', $holy['timeout']);
Assert::false($holy['hasStdin']);
Assert::same('none', $holy['allowFailure']);
