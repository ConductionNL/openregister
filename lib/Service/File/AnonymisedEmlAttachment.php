<?php

/**
 * Immutable value object for a redacted EML attachment.
 *
 * The anonymisation counterpart of {@see \OCA\OpenRegister\Service\TextExtraction\EmlAttachment}.
 *
 * - When the attachment's MIME is supported by an OpenRegister redactor,
 *   `$unsupported` is false and `$redactedContent` holds the DECODED,
 *   redacted bytes (not base64 transport encoding).
 * - When no redactor exists for the MIME (xlsx, ods, archives, calendar,
 *   images, octet-stream, nested EML beyond the recursion cap),
 *   `$unsupported` is true and `$redactedContent` is null — the consumer
 *   renders a placeholder page and OpenRegister NEVER emits the raw bytes.
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
 *       "Unsupported attachments MUST be flagged and their content dropped"
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

use JsonSerializable;

/**
 * One redacted attachment of an anonymised EML message.
 */
final class AnonymisedEmlAttachment implements JsonSerializable
{
    /**
     * Constructor.
     *
     * @param string                      $filename        Attachment filename (always non-empty).
     * @param string                      $mimeType        MIME type from the source attachment.
     * @param string|null                 $redactedContent Decoded redacted bytes, or null when unsupported or a nested EML.
     * @param bool                        $unsupported     True when no redactor handled the MIME; content omitted.
     * @param AnonymisedEmlStructure|null $nestedEml       Recursively-redacted nested message (for `message/rfc822`
     *                                                     attachments within the depth cap), or null otherwise.
     */
    public function __construct(
        public readonly string $filename,
        public readonly string $mimeType,
        public readonly ?string $redactedContent,
        public readonly bool $unsupported,
        public readonly ?AnonymisedEmlStructure $nestedEml=null,
    ) {

    }//end __construct()

    /**
     * JSON serialisation. Byte fields are base64-encoded so the JSON form is
     * safe to print — matching the `EmlAttachment` convention from
     * `text-extraction-eml`. Consumers using the typed property receive raw
     * bytes and MUST NOT base64-decode again.
     *
     * @return array{filename: string, mimeType: string, redactedContent: string|null, unsupported: bool, nestedEml: AnonymisedEmlStructure|null}
     */
    public function jsonSerialize(): array
    {
        $content = null;
        if ($this->redactedContent !== null) {
            $content = base64_encode($this->redactedContent);
        }

        return [
            'filename'        => $this->filename,
            'mimeType'        => $this->mimeType,
            'redactedContent' => $content,
            'unsupported'     => $this->unsupported,
            'nestedEml'       => $this->nestedEml,
        ];

    }//end jsonSerialize()
}//end class
