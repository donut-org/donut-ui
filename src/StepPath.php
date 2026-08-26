<?php

declare(strict_types=1);

namespace Donut\Gui;


/**
 * A step path in the shape that Donut\Validator\Validator assembles it.
 *
 * Exists so that the template, when walking the step tree, can tell which
 * problems belong to this particular step. The shape is a contract with
 * donut and is pinned by its test tests/Donut/Validator.location.phpt.
 *
 * Immutable: neither index() nor child() mutates the original object,
 * because one path branches into several children.
 */
final class StepPath implements \Stringable
{
	private function __construct(
		private readonly string $path,
	) {
	}


	public static function root(string $workflowName): self
	{
		return new self("{$workflowName}.json:steps");
	}


	/**
	 * The path for a problem that doesn't belong to any step, but to the
	 * whole workflow — the same shape that Donut\Validator\Validator::validate()
	 * assembles for it.
	 */
	public static function workflow(string $workflowName): self
	{
		return new self("{$workflowName}.json");
	}


	/**
	 * A step path from a URL. Accepts only a shape that ends in an index —
	 * `…:steps[7].then` is a list, not a step, and makes no sense as an edit
	 * target.
	 *
	 * @throws \InvalidArgumentException
	 */
	public static function parse(string $path): self
	{
		// Surrounding whitespace would end up in the workflow name, and the
		// path would then point to a file no one created. Whitespace inside
		// the name is legitimate — the name must equal the file's name, and
		// that may contain it. [^:\r\n], not just [^:] — the character class
		// alone doesn't exclude \n, so a newline in the middle of the name
		// would pass (trim() only catches one at the edge). Whitespace
		// inside the name remains legitimate.
		if (\trim($path) !== $path || !\preg_match('~^[^:\r\n]+\.json:steps\[\d+](\.(then|else|steps)\[\d+])*\z~', $path)) {
			throw new \InvalidArgumentException("\"{$path}\" is not a step path.");
		}

		return new self($path);
	}


	public function index(int $i): self
	{
		return new self("{$this->path}[{$i}]");
	}


	/**
	 * @param string $property then, else or steps — the name of the
	 *                         collection being descended into
	 */
	public function child(string $property): self
	{
		return new self("{$this->path}.{$property}");
	}


	public function workflowName(): string
	{
		$colon = \strpos($this->path, ':');
		$head = $colon === false ? $this->path : \substr($this->path, 0, $colon);

		return \substr($head, 0, -\strlen('.json'));
	}


	/**
	 * Pairs of *collection name* + *index*, in order from the root.
	 * `steps[7].then[0]` → `[['steps', 7], ['then', 0]]`.
	 *
	 * @return list<array{string, int}>
	 */
	public function segments(): array
	{
		$colon = \strpos($this->path, ':');

		if ($colon === false) {
			return [];
		}

		\preg_match_all(
			'~(steps|then|else)\[(\d+)]~',
			\substr($this->path, $colon + 1),
			$matches,
			\PREG_SET_ORDER,
		);

		return \array_map(
			fn(array $m): array => [$m[1], (int) $m[2]],
			$matches,
		);
	}


	public function __toString(): string
	{
		return $this->path;
	}
}
