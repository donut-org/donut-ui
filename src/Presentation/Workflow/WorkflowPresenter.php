<?php

declare(strict_types=1);

namespace Donut\Gui\Presentation\Workflow;

use Donut\BlockRepository;
use Donut\Format\Condition;
use Donut\Format\RunStep;
use Donut\Format\Step;
use Donut\Gui\KeyMap;
use Donut\Gui\ProblemMap;
use Donut\Gui\RowShape;
use Donut\Gui\StepMapper;
use Donut\Gui\StepPath;
use Donut\Gui\StepTree;
use Donut\Gui\WorkflowRepository;
use Donut\Gui\WorkflowStore;
use Donut\Parser\ParseException;
use Donut\Validator\Validator;
use Donut\Writer\WriteException;
use Nette\Application\UI\Form;
use Nette\Application\UI\Presenter;
use Nette\IOException;


/**
 * Workflow se čtou z pracovního adresáře serveru, stejně jako u CLI.
 * Nic se necachuje — soubor se čte při každém requestu.
 */
final class WorkflowPresenter extends Presenter
{
	private ?Step $editedStep = null;

	private ?StepPath $stepAt = null;

	private string $stepType = '';


	public function renderDefault(): void
	{
		/** @var WorkflowDefaultTemplate $template */
		$template = $this->template;

		$dir = WorkflowRepository::projectDir() . '/workflows';

		try {
			$repository = new WorkflowRepository($dir);

		} catch (ParseException $e) {
			$template->workflows = [];
			$template->error = $e->getMessage();
			$template->dir = $dir;

			return;
		}

		$template->workflows = $repository->loadAll();
		$template->error = null;
		$template->dir = $dir;
	}


	public function renderDetail(string $name, ?string $key = null): void
	{
		/** @var WorkflowDetailTemplate $template */
		$template = $this->template;
		$template->error = null;

		// $name je z query stringu. WorkflowRepository::get() ho hledá jako
		// klíč v seznamu skutečně existujících souborů, takže lomítka samy
		// o sobě nikam neukradou — basename() je navíc, aby to platilo, i
		// kdyby se cesta k souboru někdy zase skládala ručně.
		$name = \basename($name);

		try {
			$repository = new WorkflowRepository(WorkflowRepository::projectDir() . '/workflows');
			$workflow = $repository->get($name);

		} catch (ParseException $e) {
			$template->error = $e->getMessage();
			return;
		}

		try {
			$blocks = new BlockRepository(WorkflowRepository::projectDir() . '/blocks');
			$result = (new Validator($blocks))->validate($workflow);

		} catch (ParseException $e) {
			$template->error = $e->getMessage();
			return;
		}

		$problems = ProblemMap::fromResult($result);

		$template->workflow = $workflow;
		$template->problems = $problems;
		$template->workflowProblems = $problems->at(StepPath::workflow($workflow->name));

		$template->keys = KeyMap::of($workflow);

		// Prázdný řetězec z adresy znamená „nic nevybráno", ne klíč jménem "".
		$template->selectedKey = ($key ?? '') === '' ? null : $key;
		$template->selectedKeyExists = $template->selectedKey === null
			|| \in_array($template->selectedKey, $template->keys->keys(), true);
	}


	protected function createComponentStepTree(): StepTreeControl
	{
		$raw = $this->getParameter('name');

		return new StepTreeControl(
			WorkflowRepository::projectDir() . '/workflows',
			// basename() stejně jako v renderDetail(): jméno je z query
			// stringu a tohle je jediné místo, kde ho komponenta dostane.
			\basename(\is_string($raw) ? $raw : ''),
		);
	}


	public function actionStep(string $name, string $at, ?string $type = null): void
	{
		/** @var WorkflowStepTemplate $template */
		$template = $this->template;

		try {
			$this->stepAt = StepPath::parse($at);

			if ($this->stepAt->workflowName() !== $name) {
				throw new \InvalidArgumentException("Cesta \"{$at}\" nepatří workflow \"{$name}\".");
			}

			$repository = new WorkflowRepository(WorkflowRepository::projectDir() . '/workflows');
			$workflow = $repository->get($name);

			if ($type === null || $type === '') {
				// Úprava existujícího kroku.
				$this->editedStep = StepTree::get($workflow, $this->stepAt);
				$editedType = StepMapper::toValues($this->editedStep)['type'];
				$this->stepType = \is_string($editedType) ? $editedType : '';

			} else {
				// Nový krok — zatím nikde neuložený, jen typ a cílová pozice.
				$this->stepType = $type;
			}

		} catch (\InvalidArgumentException | \OutOfRangeException | ParseException $e) {
			$template->error = $e->getMessage();
		}
	}


	public function renderStep(string $name, string $at): void
	{
		/** @var WorkflowStepTemplate $template */
		$template = $this->template;
		$template->name = $name;
		$template->at = $at;
		// Typ z adresy se nečte znovu — actionStep() ho už vyřešil, a u úpravy
		// existujícího kroku ho odvodil z kroku samotného, ne z adresy.
		$template->type = $this->stepType;
		$template->blocks = $this->blockNames();
	}


