<?php

/**
<<<<<<< HEAD
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
=======
 * EmlAttachment Value Object
 *
 * Represents a single attachment in a parsed EML message.
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
 *       "Each `EmlAttachment` MUST carry filename, MIME type, raw bytes, and inline / contentId metadata"
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
 * One MIME-part attachment of a parsed EML message.
 */
final class EmlAttachment implements JsonSerializable
=======
/**
 * Immutable value object for a single EML attachment.
 *
 * The `content` field holds the decoded (raw-binary) bytes of the attachment,
 * NOT the MIME-transport base64 form. Consumers (DocuDesk PDF/A-3 assembly,
 * data: URI renderers) can embed the bytes directly without decoding again.
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
final class EmlAttachment
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
{
    /**
     * Constructor.
     *
<<<<<<< HEAD
     * @param string            $filename  Resolved filename (always non-empty).
     * @param string            $mimeType  MIME type from `Content-Type`.
     * @param string            $content   Decoded binary bytes of the attachment.
     * @param bool              $isInline  True when the part has `Content-Disposition: inline`.
     * @param string|null       $contentId `Content-ID` header value with angle brackets stripped, or null.
     * @param EmlStructure|null $nestedEml Recursively parsed nested EML, or null beyond the depth cap.
=======
     * @param string            $filename  Attachment filename. Resolved from Content-Disposition
     *                                     filename → Content-Type name → generated
     *                                     'attachment-<n>' (1-indexed position). Never empty.
     * @param string            $mimeType  MIME type from Content-Type.
     * @param string            $content   Decoded binary content (NOT base64-encoded).
     * @param bool              $isInline  True when Content-Disposition is 'inline'.
     * @param string|null       $contentId Content-ID without angle brackets, for cid: references.
     * @param EmlStructure|null $nestedEml Recursively-parsed EML structure, or null when
     *                                     the attachment is not message/rfc822 or the depth
     *                                     limit has been reached.
     *
     * @spec openspec/changes/text-extraction-eml/tasks.md#task-2.1
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
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
<<<<<<< HEAD

    /**
     * JSON serialisation.
     *
     * `content` is base64-encoded for transport so the JSON shape is
     * still safe-to-print binary bytes. Consumers consuming the PHP
     * value object directly receive raw bytes via `$attachment->content`.
     *
     * @return array<string, mixed>
     *
     * @spec exclude Value-object serialiser: maps public readonly properties to an array (content base64-encoded);
     *              field shape specified by text-extraction-eml.
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
=======
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
}//end class
