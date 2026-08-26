<?php

declare(strict_types=1);

namespace Donut\Gui;

use Donut\Format\Workflow;


/**
 * Workflow header ↔ form values. Name, description, inputs.
 *
 * **Steps are not converted in either direction.** The header form doesn't
 * edit them, so toWorkflow() takes them from the original workflow —
 * otherwise editing the description would delete the whole step tree. It's
 * the same trap that StepMapper::keepChildren() handles for a step, just
 * more destructive.
 */
final class WorkflowMapper
{
	/**
	 * @param array<string, mixed> $values
	 * @param Workflow|null        $original when editing; null when creating
	 */
	public static function toWorkflow(array $values, ?Workflow $original = null): Workflow
	{
		// $original?->steps ?? [] falsely reports nullsafe.neverNull to
		// PHPStan (level max) — splitting into a variable works around it.
		$steps = $original?->steps;

		return new Workflow(
			name: Text::of($values['name'] ?? ''),
			inputs: InputMapper::toInputs($values['inputs'] ?? []),
			steps: $steps ?? [],
			description: Text::orNull($values['description'] ?? ''),
		);
	}


	/**
	 * @return array<string, mixed>
	 */
	public static function toValues(Workflow $workflow): array
	{
		return [
			'name' => $workflow->name,
			'description' => $workflow->description ?? '',
			'inputs' => InputMapper::toValues($workflow->inputs),
		];
	}
}
