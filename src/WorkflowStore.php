<?php

declare(strict_types=1);

namespace Donut\Gui;

use Donut\Format\Workflow;
use Donut\Parser\ParseException;
use Donut\Writer\WorkflowWriter;
use Nette\Utils\FileSystem;


/**
 * Zápis workflow na disk.
 *
 * Jediné místo, které ví, že workflow jménem "card-dev" bydlí
 * v <adresář>/card-dev.json. Zapisovač v donutu cestu vědomě neodvozuje ze
 * jména, jen ověřuje, že spolu sedí — složit ji musí někdo, a je to tohle.
 *
 * Čtení tady není: umí ho WorkflowRepository, který prezentér už používá.
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


	public function exists(string $name): bool
	{
		return \is_file($this->path($name));
	}


	/**
	 * @throws ParseException když workflow neexistuje
	 */
	public function delete(string $name): void
	{
		if (!$this->exists($name)) {
			throw new ParseException("Workflow \"{$name}\" neexistuje. Hledal jsem v: {$this->directory}");
		}

		FileSystem::delete($this->path($name));
	}
}
