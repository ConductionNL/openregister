<?php

/**
 * Immutable value object for an anonymised (redacted) EML message.
 *
 * The redaction-output counterpart of
 * {@see \OCA\OpenRegister\Service\TextExtraction\EmlStructure}. OpenRegister
 * produces it; DocuDesk's `eml-pdf-assembly` consumer assembles it into a
 * PDF/A-3b. OpenRegister does NOT render PDF.
 *
 * All text fields (body, header values, and the text inside redacted
 * attachments) carry the stable `[<TYPE>: <number>]` placeholders, with the
 * SAME entity yielding the SAME placeholder across body, headers and every
 * attachment of one message.
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
 * Redacted structured representation of an anonymised EML message.
 */
final class AnonymisedEmlStructure implements JsonSerializable
{
    /**
     * Constructor.
     *
     * @param array<string, mixed>                $headers      Redacted display-header subset (from, to[], cc[], replyTo, subject, date).
     * @param AnonymisedEmlBody                   $body         Redacted body (plain and/or html, each nullable).
     * @param array<int, AnonymisedEmlAttachment> $attachments  Redacted attachments in multipart-document order.
     * @param array<string, string>               $inlineImages Map of contentId => decoded redacted bytes, for `cid:` resolution.
     */
    public function __construct(
        public readonly array $headers,
        public readonly AnonymisedEmlBody $body,
        public readonly array $attachments,
        public readonly array $inlineImages,
    ) {

    }//end __construct()

    /**
     * JSON serialisation. Inline-image byte values are base64-encoded so the
     * JSON form is safe to print (attachments base64-encode their own bytes).
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $inlineImages = [];
        foreach ($this->inlineImages as $contentId => $bytes) {
            $inlineImages[$contentId] = base64_encode($bytes);
        }

        return [
            'headers'      => $this->headers,
            'body'         => $this->body,
            'attachments'  => $this->attachments,
            'inlineImages' => $inlineImages,
        ];

    }//end jsonSerialize()
}//end class
