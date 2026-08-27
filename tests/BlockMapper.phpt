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

// --- round-trip over the reference workload ---
//
// Fifteen real blocks go through Block → values → Block. If the mapper
// dropped timeout, an input's default, or allow_failure, this would catch
// it. Compared via serialize(): type-exact and depth-proof, same as in the
// serializer's round-trip.

$parser = new BlockParser;
$blocks = \glob(__DIR__ . '/../../docs/workflows/donut/blocks/*.json');
Assert::count(15, $blocks === false ? [] : $blocks);

foreach ($blocks === false ? [] : $blocks as $path) {
	$original = $parser->parseFile($path);
	$again = $mapper->toBlock($mapper->toValues($original));

	Assert::same(
		\serialize($original),
		\serialize($again),
		'round-trip of block ' . \basename($path),
	);
}

// --- holes in the indexes get sorted out ---
//
// JS never renumbers rows: it adds an index one higher than the maximum,
// and deleting leaves a hole. Sorting them out is the mapper's job.

$withGap = $mapper->toBlock([
	'name' => 'gap',
	'description' => '',
	'command' => 'curl',
	'args' => [
		0 => [0 => '-sS', 2 => '--fail'],
		3 => [1 => '{%url%}'],
	],
	'inputs' => [
		1 => ['name' => 'url', 'required' => true, 'default' => '', 'description' => ''],
	],
	'stdin' => 'no',
	'stdinDescription' => '',
	'timeout' => '',
	'allowFailure' => 'none',
	'allowFailureCodes' => '',
]);

Assert::same(
	[['-sS', '--fail'], ['{%url%}']],
	\array_map(
		fn(array $g): array => \array_map(fn(Template $t): string => $t->getSource(), $g),
		$withGap->args,
	),
);
Assert::same(['url'], \array_keys($withGap->inputs));

// --- key order gets sorted out, even when POST sends it reversed ---
//
// The order of keys from POST isn't guaranteed; ksort() is the only thing
// holding the order of arguments and inputs together. The gap test above
// already has its keys given in ascending order, so without this case,
// dropping ksort() wouldn't fail anything.

$swapped = $mapper->toBlock([
	'name' => 'swapped',
	'description' => '',
	'command' => 'echo',
	'args' => [
		1 => [1 => 'second-b', 0 => 'second-a'],
		0 => [1 => 'first-b', 0 => 'first-a'],
	],
	'inputs' => [
		1 => ['name' => 'zulu', 'required' => true, 'default' => '', 'description' => ''],
		0 => ['name' => 'alpha', 'required' => true, 'default' => '', 'description' => ''],
	],
	'stdin' => 'no',
	'stdinDescription' => '',
	'timeout' => '',
	'allowFailure' => 'none',
	'allowFailureCodes' => '',
]);

Assert::same(
	[['first-a', 'first-b'], ['second-a', 'second-b']],
	\array_map(
		fn(array $g): array => \array_map(fn(Template $t): string => $t->getSource(), $g),
		$swapped->args,
	),
);
Assert::same(['alpha', 'zulu'], \array_keys($swapped->inputs));

// --- empty rows and empty groups drop out ---

$withEmpties = $mapper->toBlock([
	'name' => 'empty',
	'description' => '',
	'command' => 'echo',
	'args' => [
		0 => [0 => 'hello', 1 => ''],
		1 => [0 => '', 1 => ''],
		2 => [0 => 'world'],
	],
	'inputs' => [
		0 => ['name' => '', 'required' => true, 'default' => '', 'description' => 'nobody'],
		1 => ['name' => 'who', 'required' => true, 'default' => '', 'description' => ''],
	],
	'stdin' => 'no',
	'stdinDescription' => '',
	'timeout' => '',
	'allowFailure' => 'none',
	'allowFailureCodes' => '',
]);

// Group 1 was entirely empty — it disappeared, leaving no empty group behind.
Assert::count(2, $withEmpties->args);
Assert::same('hello', $withEmpties->args[0][0]->getSource());
Assert::count(1, $withEmpties->args[0]);
Assert::same('world', $withEmpties->args[1][0]->getSource());

// An input row without a name is dropped even with a description filled in.
Assert::same(['who'], \array_keys($withEmpties->inputs));

