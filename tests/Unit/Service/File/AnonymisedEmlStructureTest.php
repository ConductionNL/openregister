<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\File;

use OCA\OpenRegister\Service\File\AnonymisedEmlAttachment;
use OCA\OpenRegister\Service\File\AnonymisedEmlBody;
use OCA\OpenRegister\Service\File\AnonymisedEmlStructure;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the anonymise-eml-structured contract value objects.
 *
 * @spec openspec/changes/anonymise-eml-structured/specs/eml-anonymisation/spec.md
 */
class AnonymisedEmlStructureTest extends TestCase
{


    /**
     * Body is a nullable plain/html pair and serialises those keys verbatim.
     *
     * @return void
     */
    public function testBodySerialisesPlainAndHtml(): void
    {
        $body = new AnonymisedEmlBody(plain: 'redacted [PERSOON: 1]', html: null);

        $this->assertSame('redacted [PERSOON: 1]', $body->plain);
        $this->assertNull($body->html);
        $this->assertSame(['plain' => 'redacted [PERSOON: 1]', 'html' => null], $body->jsonSerialize());

    }//end testBodySerialisesPlainAndHtml()


    /**
     * A supported attachment base64-encodes its bytes in jsonSerialize; the
     * typed property stays raw.
     *
     * @return void
     */
    public function testSupportedAttachmentBase64EncodesBytesInJson(): void
    {
        $bytes = "redacted-docx-bytes-\x00\x01";
        $att   = new AnonymisedEmlAttachment(
            filename: 'brief.docx',
            mimeType: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            redactedContent: $bytes,
            unsupported: false
        );

        $this->assertSame($bytes, $att->redactedContent);
        $this->assertFalse($att->unsupported);
        $json = $att->jsonSerialize();
        $this->assertSame(base64_encode($bytes), $json['redactedContent']);
        $this->assertNull($json['nestedEml']);

    }//end testSupportedAttachmentBase64EncodesBytesInJson()


    /**
     * An unsupported attachment carries no content and serialises null bytes.
     *
     * @return void
     */
    public function testUnsupportedAttachmentHasNullContent(): void
    {
        $att = new AnonymisedEmlAttachment(
            filename: 'sheet.xlsx',
            mimeType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            redactedContent: null,
            unsupported: true
        );

        $this->assertTrue($att->unsupported);
        $this->assertNull($att->redactedContent);
        $this->assertNull($att->jsonSerialize()['redactedContent']);

    }//end testUnsupportedAttachmentHasNullContent()


    /**
     * A nested-EML attachment carries a recursive structure and no bytes.
     *
     * @return void
     */
    public function testNestedEmlAttachmentCarriesStructure(): void
    {
        $nested = new AnonymisedEmlStructure(
            headers: ['subject' => 'redacted'],
            body: new AnonymisedEmlBody(plain: 'inner [PERSOON: 1]', html: null),
            attachments: [],
            inlineImages: []
        );
        $att    = new AnonymisedEmlAttachment(
            filename: 'forward.eml',
            mimeType: 'message/rfc822',
            redactedContent: null,
            unsupported: false,
            nestedEml: $nested
        );

        $this->assertFalse($att->unsupported);
        $this->assertNull($att->redactedContent);
        $this->assertInstanceOf(AnonymisedEmlStructure::class, $att->nestedEml);
        $this->assertSame('inner [PERSOON: 1]', $att->nestedEml->body->plain);

    }//end testNestedEmlAttachmentCarriesStructure()


    /**
     * The structure base64-encodes inline-image bytes in jsonSerialize.
     *
     * @return void
     */
    public function testStructureBase64EncodesInlineImages(): void
    {
        $structure = new AnonymisedEmlStructure(
            headers: ['from' => '[PERSOON: 1]'],
            body: new AnonymisedEmlBody(plain: null, html: '<p>[PERSOON: 1]</p>'),
            attachments: [],
            inlineImages: ['cid-1' => "\x89PNGbytes"]
        );

        $json = $structure->jsonSerialize();
        $this->assertSame(base64_encode("\x89PNGbytes"), $json['inlineImages']['cid-1']);
        $this->assertSame(['from' => '[PERSOON: 1]'], $json['headers']);

    }//end testStructureBase64EncodesInlineImages()


}//end class
