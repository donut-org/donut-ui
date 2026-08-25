<?php

declare(strict_types=1);

namespace Donut\Gui\Presentation\Block;

use Donut\Format\Block;
use Donut\Gui\Presentation\LayoutTemplate;


/**
 * Šablona pro Block:default — přehled kamenů z blocks/.
 */
final class BlockDefaultTemplate extends LayoutTemplate
{
	/** @var array<string, Block|string> jméno => kámen, nebo hláška o chybě */
	public array $blocks = [];

	public ?string $error = null;

	public string $dir = '';

	/** @var array<string, list<string>> */
	public array $usage = [];
}
