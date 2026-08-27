<?php

declare(strict_types=1);

namespace Donut\Gui\Presentation\Workflow;

use Donut\Format\Block;
use Donut\Gui\Presentation\LayoutTemplate;


/**
 * Template for Workflow:pickBlock — which block will the new run step call?
 */
final class WorkflowPickBlockTemplate extends LayoutTemplate
{
	/** @var array<string, Block|string> name => block, or an error message */
	public array $blocks = [];

	public ?string $error = null;

	public string $dir = '';

	public string $name = '';

	public string $at = '';
}
