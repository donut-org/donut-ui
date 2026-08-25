<?php

declare(strict_types=1);

namespace Donut\Gui\Presentation\Block;

use Donut\BlockRepository;
use Donut\Format\Block;
use Donut\Format\Workflow;
use Donut\Gui\BlockMapper;
use Donut\Gui\BlockStore;
use Donut\Gui\BlockUsage;
use Donut\Gui\FormFactory;
use Donut\Gui\WorkflowRepository;
use Donut\MissingDir;
use Donut\Parser\ParseException;
use Donut\Profile;
use Donut\Validator\BlockValidator;
use Donut\Validator\Problem;
use Donut\Writer\WriteException;
use Nette\Application\UI\Form;
use Nette\Application\UI\Presenter;
use Nette\IOException;


final class BlockPresenter extends Presenter
{
	public function __construct(private readonly Profile $profile)
	{
	}


	private ?Block $edited = null;

	private ?Block $detail = null;


	public function renderDefault(): void
	{
		/** @var BlockDefaultTemplate $template */
		$template = $this->template;

		$dir = $this->profile->blocksDir();

		try {
			$repository = new BlockRepository($dir);

		} catch (ParseException $e) {
			// Jediná chyba, kterou sem BlockRepository pustí, je chybějící
			// adresář — jinde v konstruktoru neselže, takže návod platí vždycky
			// a na is_dir() se tu (na rozdíl od Workflow:detail) ptát nemusíme.
			// Hláška je z donutu (sdílí ji s CLI), návod k ní patří sem — do
			// prezenteru, stejně jako u Workflow:detail.
			$template->blocks = [];
			$template->error = $e->getMessage() . ' ' . MissingDir::hint($dir);
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
		$template->usage = BlockUsage::of($this->loadWorkflows());
		$template->dir = $dir;
	}


	public function actionDetail(string $name): void
	{
		try {
			$this->detail = $this->store()->get($name);

		} catch (ParseException $e) {
			// Chybějící adresář i nenaparsovatelný soubor končí stejně:
			// stránka se vykreslí s hláškou a s odkazem na editaci, protože
			// rozbitý kámen je ten, u kterého je cesta k opravě potřeba
			// nejvíc. Totéž pravidlo má Workflow:detail — odkaz „upravit
			// hlavičku" je v detail.latte mimo {if $workflow !== null}.
			/** @var BlockDetailTemplate $template */
			$template = $this->template;
			$template->error = $e->getMessage();
		}
	}


	public function renderDetail(string $name): void
	{
		/** @var BlockDetailTemplate $template */
		$template = $this->template;
		$template->name = $name;
		$template->block = $this->detail;
		$template->usedBy = BlockUsage::of($this->loadWorkflows())[$name] ?? [];
	}


	public function actionEdit(?string $name = null): void
	{
		if ($name === null || $name === '') {
			return;
		}

		try {
			$this->edited = $this->store()->get($name);

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
		$template->usedBy = $template->name === null
			? []
			: (BlockUsage::of($this->loadWorkflows())[$template->name] ?? []);
	}


	protected function createComponentBlockForm(): Form
	{
		$form = FormFactory::create();
		$shape = $this->formShape();

		$name = $form->addText('name', 'Jméno')
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
			// aria-label místo popisku: co do sloupce patří, říká hlavička
			// tabulky, jenže <th> pojmenovává buňku, ne <input> uvnitř ní —
			// odečítač obrazovky by jinak četl jen „textbox". Doslova jako
			// ve WorkflowPresenter::createComponentHeaderForm(); checkbox si
			// o atribut musí říct takhle, ze šablony by skončil na obalujícím
			// <label>, kde se ztratí.
			$row->addText('name')->setHtmlAttribute('aria-label', 'Jméno');
			$row->addCheckbox('required')->setHtmlAttribute('aria-label', 'Povinný');
			$row->addText('default')->setHtmlAttribute('aria-label', 'Výchozí');
			$row->addText('description')->setHtmlAttribute('aria-label', 'Popis');
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

		if ($this->edited !== null) {
			// Přejmenování je mimo návrh — spec: „Jméno je ve formuláři jen
			// při zakládání." Pole zůstává vidět kvůli kontextu, ale je
			// needitovatelné a jeho hodnota jde vždy z načteného kamene, ne
			// z POSTu — setDisabled() ochrání i ručně poslaný požadavek
			// s jiným jménem (jinak by šlo tímhle kanálem přepsat cizí kámen).
			// Pořadí volání je důležité: setDisabled() volá interně
			// setValue(null), takže setDefaultValue() musí přijít až po něm.
			// setOmitted(false) je nutné taky — needitovatelné pole je bez
			// něj z getValues() potichu vynechané (Nette default pro disabled
			// kontrolky) a BlockMapper by dostal jméno '' místo skutečného.
			$name->setDisabled()->setDefaultValue($this->edited->name)->setOmitted(false);

			// Táž otázka jako ve formShape(), a proto tentýž mechanismus: ne
			// „je to POST?", ale „patří ten POST tomuhle formuláři?". Po cizím
			// signálu (třeba neúspěšném mazání) se hodnoty musí vzít z disku —
			// jinak se formulář překreslí prázdný a „Uložit" ho tak zapíše.
			if (!$this->isFormPost('blockForm-submit')) {
				$form->setDefaults((new BlockMapper)->toValues($this->edited));
			}
		}

		return $form;
	}


	public function blockFormSucceeded(Form $form): void
	{
		/** @var array<string, mixed> $values */
		$values = $form->getValues('array');

		$block = (new BlockMapper)->toBlock($values);

		try {
			$store = $this->store();

			// Zakládání nesmí přepsat kámen, který už existuje — writeFile()
			// přepisuje bez ptaní a uživatel by o obsah přišel bez jediné hlášky.
			// Editace na tohle narazit nemůže — jméno je při ní needitovatelné
			// (viz createComponentBlockForm).
			if ($this->edited === null && $store->exists($block->name)) {
				$form->addError("Kámen \"{$block->name}\" už existuje. Uprav ho, nebo zvol jiné jméno.");

				return;
			}

		} catch (ParseException $e) {
			// BlockStore::__construct() hází ParseException, když adresář
			// blocks neexistuje.
			$form->addError($e->getMessage());

			return;
		}

		$result = (new BlockValidator)->validate($block);

		/** @var BlockEditTemplate $template */
		$template = $this->template;
		$template->errors = self::messages($result->getErrors());
		$template->warnings = self::messages($result->getWarnings());

		// Chyba blokuje, varování ne.
		if ($result->hasErrors()) {
			$form->addError('Kámen se neuložil — oprav chyby níž.');

			return;
		}

		try {
			$store->save($block);

		} catch (WriteException $e) {
			$form->addError($e->getMessage());

			return;
		}

		$this->redirect('edit', ['name' => $block->name]);
	}


	/**
	 * @param  array<int, Problem> $problems
	 * @return array<int, string>
	 */
	private static function messages(array $problems): array
	{
		return \array_map(fn(Problem $problem): string => $problem->message, $problems);
	}


	protected function createComponentDeleteForm(): Form
	{
		$form = FormFactory::create();
		$form->addHidden('name');
		$form->addSubmit('delete', 'Smazat')
			->getControlPrototype()->setAttribute('class', 'btn btn-danger');
		$form->onSuccess[] = $this->deleteFormSucceeded(...);

		return $form;
	}


	public function deleteFormSucceeded(Form $form): void
	{
		/** @var array{name: string} $values */
		$values = $form->getValues('array');

		// basename() stejně jako v deleteWorkflowFormSucceeded(): jméno pochází
		// z požadavku. Samo o sobě nic neukradne — BlockStore::exists() se ptá
		// do mapy klíčované basename($path, '.json'), takže jméno s lomítkem
		// v ní nikdy nemůže být klíčem — ale ochrana má být vidět na obou
		// polovinách GUI a nemá viset na vzdálené implementaci.
		$name = \basename($values['name']);

		// Stejný guard jako v deleteWorkflowFormSucceeded(): bez něj se prázdné
		// jméno ohlásí jako „Kámen "" neexistuje", což o ničem nevypovídá.
		if ($name === '') {
			$form->addError('Není co mazat.');

			return;
		}

		$usage = BlockUsage::of($this->loadWorkflows());

		// Chyba blokuje, stejně jako u ukládání. Smazat kámen, na který se
		// odkazuje workflow, není varování — je to rozbití něčeho, co běželo.
		// Šablona tlačítko v takovém případě nevykreslí; tohle je druhá
		// pojistka pro ručně poslaný POST.
		if (isset($usage[$name])) {
			$form->addError(
				"Kámen \"{$name}\" nejde smazat — používá ho: " . \implode(', ', $usage[$name]) . '.'
			);

			return;
		}

		try {
			$this->store()->delete($name);

		} catch (ParseException | IOException $e) {
			$form->addError($e->getMessage());

			return;
		}

		$this->redirect('default');
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
		// Jiný signál (třeba deleteForm-submit) nenese args/inputs vůbec —
		// bez týhle podmínky by se blockForm sestavil s nula skupinami
		// a nula řádky a stránka by při odmítnutém mazání ukázala prázdný
		// obsah kamene, který ve skutečnosti pořád existuje.
		$post = $this->isFormPost('blockForm-submit')
			? $this->getHttpRequest()->getPost()
			: [];

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

		if ($this->edited !== null) {
			foreach (\array_values($this->edited->args) as $g => $group) {
				$args[$g] = \array_keys(\array_values($group));
			}

			$inputs = \array_keys(\array_values($this->edited->inputs));
		}

		// Jeden prázdný řádek navíc, aby měl uživatel kam psát i bez JS.
		$args[] = [0];
		$inputs[] = \count($inputs);

		return ['args' => $args, 'inputs' => $inputs];
	}


	/**
	 * Patří došlý POST formuláři daného signálu? Na stránce editace jsou
	 * formuláře dva a data toho druhého (deleteForm) o obsahu kamene neříkají
	 * nic — jedna odpověď pro tvar formuláře i pro jeho výchozí hodnoty.
	 *
	 * Na HTTP metodu se ptát musíme: signál `do=blockForm-submit` se dá mít
	 * v adrese i na GETu (ručně složená adresa, záložka, historie), a tam
	 * getPost() vrátí prázdné pole. Bez téhle podmínky by se formulář
	 * vykreslil prázdný a „Uložit" by tak kámen zapsalo.
	 *
	 * Tvar je schválně stejný jako u WorkflowPresenter::isFormPost() — jeden
	 * idiom na jednu otázku v obou polovinách GUI.
	 */
	private function isFormPost(string $signal): bool
	{
		return $this->getRequest()->isMethod('POST')
			&& $this->getParameter('do') === $signal;
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
		return new BlockStore($this->profile->blocksDir());
	}


	/** @return array<string, Workflow> */
	private function loadWorkflows(): array
	{
		$workflows = [];

		try {
			$repository = new WorkflowRepository($this->profile->workflowsDir());

			foreach ($repository->loadAll() as $name => $workflow) {
				// Vadné workflow nesmí shodit stránku — o použití kamene
				// neřekne nic, ale zbytek má fungovat.
				if (!\is_string($workflow)) {
					$workflows[$name] = $workflow;
				}
			}

		} catch (ParseException) {
			// Bez adresáře workflows se použití prostě nezobrazí.
		}

		return $workflows;
	}
}
