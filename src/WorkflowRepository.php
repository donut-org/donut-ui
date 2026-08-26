<?php

declare(strict_types=1);

namespace Donut\Gui;

use Donut\Format\Workflow;
use Donut\MissingDir;
use Donut\Parser\ParseException;
use Donut\Parser\WorkflowParser;


/**
 * Workflows from the workflows/ directory, looked up by name — the
 * counterpart of Donut\BlockRepository for workflows.
 *
 * A missing directory is a different situation than an empty one — the
 * constructor throws for that reason, same as BlockRepository. `glob()` on
 * a nonexistent directory would return [] and "no workflows" would be
 * indistinguishable from "you ran me from the wrong place".
 */
final class WorkflowRepository
{
	private readonly WorkflowParser $parser;

	private readonly string $directory;

	/** @var array<string, string> name => file path */
	private array $files = [];


	/**
	 * @throws ParseException
	 */
	public function __construct(
		string $directory,
		?WorkflowParser $parser = null,
	) {
		$this->directory = $directory;
		$this->parser = $parser ?? new WorkflowParser;

		if (!\is_dir($directory)) {
			throw new ParseException(
				"Workflows directory '{$directory}' does not exist. " . MissingDir::hint($directory)
			);
		}

		$paths = \glob($directory . '/*.json');

		foreach ($paths === false ? [] : $paths as $path) {
			$this->files[\basename($path, '.json')] = $path;
		}

		\ksort($this->files);
	}


	public function has(string $name): bool
	{
		return isset($this->files[$name]);
	}


	/**
	 * @throws ParseException
	 */
	public function get(string $name): Workflow
	{
		if (!isset($this->files[$name])) {
			throw new ParseException("Workflow \"{$name}\" does not exist. Searched in: {$this->directory}");
		}

		return $this->parser->parseFile($this->files[$name]);
	}


	/** @return array<int, string> */
	public function getNames(): array
	{
		return \array_keys($this->files);
	}


	/**
	 * Reads all workflows. A broken file must not hide the rest — same rule
	 * as `donut --list`.
	 *
	 * @return array<string, Workflow|string> name => workflow, or the error message
	 */
	public function loadAll(): array
	{
		$loaded = [];

		foreach ($this->getNames() as $name) {
			try {
				$loaded[$name] = $this->get($name);

			} catch (ParseException $e) {
				$loaded[$name] = $e->getMessage();
			}
		}

		return $loaded;
	}
}
