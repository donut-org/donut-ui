<?php

declare(strict_types=1);

namespace Donut\Gui\Presentation\Block;

use Donut\BlockRepository;
use Donut\Format\Block;
use Donut\Gui\BlockMapper;
use Donut\Gui\BlockStore;
use Donut\Gui\WorkflowRepository;
use Donut\Parser\ParseException;
use Donut\Validator\BlockValidator;
use Nette\Application\UI\Form;
use Nette\Application\UI\Presenter;


final class BlockPresenter extends Presenter
{
	private ?Block $editovany = null;


	public function renderDefault(): void
	{
		/** @var BlockDefaultTemplate $template */
		$template = $this->template;

		$dir = WorkflowRepository::projectDir() . '/blocks';

		try {
			$repository = new BlockRepository($dir);

		} catch (ParseException $e) {
			$template->blocks = [];
			$template->error = $e->getMessage();
			$template->dir = $dir;

			return;
		}

		$blocks = [];

		foreach ($repository->getNames() as $name) {
			try {
				$blocks[$name] = $repository->get($name);

			} catch (ParseException $e) {
				// Vadný soubor nesmí schovat ostatní — stejné pravidlo jako
				// u `donut --list`.
				$blocks[$name] = $e->getMessage();
			}
		}

		$template->blocks = $blocks;
		$template->error = null;
		$template->dir = $dir;
	}


	public function actionEdit(?string $name = null): void
	{
		if ($name === null || $name === '') {
			return;
		}

		try {
			$this->editovany = $this->store()->get($name);

		} catch (ParseException $e) {
			/** @var BlockEditTemplate $template */
			$template = $this->template;
			$template->error = $e->getMessage();
		}
	}


	public function renderEdit(?string $name = null): void
	{
		/** @var BlockEditTemplate $template */
		$template = $this->template;
		$template->name = $name === '' ? null : $name;
	}


	protected function createComponentBlockForm(): Form
	{
		$form = new Form;
		$shape = $this->formShape();

		$form->addText('name', 'Jméno')
			->setRequired('Jméno je povinné.')
			->addRule(Form::Pattern, 'Jméno smí obsahovat jen písmena, číslice, pomlčku a podtržítko.', '[A-Za-z0-9_-]+');

		$form->addText('description', 'Popis');
		$form->addText('command', 'Příkaz')->setRequired('Příkaz je povinný.');

		$args = $form->addContainer('args');

		foreach ($shape['args'] as $g => $indexes) {
			$group = $args->addContainer((string) $g);

			foreach ($indexes as $a) {
				$group->addText((string) $a);
			}
		}

		$inputs = $form->addContainer('inputs');

		foreach ($shape['inputs'] as $i) {
			$row = $inputs->addContainer((string) $i);
			$row->addText('name');
			$row->addCheckbox('required');
			$row->addText('default');
			$row->addText('description');
		}

		$form->addCheckbox('hasStdin', 'Kámen čte stdin');
		$form->addCheckbox('stdinRequired', 'stdin je povinný');
		$form->addText('stdinDescription', 'Popis stdin');

		$form->addText('timeout', 'Timeout (s)')
			->addCondition(Form::Filled)
			->addRule(Form::Integer, 'Timeout musí být celé číslo.')
			->addRule(Form::Min, 'Timeout musí být kladný.', 1);

		$form->addRadioList('allowFailure', 'Povolené selhání', [
			'none' => 'jen exit 0',
			'any' => 'jakýkoliv exit kód',
			'list' => 'jen tyhle kódy:',
		])->setDefaultValue('none');

		$form->addText('allowFailureCodes')
			->addCondition(Form::Filled)
			->addRule(Form::Pattern, 'Kódy zadej jako čísla oddělená čárkou, třeba 0, 1.', '[0-9]+(\s*,\s*[0-9]+)*');

		$form->addSubmit('save', 'Uložit');
		$form->onSuccess[] = $this->blockFormSucceeded(...);

		if ($this->editovany !== null && !$this->getRequest()->isMethod('POST')) {
			$form->setDefaults((new BlockMapper)->toValues($this->editovany));
		}

		return $form;
	}


	public function blockFormSucceeded(Form $form): void
	{
		/** @var array<string, mixed> $values */
		$values = $form->getValues('array');

		$block = (new BlockMapper)->toBlock($values);
		$result = (new BlockValidator)->validate($block);

		/** @var BlockEditTemplate $template */
		$template = $this->template;
		$template->problems = \array_map(
			fn($problem): string => $problem->message,
			$result->getProblems(),
		);

		// Chyba blokuje, varování ne.
		if ($result->hasErrors()) {
			$form->addError('Kámen se neuložil — oprav chyby níž.');

			return;
		}

		$this->store()->save($block);
		$this->redirect('edit', ['name' => $block->name]);
	}


	/**
	 * Kolik řádků formulář má.
	 *
	 * Při POSTu se odvodí z došlých dat — JS řádky nikdy nepřečísluje, takže
	 * indexy můžou mít díry a kontejnery musí vzniknout přesně pro ty klíče,
	 * které dorazily. Při GETu se vezmou z načteného kamene, plus jeden
	 * prázdný řádek navíc, aby bylo kam psát.
	 *
	 * Klíče z POSTu se filtrují na číslice: jméno komponenty v Nette musí
	 * odpovídat [a-zA-Z0-9_]+ a nic jiného sem stejně nepatří.
	 *
	 * @return array{args: array<int, array<int, int>>, inputs: array<int, int>}
	 */
	private function formShape(): array
	{
		$post = $this->getHttpRequest()->getPost();

		// getPost() bez argumentu vrací pole, ale návratový typ má mixed —
		// is_array() tu typ zúží pro PHPStan.
		if (\is_array($post) && $post !== []) {
			return [
				'args' => self::nestedIndexes($post['args'] ?? []),
				'inputs' => self::indexes($post['inputs'] ?? []),
			];
		}

		$args = [];
		$inputs = [];

		if ($this->editovany !== null) {
			foreach (\array_values($this->editovany->args) as $g => $group) {
				$args[$g] = \array_keys(\array_values($group));
			}

			$inputs = \array_keys(\array_values($this->editovany->inputs));
		}

		// Jeden prázdný řádek navíc, aby měl uživatel kam psát i bez JS.
		$args[] = [0];
		$inputs[] = \count($inputs);

		return ['args' => $args, 'inputs' => $inputs];
	}


	/**
	 * @param  mixed $raw
	 * @return array<int, int>
	 */
	private static function indexes(mixed $raw): array
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


	/**
	 * @param  mixed $raw
	 * @return array<int, array<int, int>>
	 */
	private static function nestedIndexes(mixed $raw): array
	{
		if (!\is_array($raw)) {
			return [];
		}

		$shape = [];

		foreach (self::indexes($raw) as $key) {
			$shape[$key] = self::indexes($raw[$key]);
		}

		return $shape;
	}


	private function store(): BlockStore
	{
		return new BlockStore(WorkflowRepository::projectDir() . '/blocks');
	}
}
