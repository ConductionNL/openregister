<?php

/**
 * EmlBody Value Object
 *
 * Represents the body content of a parsed EML message with both
 * plain-text and HTML parts.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\TextExtraction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/text-extraction-eml/tasks.md#task-2.1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\TextExtraction;

/**
 * Immutable value object representing the body of an EML message.
 *
 * Both plainText and html fields are populated when the respective part
 * exists in the original message; consumers choose which to use.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\TextExtraction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/text-extraction-eml/tasks.md#task-2.1
 */
final class EmlBody
{
    /**
     * Constructor.
     *
     * @param string|null $plainText The plain-text body part, or null if absent.
     * @param string|null $html      The HTML body part, or null if absent.
     *
     * @spec openspec/changes/text-extraction-eml/tasks.md#task-2.1
     */
    public function __construct(
        public readonly ?string $plainText,
        public readonly ?string $html,
    ) {
    }//end __construct()
}//end class
