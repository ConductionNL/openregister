<?php
/**
 * OpenRegister BsnFormat
 *
 * This file contains the format class for the Bsn format.
 *
 * @category Format
 * @package  OCA\OpenRegister\Formats
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Formats;

use Opis\JsonSchema\Format;

class BsnFormat implements Format
{


    /**
     * Validates if a given value conforms to the Dutch BSN (Burgerservicenummer) format.
     *
     * @param mixed $data The data to validate against the BSN format.
     *
     * @inheritDoc
     *
     * @return bool True if data is a valid BSN, false otherwise.
     */
    public function validate(mixed $data): bool
    {
        $data = str_pad(
            string: $data,
            length:9,
            pad_string: "0",
            pad_type: STR_PAD_LEFT,
        );

        if (ctype_digit($data) === false) {
            return false;
        }

        $control          = 0;
        $reversedIterator = 9;
        foreach (str_split($data) as $character) {
            // Calculate the multiplier based on position.
            $multiplier = -1;
            if ($reversedIterator > 1) {
                $multiplier = $reversedIterator;
            }

            $control += ($character * $multiplier);
            $reversedIterator--;
        }

        return ($control % 11) === 0;

    }//end validate()


}//end class
