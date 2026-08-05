<?php

declare(strict_types=1);

namespace Donut\Gui;


/**
 * Cesta ke kroku ve tvaru, v jakém ji skládá Donut\Validator\Validator.
 *
 * Existuje proto, aby šablona při procházení stromu kroků uměla říct, které
 * problémy patří právě tomuhle kroku. Tvar je smlouva s donutem a je připnutý
 * jeho testem tests/Donut/Validator.location.phpt.
 *
 * Neměnná: index() ani child() nemění původní objekt, protože jedna cesta se
 * větví do víc dětí.
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


	public function index(int $i): self
	{
		return new self("{$this->path}[{$i}]");
	}


	/**
	 * @param string $property then, else nebo steps — jméno kolekce, do které
	 *                         se sestupuje
	 */
	public function child(string $property): self
	{
		return new self("{$this->path}.{$property}");
	}


	public function __toString(): string
	{
		return $this->path;
	}
}
