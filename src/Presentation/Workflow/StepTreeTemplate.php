<?php

declare(strict_types=1);

namespace Donut\Gui\Presentation\Workflow;

use Donut\Format\Step;
use Donut\Gui\KeyMap;
use Donut\Gui\ProblemMap;
use Donut\Gui\StepPath;
use Nette\Bridges\ApplicationLatte\Template;


/**
 * Template for StepTreeControl.
 */
final class StepTreeTemplate extends Template
{
	/** @var array<int, Step> */
	public array $steps = [];

	public ?StepPath $path = null;

	public ?ProblemMap $problems = null;

	public ?KeyMap $keys = null;

	/** Name of the selected key; null = nothing selected. */
	public ?string $selected = null;

	public string $name = '';

	/** Error from the last step move or delete; null = none. */
	public ?string $error = null;
}
