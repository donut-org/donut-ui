<?php

declare(strict_types=1);

namespace Donut\Gui\Presentation\Workflow;

use Nette\Bridges\ApplicationLatte\Template;


final class WorkflowEditTemplate extends Template
{
	public ?string $name = null;

	public ?string $error = null;
}
