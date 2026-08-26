<?php

declare(strict_types=1);

namespace Donut\Gui;

use Donut\Format\Workflow;
use Donut\MissingDir;
use Donut\Parser\ParseException;
use Donut\Writer\WorkflowWriter;
use Nette\Utils\FileSystem;


/**
 * Writing workflows to disk.
 *
 * The one place that knows the workflow named "card-dev" lives at
 * <directory>/card-dev.json. The writer in donut deliberately doesn't
 * derive the path from the name, only checks the two agree — assembling
 * it is someone's job, and it's this one.
 *
 * There's no reading here: WorkflowRepository does that, and the presenter
 * already uses it.
 */
final class WorkflowStore
{
	private readonly WorkflowWriter $writer;


	/**
	 * @throws ParseException when the directory doesn't exist
	 */
	public function __construct(
		private readonly string $directory,
	) {
		if (!\is_dir($directory)) {
			throw new ParseException(
				"Workflows directory '{$directory}' does not exist. " . MissingDir::hint($directory)
			);
		}

		$this->writer = new WorkflowWriter;
	}


	public function path(string $name): string
	{
		return $this->directory . '/' . $name . '.json';
	}


	/**
	 * @throws \Donut\Writer\WriteException when the file can't be written
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
	 * @throws ParseException when the workflow doesn't exist
	 */
	public function delete(string $name): void
	{
		if (!$this->exists($name)) {
			throw new ParseException("Workflow \"{$name}\" does not exist. Searched in: {$this->directory}");
		}

		FileSystem::delete($this->path($name));
	}
}
