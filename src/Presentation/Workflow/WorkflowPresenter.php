<?php

declare(strict_types=1);

namespace Donut\Gui\Presentation\Workflow;

use Donut\BlockRepository;
use Donut\Format\Condition;
use Donut\Format\RunStep;
use Donut\Format\Step;
use Donut\Format\Workflow;
use Donut\Gui\KeyMap;
use Donut\Gui\ProblemMap;
use Donut\Gui\RowShape;
use Donut\Gui\StepMapper;
use Donut\Gui\StepPath;
use Donut\Gui\StepTree;
use Donut\Gui\WorkflowMapper;
use Donut\Gui\WorkflowRepository;
use Donut\Gui\WorkflowStore;
use Donut\Parser\ParseException;
use Donut\Validator\Result;
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

	private ?Workflow $editedWorkflow = null;


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
			// Chybějící nebo vadné kameny nejsou důvod schovat celý detail —
			// totéž pravidlo jako v blockNames(). Bez validátoru se hlásí jen
			// samotná chyba, ale hlavička, odkaz na obálku i strom kroků
			// zůstanou; jinak by čerstvý projekt bez blocks/ měl workflow,
			// se kterým už nejde nic dělat.
			$template->error = $e->getMessage();
			$result = new Result;
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


	public function actionEdit(?string $name = null): void
	{
		if ($name === null || $name === '') {
			return;
		}

		/** @var WorkflowEditTemplate $template */
		$template = $this->template;

		try {
			$this->editedWorkflow = (new WorkflowRepository($this->workflowDir()))->get(\basename($name));

		} catch (ParseException $e) {
			$template->error = $e->getMessage();
		}
	}


	public function renderEdit(?string $name = null): void
	{
		/** @var WorkflowEditTemplate $template */
		$template = $this->template;
		$template->name = ($name ?? '') === '' ? null : \basename((string) $name);
	}


	protected function createComponentHeaderForm(): Form
	{
		$form = new Form;

		$nameInput = $form->addText('name', 'Jméno')
			->setRequired('Jméno je povinné.')
			->addRule(Form::Pattern, 'Jméno smí obsahovat jen písmena, číslice, pomlčku a podtržítko.', '[A-Za-z0-9_-]+');

		if ($this->editedWorkflow !== null) {
			// Přejmenování GUI neumí — workflow se spouští jménem z cronu
			// a z CLI. Pořadí je závazné: setDisabled() maže hodnotu, takže
			// musí předcházet setDefaultValue(), a bez setOmitted(false) by
			// se zakázané pole z getValues() tiše vynechalo.
			$nameInput->setDisabled()
				->setDefaultValue($this->editedWorkflow->name)
				->setOmitted(false);
		}

		$form->addText('description', 'Popis');

		$post = $this->getParameter('do') === 'headerForm-submit'
			? $this->getHttpRequest()->getPost()
			: null;

		$inputs = $form->addContainer('inputs');

		// $this->editedWorkflow?->inputs ?? [] hlásí PHPStanu (level max)
		// falešně nullsafe.neverNull — rozdělení do proměnné to obchází,
		// stejně jako u WorkflowMapper::toWorkflow().
		$existingInputs = $this->editedWorkflow?->inputs;

		foreach (RowShape::of(\is_array($post) ? ($post['inputs'] ?? null) : null, \count($existingInputs ?? [])) as $i) {
			$row = $inputs->addContainer((string) $i);
			$row->addText('name');
			$row->addCheckbox('required');
			$row->addText('default');
			$row->addText('description');
		}

		$form->addSubmit('save', 'Uložit');
		$form->onSuccess[] = $this->headerFormSucceeded(...);

		if ($this->editedWorkflow !== null && !$this->getRequest()->isMethod('POST')) {
			$form->setDefaults(WorkflowMapper::toValues($this->editedWorkflow));
		}

		return $form;
	}


	public function headerFormSucceeded(Form $form): void
	{
		/** @var array<string, mixed> $values */
		$values = $form->getValues('array');

		$workflow = WorkflowMapper::toWorkflow($values, $this->editedWorkflow);

		try {
			$store = new WorkflowStore($this->workflowDir());

			// Zakládání nesmí přepsat workflow, které už existuje — writeFile()
			// přepisuje bez ptaní a uživatel by o obsah přišel bez jediné hlášky.
			if ($this->editedWorkflow === null && $store->exists($workflow->name)) {
				$form->addError("Workflow \"{$workflow->name}\" už existuje. Uprav ho, nebo zvol jiné jméno.");

				return;
			}

			$store->save($workflow);

		} catch (ParseException | WriteException $e) {
			// WorkflowStore::__construct() hází ParseException, když adresář
			// workflows neexistuje. WorkflowStore::save() volá
			// WorkflowWriter::writeFile(), který interní IOException vždycky
			// zabalí do WriteException — širší catch by PHPStan (level max)
			// odmítl jako dead catch.
			$form->addError($e->getMessage());

			return;
		}

		$this->redirect('detail', ['name' => $workflow->name]);
	}


	protected function createComponentDeleteWorkflowForm(): Form
	{
		$form = new Form;
		$form->addHidden('name');
		$form->addSubmit('save', 'Smazat');
		$form->onSuccess[] = $this->deleteWorkflowFormSucceeded(...);

		return $form;
	}


	public function deleteWorkflowFormSucceeded(Form $form): void
	{
		/** @var array{name: string} $values */
		$values = $form->getValues('array');

		// Jméno jde ze skrytého pole, ne z $this->editedWorkflow — mazání
		// nesmí záviset na tom, že se soubor podařilo naparsovat. Rozbité
		// workflow je zrovna to, které uživatel smazat potřebuje nejvíc.
		// basename() stejně jako jinde: jméno pochází z požadavku.
		$name = \basename($values['name']);

		if ($name === '') {
			$form->addError('Není co mazat.');

			return;
		}

		try {
			(new WorkflowStore($this->workflowDir()))->delete($name);

		} catch (ParseException | IOException $e) {
			$form->addError($e->getMessage());

			return;
		}

		$this->redirect('default');
	}


	private function workflowDir(): string
	{
		return WorkflowRepository::projectDir() . '/workflows';
	}
}
