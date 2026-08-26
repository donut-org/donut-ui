<?php

declare(strict_types=1);

namespace Donut\Gui;


/**
 * How many rows a repeating form container has, and with which indexes.
 *
 * `null` means this POST doesn't belong to the container at all — the rows
 * are then taken from the loaded object, plus one extra empty one so the
 * user has somewhere to write even without JS. An array, even an empty one,
 * means the POST does belong to the container — indexes are derived from
 * the incoming data, because JS never renumbers rows and the numbering can
 * therefore have holes. If no row survives the digit filter, one empty row
 * is returned: an empty list would be a dead end, JS clones the last row,
 * and a container with no rows could no longer be extended.
 *
 * Whether the POST belongs to this particular form is the caller's call —
 * only it knows its own signal's name. A form built from someone else's
 * POST would render empty even though the object behind it isn't.
 */
final class RowShape
{
	/**
	 * @param  mixed $post     the POST subarray for this container, or null
	 * @param  int   $existing how many rows the loaded object has
	 * @return array<int, int>
	 */
	public static function of(mixed $post, int $existing): array
	{
		if (\is_array($post)) {
			$keys = [];

			foreach (\array_keys($post) as $key) {
				// A component name in Nette must match [a-zA-Z0-9_]+.
				if (\ctype_digit((string) $key)) {
					$keys[] = (int) $key;
				}
			}

			\sort($keys);

			// A container with not a single row arriving gets one empty
			// one. An empty list would be a dead end: JS clones the last
			// row, so a container with no rows could no longer be extended.
			return $keys === [] ? [0] : $keys;
		}

		return \range(0, $existing);
	}
}
