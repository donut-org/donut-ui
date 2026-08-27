<?php

declare(strict_types=1);

namespace Donut\Gui;


/**
 * One row of the "Block inputs" table.
 *
 * A slot is not the block's declaration: it also carries the value the step
 * passes today, and whether the block declares it at all. A key in the
 * step's `in` that the block does not declare gets a slot too — otherwise
 * saving would delete it without a word.
 */
final class BlockInputSlot
{
	public function __construct(
		public readonly string $name,
		public readonly bool $required,
		public readonly ?string $default,
		public readonly ?string $description,
		public readonly string $value,
		public readonly bool $declared,
	) {
	}
}
