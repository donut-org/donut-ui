<?php

declare(strict_types=1);

namespace Donut\Gui\Presentation\Workflow;

use Donut\BlockRepository;
use Donut\Format\Condition;
use Donut\Format\ForeachStep;
use Donut\Format\IfStep;
use Donut\Format\RunStep;
use Donut\Format\Step;
use Donut\Format\Workflow;
use Donut\Gui\KeyMap;
use Donut\Gui\ProblemMap;
use Donut\Gui\StepMapper;
use Donut\Gui\StepPath;
use Donut\Gui\StepTree;
use Donut\Gui\WorkflowRepository;
use Donut\Gui\WorkflowStore;
use Donut\Parser\ParseException;
use Donut\Validator\Validator;
use Donut\Writer\WriteException;
use Nette\Application\Attributes\Requires;
use Nette\Application\UI\Form;
use Nette\Application\UI\Presenter;
use Nette\IOException;


/**
 * Workflow se čtou z pracovního adresáře serveru, stejně jako u CLI.
 * Nic se necachuje — soubor se čte při každém requestu.
 */
final class WorkflowPresenter extends Presenter
{
	private ?string $stepError = null;

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
		$template->rootPath = StepPath::root($workflow->name);
		$template->workflowProblems = $problems->at(StepPath::workflow($workflow->name));

		$template->keys = KeyMap::of($workflow);

		// Prázdný řetězec z adresy znamená „nic nevybráno", ne klíč jménem "".
		$template->selectedKey = ($key ?? '') === '' ? null : $key;
		$template->selectedKeyExists = $template->selectedKey === null
			|| \in_array($template->selectedKey, $template->keys->keys(), true);

		$template->stepError = $this->stepError;
	}


	// Bez GET: mění soubor, a GET, který mění soubor, si najde přednačítač
	// v prohlížeči nebo prefetch odkazů. Dnes to platí i implicitně —
	// formuláře v steps.latte posílají jen POST — ale ať je to vynucené
	// a čitelné, ne jen náhoda toho, jak je vyplněný markup.
	#[Requires(methods: 'POST')]
	public function handleMoveUp(): void
	{
		$this->applyToStep(fn($workflow, $at) => StepTree::moveUp($workflow, $at));
	}


	#[Requires(methods: 'POST')]
	public function handleMoveDown(): void
	{
		$this->applyToStep(fn($workflow, $at) => StepTree::moveDown($workflow, $at));
	}


	#[Requires(methods: 'POST')]
	public function handleDeleteStep(): void
	{
		$this->applyToStep(fn($workflow, $at) => StepTree::remove($workflow, $at));
	}


	/**
	 * Společný obal pro všechny tři signály: vzít cestu z POSTu, načíst
	 * workflow, provést operaci, uložit, vrátit se na přehled.
	 *
	 * Validace se **nespouští** — u workflow neblokuje, protože mezistavy
	 * přerovnávání jsou skoro vždycky neplatné. Problémy se ukážou
	 * v přehledu, kam se vzápětí vracíme.
	 *
	 * Při chybě se **nepřesměrovává**: redirect() hodí AbortException a chyba
	 * by se nikam nedostala. Flash zprávy k dispozici nejsou (žádná session),
	 * takže se nechá doběhnout renderDetail(), který $stepError vykreslí.
	 *
	 * @param callable(Workflow, StepPath): Workflow $operation
	 */
	private function applyToStep(callable $operation): void
	{
		$rawName = $this->getParameter('name');
		// basename() stejně jako renderDetail() — jméno je z query stringu
		// a WorkflowRepository ho hledá jako klíč, takže lomítka samy o sobě
		// nikam neukradou, ale ať se s ním obě metody zachází stejně.
		$name = \basename(\is_string($rawName) ? $rawName : '');
		$raw = $this->getHttpRequest()->getPost('at');
		$dir = WorkflowRepository::projectDir() . '/workflows';

		try {
			$at = StepPath::parse(\is_string($raw) ? $raw : '');

			// Cesta nese jméno workflow; kdyby nesouhlasilo s adresou,
			// operace by sáhla do cizího souboru.
			if ($at->workflowName() !== $name) {
				throw new \InvalidArgumentException("Cesta \"{$at}\" nepatří workflow \"{$name}\".");
			}

			$workflow = (new WorkflowRepository($dir))->get($name);
			(new WorkflowStore($dir))->save($operation($workflow, $at));

		} catch (\InvalidArgumentException | \OutOfRangeException | ParseException | WriteException | IOException $e) {
			$this->stepError = $e->getMessage();

			return;
		}

		$this->redirect('detail', ['name' => $name]);
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
	 * Kolik řádků mají kontejnery in a out.
	 *
	 * Při POSTu se odvodí z došlých dat — JS řádky nikdy nepřečísluje, takže
	 * indexy můžou mít díry a kontejnery musí vzniknout přesně pro klíče,
	 * které dorazily. Jiný signál než stepForm-submit se ignoruje, jinak by
	 * se formulář sestavil s nula řádky.
	 *
	 * Klíče se filtrují na číslice: jméno komponenty v Nette musí odpovídat
	 * [a-zA-Z0-9_]+ a nic jiného sem stejně nepatří.
	 *
	 * @return array{in: list<int>, out: list<int>}
	 */
	private function rowShape(): array
	{
		$post = $this->getParameter('do') === 'stepForm-submit'
			? $this->getHttpRequest()->getPost()
			: [];

		if (\is_array($post) && $post !== []) {
			return [
				'in' => self::rowIndexes($post['in'] ?? []),
				'out' => self::rowIndexes($post['out'] ?? []),
			];
		}

		$in = [];
		$out = [];

		if ($this->editedStep instanceof RunStep) {
			$in = \array_keys(\array_values($this->editedStep->in));
			$out = \array_keys(\array_values($this->editedStep->out));
		}

		// Jeden prázdný řádek navíc, aby měl uživatel kam psát i bez JS.
		$in[] = \count($in);
		$out[] = \count($out);

		return ['in' => $in, 'out' => $out];
	}


	/**
	 * @param  mixed $raw
	 * @return list<int>
	 */
	private static function rowIndexes(mixed $raw): array
	{
		if (!\is_array($raw)) {
			return [];
		}

		$keys = [];

		foreach (\array_keys($raw) as $key) {
			if (\ctype_digit((string) $key)) {
				$keys[] = (int) $key;
			}
		}

		\sort($keys);

		return $keys;
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
				$step = self::keepChildren($this->editedStep, $step);
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


	/**
	 * Nový krok z formuláře nese prázdné větve; převezmi je z toho, který
	 * nahrazuje, aby úprava podmínky nesmazala celý podstrom.
	 */
	private static function keepChildren(Step $original, Step $updated): Step
	{
		if ($original instanceof IfStep && $updated instanceof IfStep) {
			return new IfStep($updated->condition, $original->then, $original->else, $updated->name);
		}

		if ($original instanceof ForeachStep && $updated instanceof ForeachStep) {
			return new ForeachStep($updated->over, $updated->as, $original->steps, $updated->name);
		}

		return $updated;
	}
}
