<?php

declare(strict_types=1);

namespace Donut\Gui;

use Donut\Format\Block;
use Donut\Format\RunStep;


/**
 * The rows of the step form's input table. A pure conversion, knows nothing
 * of Nette or HTTP — same as BlockMapper, InputMapper and RowShape.
 *
 * The list is fixed by the block, not by the user: the block declares its
 * inputs and the validator checks them right afterwards, so there is no
 * reason to have the user retype their names.
 */
final class BlockInputs
{
	/**
	 * Slots in the order they are shown: declared inputs as the block
	 * declares them, then stdin, then whatever the step fills in that the
	 * block does not declare.
	 *
	 * @return array<int, BlockInputSlot>
	 */
	public static function slots(Block $block, ?RunStep $step): array
	{
		// $step?->in ?? [] reports nullsafe.neverNull to PHPStan (level max) —
		// `in` itself is never null, only the step is. Splitting it into a
		// variable works around that, same as in WorkflowMapper::toWorkflow().
		$stepIn = $step?->in;
		$in = $stepIn ?? [];

		$slots = [];
		$taken = [];

		foreach ($block->inputs as $name => $input) {
			$taken[$name] = true;
			$template = $in[$name] ?? null;

			$slots[] = new BlockInputSlot(
				name: $name,
				// The same rule the validator applies to an unfilled input
				// (Validator::checkRunStep()): an input with a default is
				// never missing, whatever it says about being required.
				required: $input->required && $input->default === null,
				default: $input->default,
				description: $input->description,
				value: $template === null ? '' : $template->getSource(),
				declared: true,
			);
		}

		// A block declaring an input literally named "stdin" is an error the
		// validator reports; without this guard the page would render two
		// slots with the same name and the second would overwrite the first
		// on save.
		if ($block->stdin !== null && !isset($block->inputs['stdin'])) {
			$taken['stdin'] = true;
			$template = $in['stdin'] ?? null;

			$slots[] = new BlockInputSlot(
				name: 'stdin',
				required: $block->stdin->required,
				default: null,
				description: $block->stdin->description,
				value: $template === null ? '' : $template->getSource(),
				declared: true,
			);
		}

		foreach ($in as $name => $template) {
			if (isset($taken[$name])) {
				continue;
			}

			$slots[] = new BlockInputSlot(
				name: $name,
				required: false,
				default: null,
				description: null,
				value: $template->getSource(),
				declared: false,
			);
		}

		return $slots;
	}

	/**
	 * The POST joined back with the slot names: $post[$i] belongs to
	 * $slots[$i]. The name never travels through the POST — input names are
	 * arbitrary strings (JsonSource::parseInputs() accepts anything), while
	 * a Nette component name has to match [a-zA-Z0-9_]+.
	 *
	 * An empty value is dropped, not written as an empty template. The two
	 * are not the same thing: a key present with an empty string suppresses
	 * the block's default, a missing key lets it through — see
	 * CommandLine::resolveValues().
	 *
	 * @param  array<int, BlockInputSlot> $slots
	 * @param  mixed                      $post values of the `in` container
	 * @return list<array{key: string, value: string}>
	 */
	public static function rows(array $slots, mixed $post): array
	{
		$rows = [];

		foreach ($slots as $i => $slot) {
			$row = \is_array($post) ? ($post[$i] ?? null) : null;
			$value = \is_array($row) ? Text::of($row['value'] ?? '') : '';

			if ($value === '') {
				continue;
			}

			$rows[] = ['key' => $slot->name, 'value' => $value];
		}

		return $rows;
	}
}
