<?php

declare(strict_types=1);

namespace Donut\Gui\Presentation\Workflow;

use Donut\Format\Workflow;
use Donut\Gui\KeyMap;
use Donut\Gui\ProblemMap;
use Donut\Gui\StepPath;
use Donut\Gui\StepTree;
use Donut\Gui\WorkflowRepository;
use Donut\Gui\WorkflowStore;
use Donut\Parser\ParseException;
use Donut\Writer\WriteException;
use Nette\Application\Attributes\Requires;
use Nette\Application\UI\Control;
use Nette\IOException;


/**
 * Strom kroků workflow i s ovládáním.
 *
 * Jako komponenta proto, že má vlastní šablonu, vlastní signály a vlastní
 * stav. Prezentéru tím zůstane seznam, detail a formuláře.
 *
 * Jméno workflow a adresář dostává z konstruktoru, ne z adresy: signál běží
 * dřív než render, takže se na parametry akce spolehnout nedá, a jméno má
 * takhle jediné místo, kde se ověřuje.
 */
final class StepTreeControl extends Control
{
	private ?string $error = null;


	public function __construct(
		private readonly string $directory,
		private readonly string $name,
	) {
	}


	public function render(
		Workflow $workflow,
		ProblemMap $problems,
		KeyMap $keys,
		?string $selected,
	): void {
		/** @var StepTreeTemplate $template */
		$template = $this->template;
		$template->setFile(__DIR__ . '/stepTree.latte');
		$template->steps = $workflow->steps;
		$template->path = StepPath::root($workflow->name);
		$template->problems = $problems;
		$template->keys = $keys;
		$template->selected = $selected;
		$template->name = $this->name;
		$template->error = $this->error;
		$template->render();
	}


	#[Requires(methods: 'POST')]
	public function handleMoveUp(): void
	{
		$this->applyToStep(fn(Workflow $w, StepPath $at): Workflow => StepTree::moveUp($w, $at));
	}


	#[Requires(methods: 'POST')]
	public function handleMoveDown(): void
	{
		$this->applyToStep(fn(Workflow $w, StepPath $at): Workflow => StepTree::moveDown($w, $at));
	}


	#[Requires(methods: 'POST')]
	public function handleDeleteStep(): void
	{
		$this->applyToStep(fn(Workflow $w, StepPath $at): Workflow => StepTree::remove($w, $at));
	}


	/**
	 * Vzít cestu z POSTu, načíst workflow, provést operaci, uložit, vrátit se
	 * na přehled.
	 *
	 * Validace se **nespouští** — u workflow neblokuje, protože mezistavy
	 * přerovnávání jsou skoro vždycky neplatné. Problémy se ukážou v přehledu,
	 * kam se vzápětí vracíme.
	 *
	 * Při chybě se **nepřesměrovává**: redirect() hodí AbortException a chyba
	 * by se nikam nedostala. Flash zprávy k dispozici nejsou (žádná session),
	 * takže se nechá doběhnout render, který $error vykreslí.
	 *
	 * @param callable(Workflow, StepPath): Workflow $operation
	 */
	private function applyToStep(callable $operation): void
	{
		$raw = $this->getPresenter()->getHttpRequest()->getPost('at');

		try {
			$at = StepPath::parse(\is_string($raw) ? $raw : '');

			// Cesta nese jméno workflow; kdyby nesouhlasilo s tím, nad kterým
			// komponenta stojí, operace by sáhla do cizího souboru.
			if ($at->workflowName() !== $this->name) {
				throw new \InvalidArgumentException(
					"Cesta \"{$at}\" nepatří workflow \"{$this->name}\"."
				);
			}

			$workflow = (new WorkflowRepository($this->directory))->get($this->name);
			(new WorkflowStore($this->directory))->save($operation($workflow, $at));

		} catch (\InvalidArgumentException | \OutOfRangeException | ParseException | WriteException | IOException $e) {
			$this->error = $e->getMessage();

			return;
		}

		$this->getPresenter()->redirect('detail', ['name' => $this->name]);
	}
}
