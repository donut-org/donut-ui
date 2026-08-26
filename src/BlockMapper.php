<?php

declare(strict_types=1);

namespace Donut\Gui;

use Donut\Format\Block;
use Donut\Format\StdinSpec;
use Donut\Template;


/**
 * Form values ↔ Block. A pure conversion, knows nothing of Nette or HTTP.
 *
 * Indexes in args and inputs can have holes — JS never renumbers rows, it
 * only adds and removes them, so deleting a middle row leaves a gap in the
 * numbering. The sorting happens here.
 *
 * An empty string means unfilled, not "" — section 6 of the format spec.
 */
final class BlockMapper
{
	/**
	 * @param array<string, mixed> $values
	 */
	public function toBlock(array $values): Block
	{
		return new Block(
			name: \trim(self::toStr($values['name'] ?? '')),
			command: \trim(self::toStr($values['command'] ?? '')),
			args: $this->toArgs($values['args'] ?? []),
			inputs: InputMapper::toInputs($values['inputs'] ?? []),
			stdin: ($values['hasStdin'] ?? false)
				? new StdinSpec(
					required: (bool) ($values['stdinRequired'] ?? false),
					description: self::orNull($values['stdinDescription'] ?? ''),
				)
				: null,
			timeout: ($t = \trim(self::toStr($values['timeout'] ?? ''))) === '' ? null : (int) $t,
			allowFailure: $this->toAllowFailure($values),
			description: self::orNull($values['description'] ?? ''),
		);
	}


	/**
	 * @return array<string, mixed>
	 */
	public function toValues(Block $block): array
	{
		$args = [];

		foreach ($block->args as $group) {
			$args[] = \array_values(\array_map(
				fn(Template $template): string => $template->getSource(),
				$group,
			));
		}

		$inputs = InputMapper::toValues($block->inputs);

		return [
			'name' => $block->name,
			'description' => $block->description ?? '',
			'command' => $block->command,
			'args' => $args,
			'inputs' => $inputs,
			'hasStdin' => $block->stdin !== null,
			'stdinRequired' => $block->stdin->required ?? true,
			'stdinDescription' => $block->stdin->description ?? '',
			'timeout' => $block->timeout === null ? '' : (string) $block->timeout,
			'allowFailure' => match (true) {
				$block->allowFailure === false => 'none',
				$block->allowFailure === true => 'any',
				default => 'list',
			},
			'allowFailureCodes' => \is_array($block->allowFailure)
				? \implode(', ', $block->allowFailure)
				: '',
		];
	}


	/**
	 * @param  mixed $raw
	 * @return array<int, array<int, Template>>
	 */
	private function toArgs(mixed $raw): array
	{
		if (!\is_array($raw)) {
			return [];
		}

		$groups = [];

		// ksort, because the order of keys from POST isn't guaranteed and
		// argument order matters.
		\ksort($raw);

		foreach ($raw as $group) {
			if (!\is_array($group)) {
				continue;
			}

			\ksort($group);
			$args = [];

			foreach ($group as $arg) {
				$arg = \trim(self::toStr($arg));

				if ($arg !== '') {
					$args[] = Template::parse($arg);
				}
			}

			// A group with no argument left doesn't belong in the file.
			if ($args !== []) {
				$groups[] = $args;
			}
		}

		return $groups;
	}


	/**
	 * @param  array<string, mixed> $values
	 * @return bool|array<int, int>
	 */
	private function toAllowFailure(array $values): bool|array
	{
		$mode = self::toStr($values['allowFailure'] ?? 'none');

		if ($mode === 'any') {
			return true;
		}

		if ($mode !== 'list') {
			return false;
		}

		$codes = [];

		foreach (\explode(',', self::toStr($values['allowFailureCodes'] ?? '')) as $code) {
			$code = \trim($code);

			if (\ctype_digit($code)) {
				$codes[] = (int) $code;
			}
		}

		// The parser would reject an empty list — it's the same as "allow nothing".
		return $codes === [] ? false : $codes;
	}


	private static function orNull(mixed $value): ?string
	{
		$value = \trim(self::toStr($value));

		return $value === '' ? null : $value;
	}


	// PHPStan: mixed from the form can't be safely cast to string.
	// The form sends scalars; anything else is treated as unfilled.
	private static function toStr(mixed $value): string
	{
		return \is_scalar($value) ? (string) $value : '';
	}
}
