<?php

declare(strict_types=1);

namespace Donut\Gui\Presentation\Workflow;

use Donut\Gui\Presentation\LayoutTemplate;


final class WorkflowStepTemplate extends LayoutTemplate
{
	public ?string $error = null;

	public string $name = '';

	public string $at = '';

	public string $type = '';

	public string $block = '';

	/** @var array<int, \Donut\Gui\BlockInputSlot> */
	public array $slots = [];
}
