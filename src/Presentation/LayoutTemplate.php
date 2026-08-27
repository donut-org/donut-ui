<?php

declare(strict_types=1);

namespace Donut\Gui\Presentation;

use Nette\Bridges\ApplicationLatte\Template;


/**
 * Common ancestor of templates that render into @layout.latte.
 *
 * The profile name is the only thing that tells the user which set they're
 * editing — the working directory no longer does, since the move to
 * profiles. The default empty value is for tests that render a presenter
 * method directly, i.e. without beforeRender().
 */
abstract class LayoutTemplate extends Template
{
	public string $profile = '';

	/**
	 * Set by Presenter::flashMessage(); declared here because these template
	 * classes are typed, and Nette would otherwise be creating a dynamic
	 * property. The layout renders it, so this is where it belongs.
	 *
	 * @var array<int, \stdClass>
	 */
	public array $flashes = [];
}
