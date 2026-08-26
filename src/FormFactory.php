<?php

declare(strict_types=1);

namespace Donut\Gui;

use Nette\Application\UI\Form as UIForm;
use Nette\Forms\Controls\BaseControl;
use Nette\Forms\Form;


/**
 * A form with Bootstrap classes.
 *
 * Templates render fields by hand via {input name}, so the Bootstrap
 * approach through $renderer->wrappers wouldn't apply. onRender does — the
 * {form} tag triggers it via fireRenderEvents().
 */
final class FormFactory
{
	/** @var array<string, string> control type → class */
	private const Classes = [
		'text' => 'form-control',
		'textarea' => 'form-control',
		'select' => 'form-select',
		'checkbox' => 'form-check-input',
		// Radios ("Allowed failure") appear on both presenters; without this
		// line they stayed classless in the middle of a Bootstrap page.
		'radio' => 'form-check-input',
		'button' => 'btn btn-primary',
	];


	public static function create(): UIForm
	{
		$form = new UIForm;
		$form->onRender[] = self::addClasses(...);

		return $form;
	}


	public static function addClasses(Form $form): void
	{
		foreach ($form->getControls() as $control) {
			if (!$control instanceof BaseControl) {
				continue;
			}

			$type = $control->getOption('type');

			if (!\is_string($type) || !isset(self::Classes[$type])) {
				continue;
			}

			$el = $control->getControlPrototype();

			// Only where there's no class yet. This lets a delete button ask
			// for btn-danger when created without the factory overwriting
			// it — and repeated rendering doesn't duplicate the class.
			if ($el->getAttribute('class') !== null) {
				continue;
			}

			$el->setAttribute('class', self::Classes[$type]);
		}
	}
}
