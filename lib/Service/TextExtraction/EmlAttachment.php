<?php

/**
 * EmlAttachment Value Object
 *
 * Represents a single attachment in a parsed EML message.
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
{
    /**
     * Constructor.
     *
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
}//end class
