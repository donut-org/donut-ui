<?php

declare(strict_types=1);

namespace Donut\Gui;


/**
 * Hodnota z formuláře na řetězec.
 *
 * Nette vrací z `getValues('array')` mixed — pole, null i objekty. Mapperům
 * jde vždycky o totéž: ořezaný řetězec, a prázdný řetězec brát jako „nic".
 * Bylo to dvakrát bajt po bajtu opsané (WorkflowMapper, InputMapper), tak je
 * to tady jednou.
 *
 * BlockMapper má vlastní instanční toStr()/orNull() — jiné rozhraní, a
 * editace kamene je jiný projekt.
 */
final class Text
{
	public static function of(mixed $value): string
	{
		return \is_scalar($value) ? \trim((string) $value) : '';
	}


	public static function orNull(mixed $value): ?string
	{
		$value = self::of($value);

		return $value === '' ? null : $value;
	}
}
