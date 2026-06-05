<?php

/**
<<<<<<< HEAD
 * Immutable value object for an EML message body.
 *
 * Holds the two text representations a `multipart/alternative` message
 * may carry: `plainText` (`text/plain`) and `html` (`text/html`).
 * Either or both may be null when the source message lacks that part.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
=======
 * EmlBody Value Object
 *
 * Represents the body content of a parsed EML message with both
 * plain-text and HTML parts.
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\TextExtraction
 *
<<<<<<< HEAD
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/text-extraction-eml/specs/text-extraction-eml/spec.md
 *       "A new public method `parseEmlStructured()` MUST return an `EmlStructure` value object"
=======
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/text-extraction-eml/tasks.md#task-2.1
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\TextExtraction;

<<<<<<< HEAD
use JsonSerializable;

/**
 * Body of a parsed EML message.
 */
final class EmlBody implements JsonSerializable
=======
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
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
{
    /**
     * Constructor.
     *
<<<<<<< HEAD
     * @param string|null $plainText The `text/plain` body part, or null when absent.
     * @param string|null $html      The `text/html` body part, or null when absent.
=======
     * @param string|null $plainText The plain-text body part, or null if absent.
     * @param string|null $html      The HTML body part, or null if absent.
     *
     * @spec openspec/changes/text-extraction-eml/tasks.md#task-2.1
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
     */
    public function __construct(
        public readonly ?string $plainText,
        public readonly ?string $html,
    ) {
    }//end __construct()
<<<<<<< HEAD

    /**
     * JSON serialisation.
     *
     * @return array{plainText: string|null, html: string|null}
     *
     * @spec exclude Value-object serialiser: maps public readonly properties to an array; field shape specified by text-extraction-eml.
     */
    public function jsonSerialize(): array
    {
        return [
            'plainText' => $this->plainText,
            'html'      => $this->html,
        ];
    }//end jsonSerialize()
=======
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
}//end class
