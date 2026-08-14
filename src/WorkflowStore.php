<?php

declare(strict_types=1);

namespace Donut\Gui;

use Donut\Format\Workflow;
use Donut\Parser\ParseException;
use Donut\Writer\WorkflowWriter;


/**
 * Zápis workflow na disk.
 *
 * Jediné místo, které ví, že workflow jménem "card-dev" bydlí
 * v <adresář>/card-dev.json. Zapisovač v donutu cestu vědomě neodvozuje ze
 * jména, jen ověřuje, že spolu sedí — složit ji musí někdo, a je to tohle.
 *
 * Čtení tady není: umí ho WorkflowRepository, který prezentér už používá.
 * Zakládání a mazání patří do druhého projektu editace workflow.
 */
final class WorkflowStore
{
	private readonly WorkflowWriter $writer;


	/**
	 * @throws ParseException když adresář neexistuje
	 */
	public function __construct(
		private readonly string $directory,
	) {
		if (!\is_dir($directory)) {
			throw new ParseException("Adresář s workflow '{$directory}' neexistuje.");
		}

		$this->writer = new WorkflowWriter;
	}


	public function path(string $name): string
	{
		return $this->directory . '/' . $name . '.json';
	}


	/**
	 * @throws \Donut\Writer\WriteException když soubor nejde zapsat
	 */
	public function save(Workflow $workflow): void
	{
		$this->writer->writeFile($workflow, $this->path($workflow->name));
	}
}
