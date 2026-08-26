<?php

declare(strict_types=1);

namespace Donut\Gui\Presentation\Block;

use Donut\Format\Block;
use Donut\Gui\Presentation\LayoutTemplate;


final class BlockDetailTemplate extends LayoutTemplate
{
	public ?string $name = null;

	/** Null means the file failed to parse — then $error is set. */
	public ?Block $block = null;

	public ?string $error = null;

	/** @var list<string> names of workflows that call the block */
	public array $usedBy = [];
}
