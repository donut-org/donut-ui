<?php

declare(strict_types=1);

namespace Donut\Gui;


/**
 * A form value to a string.
 *
 * Nette's `getValues('array')` returns mixed — arrays, null, even objects.
 * The mappers always want the same thing: a trimmed string, with an empty
 * string treated as "nothing". It was copied byte for byte twice
 * (WorkflowMapper, InputMapper), so here it is once.
 *
 * BlockMapper has its own instance toStr()/orNull() — a different
 * interface, and block editing is a separate project.
 */
final class Text
{
	public static function of(mixed $value): string
	{
		return \is_scalar($value) ? \trim((string) $value) : '';
	}


	public static function orNull(mixed $value): ?string
	{
		$value = self::of($value);

		return $value === '' ? null : $value;
	}
}
