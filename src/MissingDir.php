<?php

declare(strict_types=1);

namespace Donut\Gui;


/**
 * Co s chybějícím adresářem `workflows/` nebo `blocks/`.
 *
 * GUI adresáře **nezakládá**: server běží v tom pracovním adresáři, ze
 * kterého ho někdo spustil, a mlčky tam sypat adresáře je horší než hláška.
 * Hláška tedy musí říct, co udělat — jinak je prázdný projekt slepá ulička,
 * ze které se bez shellu nedá odejít.
 */
final class MissingDir
{
	public static function hint(string $directory): string
	{
		return 'GUI ho sám nezaloží — vytvoř ho příkazem `mkdir '
			. \basename($directory)
			. '`, nebo spusť server z adresáře projektu.';
	}
}