// --- '' means unfilled, not an empty string ---
// Format spec section 6: unfilled and "" are the same thing.

Assert::null($withEmpties->description);
Assert::null($withEmpties->timeout);
Assert::null($withEmpties->inputs['who']->default);
Assert::null($withEmpties->inputs['who']->description);

// --- stdin has three states, and they are the three the format has ---
//
// The file says stdin is absent, or an object with required false, or one
// with required true. One field with three options says exactly that; the
// two checkboxes it replaced could also say "not read, but required", which
// is nothing the file can hold.

$base = [
	'name' => 'x', 'description' => '', 'command' => 'cat',
	'args' => [], 'inputs' => [],
	'stdin' => 'optional', 'stdinDescription' => 'Body',
	'timeout' => '', 'allowFailure' => 'none', 'allowFailureCodes' => '',
];

$optional = $mapper->toBlock($base);
Assert::type(StdinSpec::class, $optional->stdin);
Assert::false($optional->stdin->required);
Assert::same('Body', $optional->stdin->description);

$required = $mapper->toBlock(['stdin' => 'required'] + $base);
Assert::type(StdinSpec::class, $required->stdin);
Assert::true($required->stdin->required);
Assert::same('Body', $required->stdin->description);

// "no" = no object, even if the description stayed filled in the form.
Assert::null($mapper->toBlock(['stdin' => 'no'] + $base)->stdin);

// Anything else is "no" too: the select offers exactly three values, so a
// fourth came from a hand-built POST.
Assert::null($mapper->toBlock(['stdin' => 'yes please'] + $base)->stdin);
Assert::null($mapper->toBlock(\array_diff_key($base, ['stdin' => null]))->stdin, 'no key at all');

// --- allow_failure has three states ---

Assert::false($mapper->toBlock($base)->allowFailure);
Assert::true($mapper->toBlock(['allowFailure' => 'any'] + $base)->allowFailure);
Assert::same(
	[0, 1],
	$mapper->toBlock(['allowFailure' => 'list', 'allowFailureCodes' => '0, 1'] + $base)->allowFailure,
);

// A non-numeric code is ignored — the form rejects it earlier, the mapper must not fail.
Assert::same(
	[2],
	$mapper->toBlock(['allowFailure' => 'list', 'allowFailureCodes' => '2, x, '] + $base)->allowFailure,
);

// An empty list under 'list' falls back to false — the parser would reject [].
Assert::false($mapper->toBlock(['allowFailure' => 'list', 'allowFailureCodes' => ''] + $base)->allowFailure);

// --- timeout converts to int ---

Assert::same(30, $mapper->toBlock(['timeout' => '30'] + $base)->timeout);

// --- toValues() gives the shape the form expects ---

$values = $mapper->toValues(new Block(
	name: 'full',
	command: 'curl',
	args: [[Template::parse('-sS'), Template::parse('--fail')]],
	inputs: ['url' => new Input(name: 'url', required: false, default: '/tmp/x', description: 'Address')],
	stdin: new StdinSpec(required: true, description: 'Body'),
	timeout: 30,
	allowFailure: [0, 1],
	description: 'Description',
));

Assert::same('full', $values['name']);
Assert::same('Description', $values['description']);
Assert::same([['-sS', '--fail']], $values['args']);
Assert::same(
	[['name' => 'url', 'required' => false, 'default' => '/tmp/x', 'description' => 'Address']],
	$values['inputs'],
);
Assert::same('required', $values['stdin']);
Assert::same('Body', $values['stdinDescription']);
Assert::same('30', $values['timeout']);
Assert::same('list', $values['allowFailure']);
Assert::same('0, 1', $values['allowFailureCodes']);

// Unfilled fields come out as '', not as null — the form wants strings.
$bare = $mapper->toValues(new Block(name: 'bare', command: 'echo', args: []));
Assert::same('', $bare['description']);
Assert::same('', $bare['timeout']);
// A block that does not read stdin opens as "no". It used to open as "does
// not read stdin" AND "stdin is required" at once — the required flag fell
// back to StdinSpec's default of true even though there was no spec — which
// said something the file could not mean, and was silently discarded on
// save.
Assert::same('no', $bare['stdin']);
Assert::same('', $bare['stdinDescription']);
Assert::same('none', $bare['allowFailure']);
