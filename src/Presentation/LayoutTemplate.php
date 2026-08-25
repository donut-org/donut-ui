<?php

declare(strict_types=1);

namespace Donut\Gui\Presentation;

use Nette\Bridges\ApplicationLatte\Template;


/**
 * Společný předek šablon, které se kreslí do @layout.latte.
 *
 * Jméno profilu je jediné, z čeho uživatel pozná, kterou sadu edituje —
 * pracovní adresář mu to po přechodu na profily neřekne. Výchozí prázdná
 * hodnota je kvůli testům, které renderují metodu prezentéru napřímo,
 * tedy bez beforeRender().
 */
abstract class LayoutTemplate extends Template
{
	public string $profile = '';
}
