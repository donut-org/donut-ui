<?php

declare(strict_types=1);

namespace Donut\Gui;

use Donut\Format\Input;


/**
 * Inputs ↔ form values.
 *
 * Block inputs and workflow inputs have the same shape, so the conversion
 * lives in one place — the same reason donut has InputWriter for the
 * serializer.
 *
 * Row indexes can have holes, and their order from POST isn't guaranteed:
 * JS never renumbers rows. The sorting happens here.
 */
final class InputMapper
{
	/**
	 * @param  mixed $raw rows from the form
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

			$name = Text::of($row['name'] ?? '');

			// A row without a name is an unfinished row, not an input.
			if ($name === '') {
				continue;
			}

			$inputs[$name] = new Input(
				name: $name,
				required: (bool) ($row['required'] ?? false),
				default: Text::orNull($row['default'] ?? ''),
				description: Text::orNull($row['description'] ?? ''),
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
}
