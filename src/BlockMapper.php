<?php

declare(strict_types=1);

namespace Donut\Gui;

use Donut\Format\Block;
use Donut\Format\StdinSpec;
use Donut\Template;


/**
 * Hodnoty formuláře ↔ Block. Čistá konverze, nezná Nette ani HTTP.
 *
 * Indexy v args a inputs můžou mít díry — JS řádky nikdy nepřečísluje, jen
 * je přidává a ubírá, takže po smazání prostředního řádku zůstane v číslování
 * mezera. Srovnání je tady.
 *
 * Prázdný řetězec znamená nevyplněno, ne "" — sekce 6 specifikace formátu.
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

		// ksort, protože pořadí klíčů z POSTu není zaručené a na pořadí
		// argumentů záleží.
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

			// Skupina, ve které nezbyl argument, do souboru nepatří.
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

		// Prázdný výčet by parser odmítl — je to totéž jako "nepovoluj nic".
		return $codes === [] ? false : $codes;
	}


	private static function orNull(mixed $value): ?string
	{
		$value = \trim(self::toStr($value));

		return $value === '' ? null : $value;
	}


	// PHPStan: mixed z formuláře se nedá bezpečně přetypovat na string.
	// Formulář posílá skaláry; cokoliv jiného bereme jako nevyplněné.
	private static function toStr(mixed $value): string
	{
		return \is_scalar($value) ? (string) $value : '';
	}
}