	/**
	 * Jména kamenů do rozbalovacího seznamu. Chybějící adresář kamenů není
	 * důvod stránku shodit — seznam prostě zůstane prázdný.
	 *
	 * @return array<string, string>
	 */
	private function blockNames(): array
	{
		try {
			$names = (new BlockRepository(WorkflowRepository::projectDir() . '/blocks'))->getNames();

		} catch (ParseException) {
			return [];
		}

		return \array_combine($names, $names);
	}


	protected function createComponentStepForm(): Form
	{
		$form = new Form;
		$form->addHidden('type')->setDefaultValue($this->stepType);
		$form->addText('name', 'Název kroku');

		if ($this->stepType === 'run') {
			$form->addSelect('block', 'Kámen', $this->blockNames())
				->setRequired('Vyber kámen.');

			$shape = $this->rowShape();

			$in = $form->addContainer('in');

			foreach ($shape['in'] as $i) {
				$row = $in->addContainer((string) $i);
				$row->addText('key');
				$row->addText('value');
			}

			$out = $form->addContainer('out');

			foreach ($shape['out'] as $i) {
				$row = $out->addContainer((string) $i);
				$row->addSelect('channel', null, \array_combine(RunStep::Channels, RunStep::Channels))
					->setPrompt('—');
				$row->addText('value');
			}

			$form->addText('timeout', 'Timeout (s)')
				->addCondition(Form::Filled)
				->addRule(Form::Integer, 'Timeout musí být celé číslo.')
				->addRule(Form::Min, 'Timeout musí být kladný.', 1);

			$form->addRadioList('allowFailure', 'Povolené selhání', [
				'inherit' => 'převzít z kamene',
				'none' => 'jen exit 0',
				'any' => 'jakýkoliv exit kód',
				'list' => 'jen tyhle kódy:',
			])->setDefaultValue('inherit');

			$form->addText('allowFailureCodes')
				->addCondition(Form::Filled)
				->addRule(Form::Pattern, 'Kódy zadej jako čísla oddělená čárkou, třeba 0, 1.', '[0-9]+(\s*,\s*[0-9]+)*');

		} elseif ($this->stepType === 'set') {
			$form->addText('key', 'Klíč')->setRequired('Klíč je povinný.');
			$form->addText('value', 'Hodnota');

		} elseif ($this->stepType === 'if') {
			$form->addText('left', 'Vlevo');
			$form->addSelect('op', 'Operátor', \array_combine(Condition::Operators, Condition::Operators))
				->setRequired('Vyber operátor.');
			$form->addText('right', 'Vpravo');

		} elseif ($this->stepType === 'foreach') {
			$form->addText('over', 'Přes co')->setRequired('Vyplň, přes co se iteruje.');
			$form->addText('as', 'Pod jakým jménem')->setRequired('Vyplň jméno položky.');
		}

		$form->addSubmit('save', 'Uložit');
		$form->onSuccess[] = $this->stepFormSucceeded(...);

		if ($this->editedStep !== null && !$this->getRequest()->isMethod('POST')) {
			$form->setDefaults(StepMapper::toValues($this->editedStep));
		}

		return $form;
	}


	/**
	 * @return array{in: array<int, int>, out: array<int, int>}
	 */
	private function rowShape(): array
	{
		// Jiný signál nenese in/out vůbec — bez téhle podmínky by se formulář
		// sestavil s nula řádky a stránka by ukázala prázdný krok, který ve
		// skutečnosti prázdný není.
		$post = $this->getParameter('do') === 'stepForm-submit'
			? $this->getHttpRequest()->getPost()
			: null;

		$in = \is_array($post) ? ($post['in'] ?? null) : null;
		$out = \is_array($post) ? ($post['out'] ?? null) : null;

		$step = $this->editedStep;

		return [
			'in' => RowShape::of($in, $step instanceof RunStep ? \count($step->in) : 0),
			'out' => RowShape::of($out, $step instanceof RunStep ? \count($step->out) : 0),
		];
	}


	public function stepFormSucceeded(Form $form): void
	{
		/** @var array<string, mixed> $values */
		$values = $form->getValues('array');
		$rawName = $this->getParameter('name');
		$name = \is_string($rawName) ? $rawName : '';

		try {
			// Typ rozhoduje server, ne skrytý input z POSTu — formulář byl
			// sestavený podle $this->stepType a POST se stejným typem musí
			// souhlasit; jinak (foreach → set apod.) by keepChildren() níž
			// neměl na čem rozhodnout a podstrom by tiše zmizel.
			$step = StepMapper::toStep(['type' => $this->stepType] + $values);
			$at = $this->stepAt;

			if ($at === null) {
				throw new \InvalidArgumentException('Chybí cesta ke kroku.');
			}

			$repository = new WorkflowRepository(WorkflowRepository::projectDir() . '/workflows');
			$workflow = $repository->get($name);

			// Úprava if nebo foreach nesmí zahodit jejich větve — formulář
			// je needituje, takže se přenesou z původního kroku.
			if ($this->editedStep !== null) {
				$step = StepMapper::keepChildren($this->editedStep, $step);
				$workflow = StepTree::replace($workflow, $at, $step);

			} else {
				$workflow = StepTree::insert($workflow, $at, $step);
			}

			(new WorkflowStore(WorkflowRepository::projectDir() . '/workflows'))->save($workflow);

		} catch (\InvalidArgumentException | \OutOfRangeException | ParseException | WriteException | IOException $e) {
			$form->addError($e->getMessage());

			return;
		}

		$this->redirect('detail', ['name' => $name]);
	}
}
