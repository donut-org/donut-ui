<?php

declare(strict_types=1);

namespace Donut\Gui\Presentation\Workflow;

use Donut\Format\Workflow;
use Nette\Bridges\ApplicationLatte\Template;


/**
 * Šablona pro Workflow:default — seznam workflow z workflows/.
 */
final class WorkflowDefaultTemplate extends Template
{
	/** @var array<string, Workflow|string> jméno => workflow, nebo hláška o chybě */
	public array $workflows = [];

	public ?string $error = null;

	public string $dir = '';
}
