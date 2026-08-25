<?php

declare(strict_types=1);

namespace Donut\Gui\Presentation\Workflow;

use Donut\Gui\Presentation\LayoutTemplate;


final class WorkflowEditTemplate extends LayoutTemplate
{
	public ?string $name = null;

	public ?string $error = null;
}
