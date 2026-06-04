<?php

/**
 * DocxSanitizerTest
 *
 * Unit tests for {@see \OCA\OpenRegister\Service\File\Sanitizer\DocxSanitizer}.
 * A synthetic .docx ZIP is built in-test covering every surgical pass; the
 * sanitised output is re-opened and asserted part-by-part.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Service\File\Sanitizer;

use OCA\OpenRegister\Exception\SanitizationException;
use OCA\OpenRegister\Service\File\Sanitizer\DocxSanitizer;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * Tests for {@see DocxSanitizer}.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Test fixtures touch many OOXML part shapes.
 */
class DocxSanitizerTest extends TestCase
{

    /**
     * System under test, recreated per test.
     *
     * @var DocxSanitizer
     */
    private DocxSanitizer $sanitizer;

    /**
     * Temp file paths created during a test, cleaned in tearDown.
     *
     * @var string[]
     */
    private array $tempFiles = [];

    /**
     * Reset before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->sanitizer = new DocxSanitizer('DocuDesk Anonymisation');
    }//end setUp()

    /**
     * Remove temp files after each test.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (file_exists($path) === true) {
                unlink($path);
            }
        }

        parent::tearDown();
    }//end tearDown()

    /**
     * supports() matches only the DOCX MIME.
     *
     * @return void
     */
    public function testSupports(): void
    {
        $this->assertTrue($this->sanitizer->supports('application/vnd.openxmlformats-officedocument.wordprocessingml.document'));
        $this->assertFalse($this->sanitizer->supports('application/vnd.oasis.opendocument.text'));
        $this->assertFalse($this->sanitizer->supports('application/pdf'));
    }//end testSupports()

    /**
     * Every surgical pass produces the expected counts on a rich fixture.
     *
     * @return void
     */
    public function testSanitizeCounts(): void
    {
        $path   = $this->buildFixture();
        $report = $this->sanitizer->sanitize($path, $path);

        $this->assertSame(3, $report->commentsRemoved);
        $this->assertSame(2, $report->trackedChangesAccepted);
        $this->assertSame(2, $report->trackedChangesDropped);
        $this->assertGreaterThanOrEqual(1, $report->revisionAttributesStripped);
        $this->assertSame(2, $report->hyperlinksFlattened);
        $this->assertSame(1, $report->customXmlPartsDropped);
        $this->assertSame(2, $report->fieldCodesStripped);
        // dc:creator, lastModifiedBy, dc:title, dc:subject, keywords (5 core).
        $this->assertSame(5, $report->metadataFieldsScrubbed);
        $this->assertSame('DocuDesk Anonymisation', $report->sentinelApplied);
    }//end testSanitizeCounts()

    /**
     * Comments parts, content-type overrides, rels, and inline markers removed.
     *
     * @return void
     */
    public function testCommentsRemoved(): void
    {
        $path = $this->buildFixture();
        $this->sanitizer->sanitize($path, $path);

        $zip = $this->open($path);
        $this->assertFalse($zip->locateName('word/comments.xml'));
        $this->assertFalse($zip->locateName('word/people.xml'));

        $contentTypes = $zip->getFromName('[Content_Types].xml');
        $this->assertStringNotContainsString('/word/comments.xml', $contentTypes);

        $rels = $zip->getFromName('word/_rels/document.xml.rels');
        $this->assertStringNotContainsString('comments.xml', $rels);

        $document = $zip->getFromName('word/document.xml');
        $this->assertStringNotContainsString('commentReference', $document);
        $this->assertStringNotContainsString('commentRangeStart', $document);
        $zip->close();
    }//end testCommentsRemoved()

    /**
     * Inserts are kept (content preserved); deletions are dropped.
     *
     * @return void
     */
    public function testTrackedChangesAcceptedAndDropped(): void
    {
        $path = $this->buildFixture();
        $this->sanitizer->sanitize($path, $path);

        $zip      = $this->open($path);
        $document = $zip->getFromName('word/document.xml');

        // The <w:ins> wrappers are gone but their text survives.
        $this->assertStringNotContainsString('<w:ins ', $document);
        $this->assertStringContainsString('INSERTED-ONE', $document);
        $this->assertStringContainsString('INSERTED-TWO', $document);

        // <w:del> wrappers and their deleted text are gone.
        $this->assertStringNotContainsString('<w:del ', $document);
        $this->assertStringNotContainsString('DELETED-ONE', $document);
        $this->assertStringNotContainsString('DELETED-TWO', $document);
        $zip->close();
    }//end testTrackedChangesAcceptedAndDropped()

    /**
     * Revision (rsid) attributes are stripped.
     *
     * @return void
     */
    public function testRevisionAttributesStripped(): void
    {
        $path = $this->buildFixture();
        $this->sanitizer->sanitize($path, $path);

        $zip      = $this->open($path);
        $document = $zip->getFromName('word/document.xml');
        $this->assertStringNotContainsString('w:rsidR=', $document);
        $this->assertStringNotContainsString('w:rsidP=', $document);
        $zip->close();
    }//end testRevisionAttributesStripped()

