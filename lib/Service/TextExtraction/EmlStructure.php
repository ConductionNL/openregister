<?php

/**
<<<<<<< HEAD
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
=======
 * EmlStructure Value Object
 *
 * Represents the fully-parsed, structured representation of an EML message
 * suitable for downstream rich-rendering consumers such as DocuDesk.
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

use JsonSerializable;

/**
<<<<<<< HEAD
 * Structured representation of a parsed EML message.
=======
 * Immutable value object for a parsed EML message.
 *
 * Contains normalised headers, body parts (plain-text and HTML), and
 * ordered attachments. Implements JsonSerializable so controllers or
 * future REST surfaces can return it directly.
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
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
 */
final class EmlStructure implements JsonSerializable
{
    /**
     * Constructor.
     *
<<<<<<< HEAD
     * @param array<string, mixed>      $headers     Decoded headers.
     * @param EmlBody                   $body        Body value object.
     * @param array<int, EmlAttachment> $attachments Attachments in multipart order.
=======
     * @param array<string,mixed> $headers     Normalised headers. Keys: 'from' (string),
     *                                         'to' (string[]), 'cc' (string[]),
     *                                         'subject' (string), 'date'
     *                                         (\DateTimeImmutable|null), 'messageId'
     *                                         (string|null).
     * @param EmlBody             $body        Both plain-text and HTML body parts.
     * @param EmlAttachment[]     $attachments Ordered attachments in multipart order.
     *
     * @spec openspec/changes/text-extraction-eml/tasks.md#task-2.1
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
     */
    public function __construct(
        public readonly array $headers,
        public readonly EmlBody $body,
        public readonly array $attachments,
    ) {
    }//end __construct()

    /**
<<<<<<< HEAD
     * JSON serialisation.
     *
     * @return array{headers: array, body: EmlBody, attachments: array<int, EmlAttachment>}
     *
     * @spec exclude Value-object serialiser: maps public readonly properties to an array; field shape specified by text-extraction-eml.
     */
    public function jsonSerialize(): array
    {
        return [
            'headers'     => $this->headers,
            'body'        => $this->body,
            'attachments' => $this->attachments,
=======
     * Serialize to JSON-safe array for REST surfaces.
     *
     * DateTimeImmutable is serialised as ISO 8601 string; null dates remain null.
     * Attachment raw-byte content is base64-encoded for safe JSON transport.
     *
     * @return array<string,mixed>
     *
     * @spec openspec/changes/text-extraction-eml/tasks.md#task-2.1
     */
    public function jsonSerialize(): array
    {
        $headers = $this->headers;
        if (isset($headers['date']) === true && $headers['date'] instanceof \DateTimeImmutable) {
            $headers['date'] = $headers['date']->format(\DateTimeInterface::ISO8601);
        }

        return [
            'headers'     => $headers,
            'body'        => [
                'plainText' => $this->body->plainText,
                'html'      => $this->body->html,
            ],
            'attachments' => array_map(
                static function (EmlAttachment $a): array {
                    return [
                        'filename'  => $a->filename,
                        'mimeType'  => $a->mimeType,
                        'content'   => base64_encode($a->content),
                        'isInline'  => $a->isInline,
                        'contentId' => $a->contentId,
                        'nestedEml' => $a->nestedEml !== null ? $a->nestedEml->jsonSerialize() : null,
                    ];
                },
                $this->attachments
            ),
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
        ];
    }//end jsonSerialize()
}//end class
