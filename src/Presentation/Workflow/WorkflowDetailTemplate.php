<?php

declare(strict_types=1);

namespace Donut\Gui\Presentation\Workflow;

use Donut\Format\Workflow;
use Donut\Gui\KeyMap;
use Donut\Gui\ProblemMap;
use Donut\Gui\StepPath;
use Donut\Validator\Problem;
use Nette\Bridges\ApplicationLatte\Template;


/**
 * Šablona pro Workflow:detail — kroky workflow s problémy od validátoru.
 *
 * Mimo $error jsou proměnné vyplněné jen v úspěšné větvi renderDetail() —
 * detail.latte se na $error ptá dřív, než na kteroukoliv z nich sáhne.
 */
final class WorkflowDetailTemplate extends Template
{
	public ?string $error = null;

	public ?Workflow $workflow = null;

	public ?ProblemMap $problems = null;

	public ?StepPath $rootPath = null;

	public ?KeyMap $keys = null;

	/** Jméno klíče z adresy; null = nic není vybrané. */
	public ?string $selectedKey = null;

	/** false = $selectedKey ve workflow není, klik nic nezvýrazní. */
	public bool $selectedKeyExists = true;

	/** @var list<Problem> */
	public array $workflowProblems = [];
}
