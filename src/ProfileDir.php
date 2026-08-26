<?php

declare(strict_types=1);

namespace Donut\Gui;


/**
 * What to do about a missing `workflows/` or `blocks/` directory in the GUI.
 *
 * The counterpart to `Donut\MissingDir`, which tells a CLI user to run
 * `mkdir -p`. The GUI needs the opposite sentence: the stores create the
 * directory on save, so telling the user to reach for a shell would be
 * both wrong and a dead end — the GUI is the place where someone has no
 * shell at hand.
 *
 * A message on a read path always precedes the save that would fix it,
 * which is why this says *when*, not *how*.
 */
final class ProfileDir
{
	public static function hint(): string
	{
		return 'Donut will create it when you save.';
	}
}
