<?php

declare(strict_types=1);

namespace Donut\Gui\Presentation\Block;

use Donut\Format\Block;
use Donut\Gui\Presentation\LayoutTemplate;


final class BlockDetailTemplate extends LayoutTemplate
{
	public ?string $name = null;

	/** Null znamená, že se soubor nenaparsoval — pak je vyplněný $error. */
	public ?Block $block = null;

	public ?string $error = null;

	/** @var list<string> jména workflow, která kámen volají */
	public array $usedBy = [];
}
