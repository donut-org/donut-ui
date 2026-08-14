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
 * Hodnoty formuláře ↔ Step. Čistá konverze, nezná Nette ani HTTP.
 *
 * Vnořené kroky (then, else, foreach.steps) se nepřenášejí — stránka kroku
 * větve needituje, ty se plní z přehledu. toStep() proto u if a foreach
 * vrací krok s prázdnými větvemi a volající si je doplní sám.
 *
 * Indexy v in a out můžou mít díry — JS řádky nikdy nepřečísluje. Srovnání
 * je tady, stejně jako v BlockMapperu.
 */
final class StepMapper
{
	/**
	 * @param  array<string, mixed> $values
	 * @throws \InvalidArgumentException na neznámý typ kroku
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
				'Neznámý typ kroku "' . self::text($values['type'] ?? '') . '".'
			),
		};
	}


	/**
	 * @return array<string, mixed>
	 */
	public static function toValues(Step $step): array
	{
		if ($step instanceof RunStep) {
			$in = [];

			foreach ($step->in as $key => $template) {
				$in[] = ['key' => $key, 'value' => $template->getSource()];
			}

			$out = [];

			foreach ($step->out as $channel => $value) {
				$out[] = ['channel' => $channel, 'value' => $value];
			}

			return [
				'type' => 'run',
				'name' => $step->name ?? '',
				'block' => $step->block,
				'in' => $in,
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

		// Nový typ kroku se nesmí tiše přeskočit — formulář by se otevřel
		// prázdný a uložením by se krok přepsal na něco jiného.
		throw new \InvalidArgumentException('Neznámý typ kroku ' . $step::class . '.');
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
			// Unární operátor pravou stranu ignoruje; kdyby ve formuláři
			// zbyla, zapsala by se do souboru a mátla by při čtení.
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

			// Kanál bez klíče nikam nezapisuje — je to nedopsaný řádek.
			if ($channel !== '' && $value !== '') {
				$out[$channel] = $value;
			}
		}

		return $out;
	}


	/**
	 * Řádky seřazené podle indexu. Pořadí klíčů z POSTu není zaručené
	 * a u in i out na pořadí záleží.
	 *
	 * @param  mixed $raw
	 * @return list<array<mixed, mixed>>
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

		// Prázdný výčet by parser odmítl — je to totéž jako „nenastaveno".
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
