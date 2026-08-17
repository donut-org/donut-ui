<?php

declare(strict_types=1);

namespace Donut\Gui\Presentation\Block;

use Donut\Format\Block;
use Nette\Bridges\ApplicationLatte\Template;


final class BlockDetailTemplate extends Template
{
	public ?string $name = null;

	/** Null znamená, že se soubor nenaparsoval — pak je vyplněný $error. */
	public ?Block $block = null;

	public ?string $error = null;

	/** @var list<string> jména workflow, která kámen volají */
	public array $usedBy = [];
}