    /**
     * Custom XML parts removed; bound sdt unwrapped preserving visible content.
     *
     * @return void
     */
    public function testCustomXmlStripped(): void
    {
        $path = $this->buildFixture();
        $this->sanitizer->sanitize($path, $path);

        $zip = $this->open($path);
        $this->assertFalse($zip->locateName('customXml/item1.xml'));

        $contentTypes = $zip->getFromName('[Content_Types].xml');
        $this->assertStringNotContainsString('/customXml/', $contentTypes);

        $document = $zip->getFromName('word/document.xml');
        $this->assertStringNotContainsString('<w:sdt>', $document);
        $this->assertStringNotContainsString('dataBinding', $document);
        // Visible bound content preserved.
        $this->assertStringContainsString('BOUND-VISIBLE', $document);
        $zip->close();
    }//end testCustomXmlStripped()

    /**
     * Metadata scrubbed to sentinel; created timestamp preserved.
     *
     * @return void
     */
    public function testMetadataScrubbed(): void
    {
        $path = $this->buildFixture();
        $this->sanitizer->sanitize($path, $path);

        $zip  = $this->open($path);
        $core = $zip->getFromName('docProps/core.xml');

        $this->assertStringNotContainsString('Robert Zondervan', $core);
        $this->assertStringContainsString('DocuDesk Anonymisation', $core);
        // Timestamp preserved.
        $this->assertStringContainsString('2026-01-01T00:00:00Z', $core);
        $zip->close();
    }//end testMetadataScrubbed()

    /**
     * AUTHOR (simple) and USERNAME (complex) fields stripped; DATE preserved.
     *
     * @return void
     */
    public function testFieldCodesStripped(): void
    {
        $path = $this->buildFixture();
        $this->sanitizer->sanitize($path, $path);

        $zip      = $this->open($path);
        $document = $zip->getFromName('word/document.xml');

        // Simple AUTHOR field gone (instr + cached result).
        $this->assertStringNotContainsString('AUTHOR', $document);
        $this->assertStringNotContainsString('CACHED-AUTHOR', $document);
        // Complex USERNAME field gone.
        $this->assertStringNotContainsString('USERNAME', $document);
        $this->assertStringNotContainsString('CACHED-USERNAME', $document);
        // DATE field preserved.
        $this->assertStringContainsString('DATE', $document);
        $zip->close();
    }//end testFieldCodesStripped()

    /**
     * Hyperlinks flattened; external rels entry removed; text kept.
     *
     * @return void
     */
    public function testHyperlinksFlattened(): void
    {
        $path = $this->buildFixture();
        $this->sanitizer->sanitize($path, $path);

        $zip      = $this->open($path);
        $document = $zip->getFromName('word/document.xml');

        $this->assertStringNotContainsString('<w:hyperlink', $document);
        $this->assertStringContainsString('LINK-TEXT-ONE', $document);
        $this->assertStringContainsString('LINK-TEXT-TWO', $document);

        $rels = $zip->getFromName('word/_rels/document.xml.rels');
        $this->assertStringNotContainsString('mailto:p.jansen@example.com', $rels);
        $zip->close();
    }//end testHyperlinksFlattened()

    /**
     * An encrypted / non-OOXML package raises a SanitizationException.
     *
     * @return void
     */
    public function testEncryptedRaises(): void
    {
        // A valid ZIP without [Content_Types].xml stands in for an encrypted package.
        $path = tempnam(sys_get_temp_dir(), 'enc_') . '.docx';
        $this->tempFiles[] = $path;
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('EncryptedPackage', 'garbage');
        $zip->close();

        $this->expectException(SanitizationException::class);
        try {
            $this->sanitizer->sanitize($path, $path);
        } catch (SanitizationException $e) {
            $this->assertSame(SanitizationException::REASON_ENCRYPTED, $e->getReason());
            throw $e;
        }
    }//end testEncryptedRaises()

