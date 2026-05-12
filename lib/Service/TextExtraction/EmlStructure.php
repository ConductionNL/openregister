<?php

/**
 * Immutable value object representing a parsed EML message.
 *
 * Holds:
 *   - `headers`     — associative array of canonical header values
 *                     (`from`, `to`, `cc`, `subject`, `date`,
 *                     `messageId`, plus any extras the implementation
 *                     chooses to surface). Encoded-word headers
 *                     (RFC 2047) are decoded here.
 *   - `body`        — `EmlBody` value object with `plainText` / `html`.
 *   - `attachments` — array of `EmlAttachment` in multipart-document
 *                     order. Nested EMLs carry their own `EmlStructure`
 *                     subject to the recursion cap.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\TextExtraction
 *
 * @author  Conduction Development Team <dev@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/text-extraction-eml/specs/text-extraction-eml/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\TextExtraction;

use JsonSerializable;

/**
 * Structured representation of a parsed EML message.
 */
final class EmlStructure implements JsonSerializable
{

    /**
     * Constructor.
     *
     * @param array<string, mixed>      $headers     Decoded headers.
     * @param EmlBody                   $body        Body value object.
     * @param array<int, EmlAttachment> $attachments Attachments in multipart order.
     */
    public function __construct(
        public readonly array $headers,
        public readonly EmlBody $body,
        public readonly array $attachments,
    ) {
    }//end __construct()

    /**
     * JSON serialisation.
     *
     * @return array{headers: array, body: EmlBody, attachments: array<int, EmlAttachment>}
     */
    public function jsonSerialize(): array
    {
        return [
            'headers'     => $this->headers,
            'body'        => $this->body,
            'attachments' => $this->attachments,
        ];
    }//end jsonSerialize()
}//end class
