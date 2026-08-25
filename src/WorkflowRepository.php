<?php

declare(strict_types=1);

namespace Donut\Gui;

use Donut\Format\Workflow;
use Donut\MissingDir;
use Donut\Parser\ParseException;
use Donut\Parser\WorkflowParser;


/**
 * Workflow z adresáře workflows/, hledaná podle jména — obdoba
 * Donut\BlockRepository pro workflow.
 *
 * Chybějící adresář je jiná situace než prázdný — konstruktor proto hází,
 * stejně jako BlockRepository. `glob()` na neexistujícím adresáři by vrátil
 * [] a "žádné workflow" by bylo k nerozeznání od "spustil jsi mě odjinud".
 */
final class WorkflowRepository
{
	private readonly WorkflowParser $parser;

	private readonly string $directory;

	/** @var array<string, string> jméno => cesta k souboru */
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
				"Adresář s workflow '{$directory}' neexistuje. " . MissingDir::hint($directory)
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
			throw new ParseException("Workflow \"{$name}\" neexistuje. Hledal jsem v: {$this->directory}");
		}

		return $this->parser->parseFile($this->files[$name]);
	}


	/** @return array<int, string> */
	public function getNames(): array
	{
		return \array_keys($this->files);
	}


	/**
	 * Přečte všechna workflow. Vadný soubor nesmí schovat ostatní — stejné
	 * pravidlo jako u `donut --list`.
	 *
	 * @return array<string, Workflow|string> jméno => workflow, nebo hláška o chybě
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


	public static function projectDir(): string
	{
		return \getcwd() ?: '.';
	}
}
