<?php

declare(strict_types=1);

namespace Donut\Gui\Presentation\Block;

use Nette\Bridges\ApplicationLatte\Template;


final class BlockEditTemplate extends Template
{
	public ?string $name = null;

	public ?string $error = null;

	/** @var array<int, string> */
	public array $errors = [];

	/** @var array<int, string> */
	public array $warnings = [];

	/** @var list<string> */
	public array $usedBy = [];
}
