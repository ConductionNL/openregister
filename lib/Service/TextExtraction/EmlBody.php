<?php

/**
 * Immutable value object for an EML message body.
 *
 * Holds the two text representations a `multipart/alternative` message
 * may carry: `plainText` (`text/plain`) and `html` (`text/html`).
 * Either or both may be null when the source message lacks that part.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\TextExtraction
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/text-extraction-eml/specs/text-extraction-eml/spec.md
 *       "A new public method `parseEmlStructured()` MUST return an `EmlStructure` value object"
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\TextExtraction;

use JsonSerializable;

/**
 * Body of a parsed EML message.
 */
final class EmlBody implements JsonSerializable
{
    /**
     * Constructor.
     *
     * @param string|null $plainText The `text/plain` body part, or null when absent.
     * @param string|null $html      The `text/html` body part, or null when absent.
     */
    public function __construct(
        public readonly ?string $plainText,
        public readonly ?string $html,
    ) {
    }//end __construct()

    /**
     * JSON serialisation.
     *
     * @return array{plainText: string|null, html: string|null}
     */
    public function jsonSerialize(): array
    {
        return [
            'plainText' => $this->plainText,
            'html'      => $this->html,
        ];
    }//end jsonSerialize()
}//end class
