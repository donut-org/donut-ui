<?php

declare(strict_types=1);

namespace Donut\Gui;

use Nette\Application\UI\Form as UIForm;
use Nette\Forms\Controls\BaseControl;
use Nette\Forms\Form;


/**
 * Formulář s bootstrapími třídami.
 *
 * Šablony vykreslují políčka ručně přes {input jmeno}, takže bootstrapí
 * recept přes $renderer->wrappers by se neuplatnil. onRender ano — tag
 * {form} ho spouští přes fireRenderEvents().
 */
final class FormFactory
{
	/** @var array<string, string> typ prvku → třída */
	private const Classes = [
		'text' => 'form-control',
		'textarea' => 'form-control',
		'select' => 'form-select',
		'checkbox' => 'form-check-input',
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

			// Jen tam, kde žádná třída není. Díky tomu si mazací tlačítko
			// může říct o btn-danger při vzniku a továrna mu to nepřepíše —
			// a opakované vykreslení třídu nezdvojí.
			if ($el->getAttribute('class') !== null) {
				continue;
			}

			$el->setAttribute('class', self::Classes[$type]);
		}
	}
}
