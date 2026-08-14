<?php

declare(strict_types=1);

namespace Donut\Gui\Presentation\Workflow;

use Donut\BlockRepository;
use Donut\Format\Workflow;
use Donut\Gui\KeyMap;
use Donut\Gui\ProblemMap;
use Donut\Gui\StepPath;
use Donut\Gui\StepTree;
use Donut\Gui\WorkflowRepository;
use Donut\Gui\WorkflowStore;
use Donut\Parser\ParseException;
use Donut\Validator\Validator;
use Donut\Writer\WriteException;
use Nette\Application\UI\Presenter;
use Nette\IOException;


/**
 * Workflow se čtou z pracovního adresáře serveru, stejně jako u CLI.
 * Nic se necachuje — soubor se čte při každém requestu.
 */
final class WorkflowPresenter extends Presenter
{
	private ?string $stepError = null;


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


	public function handleMoveUp(): void
	{
		$this->applyToStep(fn($workflow, $at) => StepTree::moveUp($workflow, $at));
	}


	public function handleMoveDown(): void
	{
		$this->applyToStep(fn($workflow, $at) => StepTree::moveDown($workflow, $at));
	}


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
		$name = \is_string($rawName) ? $rawName : '';
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
}
