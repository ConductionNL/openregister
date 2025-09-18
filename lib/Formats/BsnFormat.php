<?php
/**
 * OpenRegister BsnFormat
 *
 * This file contains the format class for the Bsn format.
 *
 * @category Format
 * @package  OCA\OpenRegister\Formats
 *
 * @author    Conduction Development Team <dev@conductio.nl>
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
        if ($data === null || $data === '') {
            return false;
        }
        
        // Only accept strings and numeric values that can be meaningfully converted
        if (is_string($data) === false && is_numeric($data) === false) {
            return false;
        }
        
        $dataString = (string) $data;
        
        // Reject numbers that are not exactly 9 digits
        if (strlen($dataString) !== 9) {
            return false;
        }
        
        $data = $dataString;

        if (ctype_digit($data) === false) {
            return false;
        }

        // Reject all-zero BSNs (000000000)
        if ($data === '000000000') {
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
