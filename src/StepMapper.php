<?php

declare(strict_types=1);

namespace Donut\Gui;

use Donut\Format\Condition;
use Donut\Format\ForeachStep;
use Donut\Format\IfStep;
use Donut\Format\RunStep;
use Donut\Format\SetStep;
use Donut\Format\Step;
use Donut\Template;


/**
 * Form values ↔ Step. A pure conversion, knows nothing of Nette or HTTP.
 *
 * Nested steps (then, else, foreach.steps) aren't carried over — the step
 * page doesn't edit branches, those are filled in from the overview. That's
 * why toStep() returns an if or foreach step with empty branches, and the
 * caller fills them in itself.
 *
 * Indexes in in and out can have holes — JS never renumbers rows. The
 * sorting happens here, same as in BlockMapper.
 */
final class StepMapper
{
	/**
	 * @param  array<string, mixed> $values
	 * @throws \InvalidArgumentException on an unknown step type
	 */
	public static function toStep(array $values): Step
	{
		$name = self::orNull($values['name'] ?? '');

		return match (self::text($values['type'] ?? '')) {
			'run' => new RunStep(
				block: self::text($values['block'] ?? ''),
				in: self::toIn($values['in'] ?? []),
				out: self::toOut($values['out'] ?? []),
				timeout: ($t = self::text($values['timeout'] ?? '')) === '' ? null : (int) $t,
				allowFailure: self::toAllowFailure($values),
				name: $name,
			),

			'set' => new SetStep(
				key: self::text($values['key'] ?? ''),
				value: Template::parse(self::text($values['value'] ?? '')),
				name: $name,
			),

			'if' => new IfStep(
				condition: self::toCondition($values),
				name: $name,
			),

			'foreach' => new ForeachStep(
				over: Template::parse(self::text($values['over'] ?? '')),
				as: self::text($values['as'] ?? ''),
				name: $name,
			),

			default => throw new \InvalidArgumentException(
				'Unknown step type "' . self::text($values['type'] ?? '') . '".'
			),
		};
	}


	/**
	 * @return array<string, mixed>
	 */
	public static function toValues(Step $step): array
	{
		if ($step instanceof RunStep) {
			$out = [];

			foreach ($step->out as $channel => $value) {
				$out[] = ['channel' => $channel, 'value' => $value];
			}

			// `in` is not returned: the values of the inputs travel with the
			// slots (BlockInputs::slots()), and the container they belong to
			// is keyed by position, not by the shape this method used to
			// produce. Nette ignores keys it has no control for, so leaving
			// it here would fail silently rather than loudly.
			return [
				'type' => 'run',
				'name' => $step->name ?? '',
				'block' => $step->block,
				'out' => $out,
				'timeout' => $step->timeout === null ? '' : (string) $step->timeout,
				'allowFailure' => match (true) {
					$step->allowFailure === null => 'inherit',
					$step->allowFailure === false => 'none',
					$step->allowFailure === true => 'any',
					default => 'list',
				},
				'allowFailureCodes' => \is_array($step->allowFailure)
					? \implode(', ', $step->allowFailure)
					: '',
			];
		}

		if ($step instanceof SetStep) {
			return [
				'type' => 'set',
				'name' => $step->name ?? '',
				'key' => $step->key,
				'value' => $step->value->getSource(),
			];
		}

		if ($step instanceof IfStep) {
			return [
				'type' => 'if',
				'name' => $step->name ?? '',
				'left' => $step->condition->left->getSource(),
				'op' => $step->condition->op,
				'right' => $step->condition->right?->getSource() ?? '',
			];
		}

		if ($step instanceof ForeachStep) {
			return [
				'type' => 'foreach',
				'name' => $step->name ?? '',
				'over' => $step->over->getSource(),
				'as' => $step->as,
			];
		}

		// A new step type must not be silently skipped — the form would open
		// empty and saving would overwrite the step with something else.
		throw new \InvalidArgumentException('Unknown step type ' . $step::class . '.');
	}


	/**
	 * A new step from the form carries empty branches because the form
	 * doesn't edit them. When replacing an existing step, they must
	 * therefore be taken over from the original — otherwise editing the
	 * condition would delete the whole subtree.
	 *
	 * When the type doesn't match, the new step is returned unchanged:
	 * carrying branches between different types makes no sense, and the
	 * form can't change a step's type anyway.
	 */
	public static function keepChildren(Step $original, Step $updated): Step
	{
		if ($original instanceof IfStep && $updated instanceof IfStep) {
			return new IfStep($updated->condition, $original->then, $original->else, $updated->name);
		}

		if ($original instanceof ForeachStep && $updated instanceof ForeachStep) {
			return new ForeachStep($updated->over, $updated->as, $original->steps, $updated->name);
		}

		return $updated;
	}


	/**
	 * @param  array<string, mixed> $values
	 */
	private static function toCondition(array $values): Condition
	{
		$op = self::text($values['op'] ?? '');
		$right = self::orNull($values['right'] ?? '');

		return new Condition(
			left: Template::parse(self::text($values['left'] ?? '')),
			op: $op,
			// A unary operator ignores the right side; if it stayed in the
			// form, it would be written to the file and confuse readers.
			right: \in_array($op, Condition::UnaryOperators, true) || $right === null
				? null
				: Template::parse($right),
		);
	}


	/**
	 * @param  mixed $raw
	 * @return array<string, Template>
	 */
	private static function toIn(mixed $raw): array
	{
		$in = [];

		foreach (self::rows($raw) as $row) {
			$key = self::text($row['key'] ?? '');

			if ($key !== '') {
				$in[$key] = Template::parse(self::text($row['value'] ?? ''));
			}
		}

		return $in;
	}


	/**
	 * @param  mixed $raw
	 * @return array<string, string>
	 */
	private static function toOut(mixed $raw): array
	{
		$out = [];

		foreach (self::rows($raw) as $row) {
			$channel = self::text($row['channel'] ?? '');
			$value = self::text($row['value'] ?? '');

			// A channel without a key writes nowhere — it's an unfinished row.
			if ($channel !== '' && $value !== '') {
				$out[$channel] = $value;
			}
		}

		return $out;
	}


	/**
	 * Rows sorted by index. The order of keys from POST isn't guaranteed,
	 * and order matters for both in and out.
	 *
	 * @param  mixed $raw
	 * @return list<array<array-key, mixed>>
	 */
	private static function rows(mixed $raw): array
	{
		if (!\is_array($raw)) {
			return [];
		}

		\ksort($raw);

		$rows = [];

		foreach ($raw as $row) {
			if (\is_array($row)) {
				$rows[] = $row;
			}
		}

		return $rows;
	}


	/**
	 * @param  array<string, mixed> $values
	 * @return bool|array<int, int>|null
	 */
	private static function toAllowFailure(array $values): bool|array|null
	{
		$mode = self::text($values['allowFailure'] ?? 'inherit');

		if ($mode === 'none') {
			return false;
		}

		if ($mode === 'any') {
			return true;
		}

		if ($mode !== 'list') {
			return null;
		}

		$codes = [];

		foreach (\explode(',', self::text($values['allowFailureCodes'] ?? '')) as $code) {
			$code = \trim($code);

			if (\ctype_digit($code)) {
				$codes[] = (int) $code;
			}
		}

		// The parser would reject an empty list — it's the same as "not set".
		return $codes === [] ? null : $codes;
	}


	private static function text(mixed $value): string
	{
		return \is_scalar($value) ? \trim((string) $value) : '';
	}


	private static function orNull(mixed $value): ?string
	{
		$value = self::text($value);

		return $value === '' ? null : $value;
	}
}
