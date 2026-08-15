<?php

declare(strict_types=1);

namespace Donut\Gui;

use Donut\Format\Input;


/**
 * Vstupy ↔ hodnoty formuláře.
 *
 * Vstupy kamene a vstupy workflow mají tentýž tvar, takže převod má jedno
 * místo — stejný důvod, proč v donutu existuje InputWriter pro serializér.
 *
 * Indexy řádků můžou mít díry a jejich pořadí z POSTu není zaručené: JS
 * řádky nikdy nepřečísluje. Srovnání je tady.
 */
final class InputMapper
{
	/**
	 * @param  mixed $raw řádky z formuláře
	 * @return array<string, Input>
	 */
	public static function toInputs(mixed $raw): array
	{
		if (!\is_array($raw)) {
			return [];
		}

		\ksort($raw);
		$inputs = [];

		foreach ($raw as $row) {
			if (!\is_array($row)) {
				continue;
			}

			$name = self::text($row['name'] ?? '');

			// Řádek bez jména je nedopsaný řádek, ne vstup.
			if ($name === '') {
				continue;
			}

			$inputs[$name] = new Input(
				name: $name,
				required: (bool) ($row['required'] ?? false),
				default: self::orNull($row['default'] ?? ''),
				description: self::orNull($row['description'] ?? ''),
			);
		}

		return $inputs;
	}


	/**
	 * @param  array<string, Input> $inputs
	 * @return array<int, array<string, mixed>>
	 */
	public static function toValues(array $inputs): array
	{
		$rows = [];

		foreach ($inputs as $name => $input) {
			$rows[] = [
				'name' => $name,
				'required' => $input->required,
				'default' => $input->default ?? '',
				'description' => $input->description ?? '',
			];
		}

		return $rows;
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
