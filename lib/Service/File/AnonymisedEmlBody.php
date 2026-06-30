<?php

/**
 * Immutable value object for a redacted EML message body.
 *
 * The anonymisation counterpart of {@see \OCA\OpenRegister\Service\TextExtraction\EmlBody}:
 * holds the redacted `text/plain` and `text/html` representations. Either or
 * both may be null when the source message lacked that part. HTML markup is
 * preserved; only text content is redacted.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/anonymise-eml-structured/specs/eml-anonymisation/spec.md
 *       "The result MUST be an AnonymisedEmlStructure matching contract.md"
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

use JsonSerializable;

/**
 * Redacted body of an anonymised EML message.
 */
final class AnonymisedEmlBody implements JsonSerializable
{
    /**
     * Constructor.
     *
     * @param string|null $plain The redacted `text/plain` body part, or null when absent.
     * @param string|null $html  The redacted `text/html` body part (markup preserved), or null when absent.
     */
    public function __construct(
        public readonly ?string $plain,
        public readonly ?string $html,
    ) {

    }//end __construct()

    /**
     * JSON serialisation.
     *
     * @return array{plain: string|null, html: string|null}
     */
    public function jsonSerialize(): array
    {
        return [
            'plain' => $this->plain,
            'html'  => $this->html,
        ];

    }//end jsonSerialize()
}//end class