    /**
     * Open a ZIP archive for assertions.
     *
     * @param string $path The zip path.
     *
     * @return ZipArchive The opened archive.
     */
    private function open(string $path): ZipArchive
    {
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path) === true);
        return $zip;
    }//end open()

    /**
     * Build a synthetic .docx fixture exercising every surgical pass.
     *
     * @return string Path to the fixture .docx.
     *
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Linear fixture assembly.
     */
    private function buildFixture(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'docx_') . '.docx';
        $this->tempFiles[] = $path;

        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);

        $zip->addFromString('[Content_Types].xml', $this->contentTypes());
        $zip->addFromString('word/_rels/document.xml.rels', $this->documentRels());
        $zip->addFromString('word/comments.xml', $this->comments());
        $zip->addFromString('word/commentsExtended.xml', '<root/>');
        $zip->addFromString('word/people.xml', '<root/>');
        $zip->addFromString('customXml/item1.xml', '<root><name>SECRET-PII</name></root>');
        $zip->addFromString('customXml/itemProps1.xml', '<root/>');
        $zip->addFromString('docProps/core.xml', $this->core());
        $zip->addFromString('word/document.xml', $this->document());

        $zip->close();
        return $path;
    }//end buildFixture()

    /**
     * [Content_Types].xml with comment + customXml overrides.
     *
     * @return string The XML string.
     */
    private function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            . '<Override PartName="/word/comments.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.comments+xml"/>'
            . '<Override PartName="/customXml/item1.xml" ContentType="application/xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '</Types>';
    }//end contentTypes()

    /**
     * document.xml.rels with comments + hyperlink + customXml relationships.
     *
     * @return string The XML string.
     */
    private function documentRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/comments" Target="comments.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/customXml" Target="../customXml/item1.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink" Target="mailto:p.jansen@example.com" TargetMode="External"/>'
            . '</Relationships>';
    }//end documentRels()

    /**
     * comments.xml with three comments.
     *
     * @return string The XML string.
     */
    private function comments(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:comments xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '<w:comment w:id="1" w:author="Reviewer A"><w:p><w:r><w:t>note1</w:t></w:r></w:p></w:comment>'
            . '<w:comment w:id="2" w:author="Reviewer B"><w:p><w:r><w:t>note2</w:t></w:r></w:p></w:comment>'
            . '<w:comment w:id="3" w:author="Reviewer C"><w:p><w:r><w:t>note3</w:t></w:r></w:p></w:comment>'
            . '</w:comments>';
    }//end comments()

    /**
     * core.xml with PII metadata + preserved timestamp.
     *
     * @return string The XML string.
     */
    private function core(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" '
            . 'xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:creator>Robert Zondervan</dc:creator>'
            . '<cp:lastModifiedBy>Robert Zondervan</cp:lastModifiedBy>'
            . '<dc:title>Geheim Dossier</dc:title>'
            . '<dc:subject>Jan Jansen zaak</dc:subject>'
            . '<cp:keywords>jansen,dossier</cp:keywords>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">2026-01-01T00:00:00Z</dcterms:created>'
            . '</cp:coreProperties>';
    }//end core()

    /**
     * document.xml exercising tracked changes, sdt, fields, hyperlinks, comments.
     *
     * @return string The XML string.
     */
    private function document(): string
    {
        $w = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document '.$w.'><w:body>'
            // Comment markers.
            . '<w:p w:rsidR="00AA00" w:rsidP="00BB00"><w:commentRangeStart w:id="1"/><w:r><w:t>body</w:t></w:r><w:commentRangeEnd w:id="1"/><w:r><w:commentReference w:id="1"/></w:r></w:p>'
            // Tracked inserts.
            . '<w:p><w:ins w:id="10" w:author="X"><w:r><w:t>INSERTED-ONE</w:t></w:r></w:ins></w:p>'
            . '<w:p><w:ins w:id="11" w:author="Y"><w:r><w:t>INSERTED-TWO</w:t></w:r></w:ins></w:p>'
            // Tracked deletes.
            . '<w:p><w:del w:id="12" w:author="X"><w:r><w:delText>DELETED-ONE</w:delText></w:r></w:del></w:p>'
            . '<w:p><w:del w:id="13" w:author="Y"><w:r><w:delText>DELETED-TWO</w:delText></w:r></w:del></w:p>'
            // Data-bound content control.
            . '<w:p><w:sdt><w:sdtPr><w:dataBinding w:xpath="/root/name"/></w:sdtPr><w:sdtContent><w:r><w:t>BOUND-VISIBLE</w:t></w:r></w:sdtContent></w:sdt></w:p>'
            // Simple AUTHOR field.
            . '<w:p><w:fldSimple w:instr=" AUTHOR "><w:r><w:t>CACHED-AUTHOR</w:t></w:r></w:fldSimple></w:p>'
            // Complex USERNAME field.
            . '<w:p>'
            . '<w:r><w:fldChar w:fldCharType="begin"/></w:r>'
            . '<w:r><w:instrText> USERNAME </w:instrText></w:r>'
            . '<w:r><w:fldChar w:fldCharType="separate"/></w:r>'
            . '<w:r><w:t>CACHED-USERNAME</w:t></w:r>'
            . '<w:r><w:fldChar w:fldCharType="end"/></w:r>'
            . '</w:p>'
            // Preserved DATE field (simple).
            . '<w:p><w:fldSimple w:instr=" DATE "><w:r><w:t>2026-01-01</w:t></w:r></w:fldSimple></w:p>'
            // Hyperlinks.
            . '<w:p><w:hyperlink r:id="rId3"><w:r><w:t>LINK-TEXT-ONE</w:t></w:r></w:hyperlink></w:p>'
            . '<w:p><w:hyperlink w:anchor="_Toc123"><w:r><w:t>LINK-TEXT-TWO</w:t></w:r></w:hyperlink></w:p>'
            . '</w:body></w:document>';
    }//end document()
}//end class
