<?php

declare(strict_types=1);

use Donut\Gui\FormFactory;
use Nette\Forms\Form;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

$form = FormFactory::create();
$form->addText('name', 'Name');
$form->addTextArea('description', 'Description');
$form->addSelect('op', 'Operator', ['eq' => '=']);
$form->addCheckbox('required', 'Required');
$form->addRadioList('failure', 'Allowed failure', ['none' => 'exit 0 only']);
$form->addSubmit('save', 'Save');
$form->addHidden('type');

// the class is added by onRender, which the {form} tag triggers via fireRenderEvents()
Assert::notContains('form-control', (string) $form['name']->getControl(), 'not yet before rendering');

$form->fireRenderEvents();

Assert::contains('class="form-control"', (string) $form['name']->getControl());
Assert::contains('class="form-control"', (string) $form['description']->getControl());
Assert::contains('class="form-select"', (string) $form['op']->getControl());
Assert::contains('class="form-check-input"', (string) $form['required']->getControl());
Assert::contains('class="form-check-input"', (string) $form['failure']->getControl());
Assert::contains('class="btn btn-primary"', (string) $form['save']->getControl());

// a hidden field doesn't get any class
Assert::notContains('class=', (string) $form['type']->getControl());

// the factory doesn't overwrite a custom class — the delete button relies on this
$custom = FormFactory::create();
$custom->addSubmit('delete', 'Delete')
	->getControlPrototype()->setAttribute('class', 'btn btn-danger');
$custom->addText('name')
	->getControlPrototype()->setAttribute('class', 'form-control form-control-lg');

$custom->fireRenderEvents();

Assert::contains('class="btn btn-danger"', (string) $custom['delete']->getControl());
Assert::notContains('btn-primary', (string) $custom['delete']->getControl());
Assert::contains('class="form-control form-control-lg"', (string) $custom['name']->getControl());

// repeated rendering doesn't duplicate the class
$form->fireRenderEvents();
Assert::same(1, \substr_count((string) $form['name']->getControl(), 'form-control'));

// the factory returns UI\Form, not a bare Nette\Forms\Form — presenters
// register it as a component
Assert::type(Nette\Application\UI\Form::class, $form);
Assert::type(Form::class, $form);
