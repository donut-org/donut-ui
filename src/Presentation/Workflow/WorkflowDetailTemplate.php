<?php

declare(strict_types=1);

namespace Donut\Gui\Presentation\Workflow;

use Donut\Format\Workflow;
use Donut\Gui\KeyMap;
use Donut\Gui\Presentation\LayoutTemplate;
use Donut\Gui\ProblemMap;
use Donut\Validator\Problem;


/**
 * Template for Workflow:detail — the workflow's steps with problems from the
 * validator.
 *
 * $error and $workflow are independent of each other. The workflow failed to
 * load only when $workflow stays null — and only then are all the other
 * variables null too. Missing or broken blocks instead set $error and still
 * fill in everything else: the header and the step tree render, only the
 * validator's findings are missing. That's why detail.latte asks about
 * $workflow === null, not about $error.
 */
final class WorkflowDetailTemplate extends LayoutTemplate
{
	public ?string $error = null;

	/**
	 * Name from the address. The breadcrumbs need it even when the file
	 * failed to parse and $workflow stays null — right there the file name
	 * is the only thing that tells the user what failed to open.
	 */
	public string $name = '';

	public ?Workflow $workflow = null;

	public ?ProblemMap $problems = null;

	public ?KeyMap $keys = null;

	/** Key name from the address; null = nothing selected. */
	public ?string $selectedKey = null;

	/** false = $selectedKey isn't in the workflow, the click highlights nothing. */
	public bool $selectedKeyExists = true;

	/** @var list<Problem> */
	public array $workflowProblems = [];
}
