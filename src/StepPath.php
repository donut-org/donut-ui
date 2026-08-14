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


	/**
	 * Cesta pro problém, který nepatří žádnému kroku, ale celému workflow —
	 * stejný tvar, jaký pro ně skládá Donut\Validator\Validator::validate().
	 */
	public static function workflow(string $workflowName): self
	{
		return new self("{$workflowName}.json");
	}


	/**
	 * Cesta ke kroku z adresy. Přijímá jen tvar, který končí indexem —
	 * `…:steps[7].then` je seznam, ne krok, a jako cíl editace nedává smysl.
	 *
	 * @throws \InvalidArgumentException
	 */
	public static function parse(string $path): self
	{
		if (!\preg_match('~^[^:]+\.json:steps\[\d+](\.(then|else|steps)\[\d+])*$~', $path)) {
			throw new \InvalidArgumentException("\"{$path}\" není cesta ke kroku.");
		}

		return new self($path);
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


	public function workflowName(): string
	{
		$colon = \strpos($this->path, ':');
		$head = $colon === false ? $this->path : \substr($this->path, 0, $colon);

		return \substr($head, 0, -\strlen('.json'));
	}


	/**
	 * Dvojice *jméno kolekce* + *index*, v pořadí od kořene.
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
