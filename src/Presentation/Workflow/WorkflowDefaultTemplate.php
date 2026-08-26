<?php

declare(strict_types=1);

namespace Donut\Gui\Presentation\Workflow;

use Donut\Format\Workflow;
use Donut\Gui\Presentation\LayoutTemplate;


/**
 * Template for Workflow:default — list of workflows from workflows/.
 */
final class WorkflowDefaultTemplate extends LayoutTemplate
{
	/** @var array<string, Workflow|string> name => workflow, or an error message */
	public array $workflows = [];

	public ?string $error = null;

	public string $dir = '';
}
