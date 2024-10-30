<?php

namespace OCA\OpenRegister\Formats;

use Opis\JsonSchema\Format;

class BsnFormat implements Format
{

    /**
     * @inheritDoc
     */
    public function validate(mixed $data): bool
    {
        $data = str_pad(
			string: $data,
			length:9,
			pad_string: "0",
			pad_type: STR_PAD_LEFT,
		);

		if(ctype_digit($data) === false) {
			return false;
		}

		$control = 0;
		$reversedIterator = 9;
		foreach(str_split($data) as $character)
		{
			$control += $character * (($reversedIterator > 1) ? $reversedIterator : -1);
			$reversedIterator--;
		}

		return $control % 11 === 0;
    }
}
