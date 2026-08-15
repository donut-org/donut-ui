<?php

declare(strict_types=1);

namespace Donut\Gui;

use Donut\Format\Workflow;


/**
 * Hlavička workflow ↔ hodnoty formuláře. Jméno, popis, vstupy.
 *
 * **Kroky se nepřevádějí ani jedním směrem.** Formulář hlavičky je needituje,
 * takže toWorkflow() je bere z původního workflow — jinak by úprava popisu
 * smazala celý strom kroků. Je to táž past, kterou u kroku řeší
 * StepMapper::keepChildren(), jen ničivější.
 */
final class WorkflowMapper
{
	/**
	 * @param array<string, mixed> $values
	 * @param Workflow|null        $original při úpravě; při zakládání null
	 */
	public static function toWorkflow(array $values, ?Workflow $original = null): Workflow
	{
		// $original?->steps ?? [] hlásí PHPStanu (level max) falešně
		// nullsafe.neverNull — rozdělení do proměnné to obchází.
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
