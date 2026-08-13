<?php

declare(strict_types=1);

namespace Donut\Gui;

use Donut\BlockRepository;
use Donut\Format\Block;
use Donut\Parser\ParseException;
use Donut\Writer\BlockWriter;
use Nette\Utils\FileSystem;


/**
 * Kameny na disku: čtení, zápis, mazání.
 *
 * Jediné místo, které ví, že kámen jménem "curl-get" bydlí
 * v <adresář>/curl-get.json. Zapisovač v donutu cestu vědomě neodvozuje ze
 * jména, jen ověřuje, že spolu sedí — složit ji musí někdo, a je to tohle.
 *
 * Repository se po každém zápisu zahodí: GUI nemá cache a při každém
 * requestu čte znovu, takže by drželo neaktuální seznam souborů.
 */
final class BlockStore
{
	private readonly BlockWriter $writer;

	private ?BlockRepository $repository = null;


	/**
	 * @throws ParseException když adresář neexistuje
	 */
	public function __construct(
		private readonly string $directory,
	) {
		$this->writer = new BlockWriter;

		if (!\is_dir($directory)) {
			throw new ParseException("Adresář s kameny '{$directory}' neexistuje.");
		}
	}


	public function path(string $name): string
	{
		return $this->directory . '/' . $name . '.json';
	}


	public function exists(string $name): bool
	{
		return $this->repository()->has($name);
	}


	/**
	 * @throws ParseException
	 */
	public function get(string $name): Block
	{
		return $this->repository()->get($name);
	}


	/** @return array<int, string> */
	public function names(): array
	{
		return $this->repository()->getNames();
	}


	/**
	 * Vadný soubor nesmí schovat ostatní — stejné pravidlo jako
	 * u `donut --list` a WorkflowRepository::loadAll().
	 *
	 * @return array<string, Block|string> jméno => kámen, nebo hláška o chybě
	 */
	public function loadAll(): array
	{
		$loaded = [];

		foreach ($this->names() as $name) {
			try {
				$loaded[$name] = $this->get($name);

			} catch (ParseException $e) {
				$loaded[$name] = $e->getMessage();
			}
		}

		return $loaded;
	}


	public function save(Block $block): void
	{
		$this->writer->writeFile($block, $this->path($block->name));
		$this->repository = null;
	}


	/**
	 * @throws ParseException když kámen neexistuje
	 */
	public function delete(string $name): void
	{
		if (!$this->exists($name)) {
			throw new ParseException("Kámen \"{$name}\" neexistuje. Hledal jsem v: {$this->directory}");
		}

		FileSystem::delete($this->path($name));
		$this->repository = null;
	}


	private function repository(): BlockRepository
	{
		return $this->repository ??= new BlockRepository($this->directory);
	}
}
