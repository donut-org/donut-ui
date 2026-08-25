<?php

declare(strict_types=1);

namespace Donut\Gui\Presentation\Workflow;

use Donut\Format\Workflow;
use Donut\Gui\KeyMap;
use Donut\Gui\Presentation\LayoutTemplate;
use Donut\Gui\ProblemMap;
use Donut\Validator\Problem;


/**
 * Šablona pro Workflow:detail — kroky workflow s problémy od validátoru.
 *
 * $error a $workflow jsou na sobě nezávislé. Workflow načíst nešlo, jen když
 * $workflow zůstane null — a jen tehdy jsou null i všechny ostatní proměnné.
 * Chybějící nebo vadné kameny naopak nastaví $error a zároveň vyplní všechno
 * ostatní: hlavička i strom kroků se vykreslí, chybí jen nálezy validátoru.
 * Proto se detail.latte ptá na $workflow === null, ne na $error.
 */
final class WorkflowDetailTemplate extends LayoutTemplate
{
	public ?string $error = null;

	/**
	 * Jméno z adresy. Drobečky ho potřebují i tehdy, když se soubor
	 * nenaparsoval a $workflow zůstane null — zrovna tam je jméno souboru
	 * to jediné, podle čeho uživatel pozná, co se nepovedlo otevřít.
	 */
	public string $name = '';

	public ?Workflow $workflow = null;

	public ?ProblemMap $problems = null;

	public ?KeyMap $keys = null;

	/** Jméno klíče z adresy; null = nic není vybrané. */
	public ?string $selectedKey = null;

	/** false = $selectedKey ve workflow není, klik nic nezvýrazní. */
	public bool $selectedKeyExists = true;

	/** @var list<Problem> */
	public array $workflowProblems = [];
}
