<?php

declare(strict_types=1);

namespace Donut\Gui\Presentation\Workflow;

use Nette\Bridges\ApplicationLatte\Template;


final class WorkflowStepTemplate extends Template
{
	public ?string $error = null;

	public string $name = '';

	public string $at = '';

	public string $type = '';

	/** @var array<string, string> */
	public array $blocks = [];
}
