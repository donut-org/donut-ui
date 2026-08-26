<?php

declare(strict_types=1);

namespace Donut\Gui;

use Donut\BlockRepository;
use Donut\Format\Block;
use Donut\Parser\ParseException;
use Donut\Writer\BlockWriter;
use Nette\Utils\FileSystem;


/**
 * Blocks on disk: reading, writing, deleting.
 *
 * The one place that knows the block named "curl-get" lives at
 * <directory>/curl-get.json. The writer in donut deliberately doesn't
 * derive the path from the name, only checks the two agree — assembling
 * it is someone's job, and it's this one.
 *
 * The repository is discarded after every write: the GUI has no cache and
 * re-reads on every request, so a stale one would hold an outdated file
 * list.
 */
final class BlockStore
{
	private readonly BlockWriter $writer;

	private ?BlockRepository $repository = null;


	public function __construct(
		private readonly string $directory,
	) {
		$this->writer = new BlockWriter;
	}


	public function path(string $name): string
	{
		return $this->directory . '/' . $name . '.json';
	}


	/**
	 * Nothing can exist in a directory that doesn't exist — that's an answer,
	 * not an error. The overwrite guard in BlockPresenter asks this before
	 * every save, so throwing here would close the very path save() opens.
	 * The reads that report a missing directory are names() and get().
	 */
	public function exists(string $name): bool
	{
		return \is_dir($this->directory) && $this->repository()->has($name);
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
	 * A broken file must not hide the rest — same rule as `donut --list`
	 * and WorkflowRepository::loadAll().
	 *
	 * @return array<string, Block|string> name => block, or the error message
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


	/**
	 * Creates the directory: saving is the user asking for the file, and a
	 * fresh profile would otherwise be a dead end — the form fills in, the
	 * save fails, and there is nowhere in the GUI to fix it. Reading is a
	 * different matter and still reports a missing directory.
	 *
	 * @throws \Nette\IOException when the directory can't be created
	 * @throws \Donut\Writer\WriteException when the file can't be written
	 */
	public function save(Block $block): void
	{
		FileSystem::createDir($this->directory);
		$this->writer->writeFile($block, $this->path($block->name));
		$this->repository = null;
	}


	/**
	 * @throws ParseException when the block doesn't exist
	 */
	public function delete(string $name): void
	{
		if (!$this->exists($name)) {
			throw new ParseException("Block \"{$name}\" does not exist. Searched in: {$this->directory}");
		}

		FileSystem::delete($this->path($name));
		$this->repository = null;
	}


	private function repository(): BlockRepository
	{
		return $this->repository ??= new BlockRepository($this->directory);
	}
}
