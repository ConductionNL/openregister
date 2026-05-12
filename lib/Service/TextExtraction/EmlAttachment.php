<?php

/**
 * Immutable value object for an EML attachment.
 *
 * `content` holds the DECODED binary bytes (not the base64 transport
 * string). Consumers — e.g. DocuDesk's `eml-pdf-assembly` building
 * PDF/A-3 file attachments or `data:` URIs — can use the bytes
 * directly without further decoding.
 *
 * `filename` is resolved in this order, with a non-empty fallback:
 *   1. Content-Disposition `filename` parameter, or
 *   2. Content-Type `name` parameter, or
 *   3. Generated `attachment-<n>` where `<n>` is the 1-indexed
 *      position of the attachment in the multipart-document order.
 *
 * `nestedEml` is populated for attachments whose `mimeType` is
 * `message/rfc822`, subject to the recursion depth cap (default 3).
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
 *       "Each `EmlAttachment` MUST carry filename, MIME type, raw bytes, and inline / contentId metadata"
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\TextExtraction;

use JsonSerializable;

/**
 * One MIME-part attachment of a parsed EML message.
 */
final class EmlAttachment implements JsonSerializable
{

    /**
     * Constructor.
     *
     * @param string             $filename  Resolved filename (always non-empty).
     * @param string             $mimeType  MIME type from `Content-Type`.
     * @param string             $content   Decoded binary bytes of the attachment.
     * @param bool               $isInline  True when the part has `Content-Disposition: inline`.
     * @param string|null        $contentId `Content-ID` header value with angle brackets stripped, or null.
     * @param EmlStructure|null  $nestedEml Recursively parsed nested EML, or null beyond the depth cap.
     */
    public function __construct(
        public readonly string $filename,
        public readonly string $mimeType,
        public readonly string $content,
        public readonly bool $isInline,
        public readonly ?string $contentId,
        public readonly ?EmlStructure $nestedEml,
    ) {
    }//end __construct()

    /**
     * JSON serialisation.
     *
     * `content` is base64-encoded for transport so the JSON shape is
     * still safe-to-print binary bytes. Consumers consuming the PHP
     * value object directly receive raw bytes via `$attachment->content`.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'filename'  => $this->filename,
            'mimeType'  => $this->mimeType,
            'content'   => base64_encode($this->content),
            'isInline'  => $this->isInline,
            'contentId' => $this->contentId,
            'nestedEml' => $this->nestedEml,
        ];
    }//end jsonSerialize()
}//end class
