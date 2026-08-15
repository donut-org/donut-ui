<?php

declare(strict_types=1);

namespace Donut\Gui\Presentation\Workflow;

use Donut\Format\Step;
use Donut\Gui\KeyMap;
use Donut\Gui\ProblemMap;
use Donut\Gui\StepPath;
use Nette\Bridges\ApplicationLatte\Template;


/**
 * Šablona pro StepTreeControl.
 */
final class StepTreeTemplate extends Template
{
	/** @var array<int, Step> */
	public array $steps = [];

	public ?StepPath $path = null;

	public ?ProblemMap $problems = null;

	public ?KeyMap $keys = null;

	/** Jméno vybraného klíče; null = nic není vybrané. */
	public ?string $selected = null;

	public string $name = '';

	/** Chyba z posledního přesunu nebo mazání kroku; null = žádná. */
	public ?string $error = null;
}
