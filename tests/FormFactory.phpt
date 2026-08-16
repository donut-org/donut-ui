<?php

declare(strict_types=1);

use Donut\Gui\FormFactory;
use Nette\Forms\Form;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

$form = FormFactory::create();
$form->addText('jmeno', 'Jméno');
$form->addTextArea('popis', 'Popis');
$form->addSelect('op', 'Operátor', ['eq' => '=']);
$form->addCheckbox('povinny', 'Povinný');
$form->addSubmit('save', 'Uložit');
$form->addHidden('typ');

// třídu doplňuje onRender, které spouští tag {form} přes fireRenderEvents()
Assert::notContains('form-control', (string) $form['jmeno']->getControl(), 'před vykreslením ještě ne');

$form->fireRenderEvents();

Assert::contains('class="form-control"', (string) $form['jmeno']->getControl());
Assert::contains('class="form-control"', (string) $form['popis']->getControl());
Assert::contains('class="form-select"', (string) $form['op']->getControl());
Assert::contains('class="form-check-input"', (string) $form['povinny']->getControl());
Assert::contains('class="btn btn-primary"', (string) $form['save']->getControl());

// skryté pole žádnou třídu nedostane
Assert::notContains('class=', (string) $form['typ']->getControl());

// vlastní třídu továrna nepřepíše — na tom stojí mazací tlačítko
$vlastni = FormFactory::create();
$vlastni->addSubmit('smazat', 'Smazat')
	->getControlPrototype()->setAttribute('class', 'btn btn-danger');
$vlastni->addText('jmeno')
	->getControlPrototype()->setAttribute('class', 'form-control form-control-lg');

$vlastni->fireRenderEvents();

Assert::contains('class="btn btn-danger"', (string) $vlastni['smazat']->getControl());
Assert::notContains('btn-primary', (string) $vlastni['smazat']->getControl());
Assert::contains('class="form-control form-control-lg"', (string) $vlastni['jmeno']->getControl());

// opakované vykreslení třídu nezdvojí
$form->fireRenderEvents();
Assert::same(1, \substr_count((string) $form['jmeno']->getControl(), 'form-control'));

// továrna vrací UI\Form, ne holý Nette\Forms\Form — prezentéry ho registrují
// jako komponentu
Assert::type(Nette\Application\UI\Form::class, $form);
Assert::type(Form::class, $form);
