<?php

/**
 * DocumentProcessingHandlerOdtWritebackTest
 *
 * Unit tests for ODT (OpenDocument Text) anonymisation writeback in
 * {@see \OCA\OpenRegister\Service\File\DocumentProcessingHandler}.
 *
 * Covers the `odt-anonymisation-writeback` change (Strategy B — in-place XML
 * surgery on content.xml / styles.xml). `.odt` inputs must be redacted by
 * rewriting their XML parts — preserving tables, headers and footers — rather
 * than via the raw-byte str_ireplace fallback (silent no-op / corrupt ZIP) or
 * PhpWord's ODText reader (which drops tables/headers/footers). A fail-loud
 * validation gate must record any surviving entity via getLastResidualEntities()
 * instead of reporting an unredacted file as clean.
 *
 * Fixtures are generated at runtime with PhpWord — no binaries are committed.
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
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Service\File;

use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Service\File\DocumentProcessingHandler;
use OCP\Files\File;
use OCP\Files\FileInfo;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IL10N;
use OCP\IUserSession;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Tests for the ODT writeback path (Strategy B) and its validation gate.
 */
class DocumentProcessingHandlerOdtWritebackTest extends TestCase
{

    /**
     * Temp files created during a test, deleted in tearDown.
     *
     * @var array<int, string>
     */
    private array $tempPaths = [];

    /**
     * Remove any temp fixtures created by the test.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        foreach ($this->tempPaths as $path) {
            if (is_string($path) === true && file_exists($path) === true) {
                @unlink($path);
            }
        }

        $this->tempPaths = [];
        parent::tearDown();
    }//end tearDown()

    /**
     * Build a handler with all collaborators mocked.
     *
     * @return DocumentProcessingHandler
     */
    private function makeHandler(): DocumentProcessingHandler
    {
        return new DocumentProcessingHandler(
            rootFolder: $this->createMock(IRootFolder::class),
            userSession: $this->createMock(IUserSession::class),
            logger: $this->createMock(LoggerInterface::class),
            entityRelationMapper: $this->createMock(EntityRelationMapper::class),
            l10n: $this->createMock(IL10N::class)
        );
    }//end makeHandler()

    /**
     * Invoke a private method of the handler via reflection.
     *
     * @param DocumentProcessingHandler $handler The handler instance.
     * @param string                    $method  Private method name.
     * @param array                     $args    Positional arguments.
     *
     * @return mixed
     */
    private function invoke(DocumentProcessingHandler $handler, string $method, array $args)
    {
        $reflection = new ReflectionMethod(DocumentProcessingHandler::class, $method);
        $reflection->setAccessible(true);
        return $reflection->invoke($handler, ...$args);
    }//end invoke()

    /**
     * Generate an ODT fixture (paragraph + table cell + header + footer) via
     * PhpWord's ODText writer and return the file path.
     *
     * @return string Absolute path to the generated .odt fixture.
     */
    private function makeOdtFixturePath(): string
    {
        $phpWord = new PhpWord();
        $section = $phpWord->addSection();
        $section->addText('Paragraaf met Jan Jansen als betrokkene.');

        $table = $section->addTable();
        $table->addRow();
        $table->addCell(5000)->addText('BSN 123456789 in de cel.');

        $section->addHeader()->addText('Kopregel met Piet Pietersen.');
        $section->addFooter()->addText('Voetregel met Klaas Klaassen.');

        $path = tempnam(sys_get_temp_dir(), 'or_odt_fixture_').'.odt';
        $this->tempPaths[] = $path;
        IOFactory::createWriter($phpWord, 'ODText')->save($path);

        return $path;
    }//end makeOdtFixturePath()

    /**
     * Build a File node mock over the given bytes whose parent folder captures
     * the bytes written by newFile().
     *
     * @param string      $bytes    The node's content bytes.
     * @param string      $name     The node's file name.
     * @param string|null &$written Receives the bytes written to newFile().
     *
     * @return Node
     */
    private function makeNode(string $bytes, string $name, ?string &$written): Node
    {
        $created = $this->createMock(File::class);

        $folder = $this->createMock(Folder::class);
        $folder->method('nodeExists')->willReturn(false);
        $folder->method('newFile')->willReturnCallback(
            function (string $path, $content) use (&$written, $created): File {
                if (is_resource($content) === true) {
                    $written = (string) stream_get_contents($content);
                } else {
                    $written = (string) $content;
                }

                return $created;
            }
        );

        $node = $this->createMock(File::class);
        $node->method('getType')->willReturn(FileInfo::TYPE_FILE);
        $node->method('getName')->willReturn($name);
        $node->method('getPath')->willReturn('/'.$name);
        $node->method('getContent')->willReturn($bytes);
        $node->method('getParent')->willReturn($folder);

        return $node;
    }//end makeNode()

    /**
     * Read one entry from a ZIP byte string.
     *
     * @param string $zipBytes Raw ZIP bytes.
     * @param string $entry    Entry name.
     *
     * @return string Entry content ('' if absent).
     */
    private function readZipEntry(string $zipBytes, string $entry): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'or_odt_zip_');
        $this->tempPaths[] = $tmp;
        file_put_contents($tmp, $zipBytes);

        $zip = new \ZipArchive();
        if ($zip->open($tmp) !== true) {
            return '';
        }

        $data = $zip->getFromName($entry);
        $zip->close();

        return is_string($data) ? $data : '';
    }//end readZipEntry()

    /**
     * A minimal ODF content.xml body wrapping the given paragraph markup.
     *
     * @param string $paragraphs Inner paragraph markup.
     *
     * @return string
     */
    private function odfContent(string $paragraphs): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'.'<office:document-content'.' xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"'.' xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0">'.'<office:body><office:text>'.$paragraphs.'</office:text></office:body>'.'</office:document-content>';
    }//end odfContent()

    /**
     * A single-node paragraph entity is replaced with its placeholder.
     *
     * @return void
     */
    public function testReplaceTextInOdfXmlReplacesSingleNode(): void
    {
        $xml = $this->odfContent('<text:p>Betrokkene is Jan Jansen vandaag.</text:p>');
        $out = (string) $this->invoke($this->makeHandler(), 'replaceTextInOdfXml', [$xml, ['Jan Jansen' => '[PERSOON: 1]']]);

        $this->assertStringContainsString('[PERSOON: 1]', $out);
        $this->assertStringNotContainsString('Jan Jansen', $out);
    }//end testReplaceTextInOdfXmlReplacesSingleNode()

    /**
     * An entity split across two <text:span> runs is still caught: the
     * placeholder lands once and no original fragment survives contiguously.
     *
     * @return void
     */
    public function testReplaceTextInOdfXmlHandlesEntitySplitAcrossSpans(): void
    {
        $xml = $this->odfContent(
            '<text:p>Naam <text:span>Jan </text:span><text:span>Jansen</text:span> hier</text:p>'
        );
        $out = (string) $this->invoke($this->makeHandler(), 'replaceTextInOdfXml', [$xml, ['Jan Jansen' => '[PERSOON: 1]']]);

        $this->assertStringContainsString('[PERSOON: 1]', $out);
        // Concatenated paragraph text must no longer contain the entity.
        $extracted = (string) $this->invoke($this->makeHandler(), 'extractOdfConcatenatedText', [$out]);
        $this->assertStringNotContainsString('Jan Jansen', $extracted);
        // The span structure is preserved (still two spans, one now emptied).
        $this->assertSame(2, substr_count($out, '<text:span'));
    }//end testReplaceTextInOdfXmlHandlesEntitySplitAcrossSpans()

    /**
     * Surrounding text and markup are preserved; only the entity changes.
     *
     * @return void
     */
    public function testReplaceTextInOdfXmlPreservesMarkupAndOtherText(): void
    {
        $xml = $this->odfContent('<text:p>Voor <text:span>Jan Jansen</text:span> en klaar.</text:p>');
        $out = (string) $this->invoke($this->makeHandler(), 'replaceTextInOdfXml', [$xml, ['Jan Jansen' => '[PERSOON: 1]']]);

        $this->assertStringContainsString('Voor ', $out);
        $this->assertStringContainsString(' en klaar.', $out);
        $this->assertStringContainsString('<text:span', $out);
        $this->assertStringContainsString('[PERSOON: 1]', $out);
    }//end testReplaceTextInOdfXmlPreservesMarkupAndOtherText()

    /**
     * Unparseable XML is returned unchanged (the validation gate flags residuals).
     *
     * @return void
     */
    public function testReplaceTextInOdfXmlLeavesUnparseableXmlUnchanged(): void
    {
        $bad = 'this is not xml at all <<<';
        $out = (string) $this->invoke($this->makeHandler(), 'replaceTextInOdfXml', [$bad, ['Jan Jansen' => '[PERSOON: 1]']]);

        $this->assertSame($bad, $out);
    }//end testReplaceTextInOdfXmlLeavesUnparseableXmlUnchanged()

    /**
     * End-to-end: an ODT with paragraph, table, header and footer is redacted
     * in every region AND retains its structure (table + header/footer parts
     * survive — proving XML surgery, not the lossy PhpWord ODText reader).
     *
     * @return void
     */
    public function testOdtEndToEndRedactsAllRegionsAndPreservesStructure(): void
    {
        $bytes   = (string) file_get_contents($this->makeOdtFixturePath());
        $written = null;
        $node    = $this->makeNode(bytes: $bytes, name: 'brief.odt', written: $written);

        $replacements = [
            'Jan Jansen'     => '[PERSOON: 1]',
            '123456789'      => '[BSN: 1]',
            'Piet Pietersen' => '[PERSOON: 2]',
            'Klaas Klaassen' => '[PERSOON: 3]',
        ];

        $this->makeHandler()->replaceWords(node: $node, replacements: $replacements, outputName: 'brief.odt');

        $this->assertIsString($written);
        $content = $this->readZipEntry($written, 'content.xml');
        $styles  = $this->readZipEntry($written, 'styles.xml');

        // Body paragraph + table cell redacted, table structure preserved.
        $this->assertStringContainsString('table:table', $content, 'Table structure must be preserved');
        $this->assertStringContainsString('[PERSOON: 1]', $content, 'Paragraph placeholder present');
        $this->assertStringContainsString('[BSN: 1]', $content, 'Table-cell placeholder present');
        $this->assertStringNotContainsString('Jan Jansen', $content);
        $this->assertStringNotContainsString('123456789', $content);

        // Header/footer live in styles.xml and are redacted, not dropped.
        $this->assertStringNotContainsString('Piet Pietersen', $styles);
        $this->assertStringNotContainsString('Klaas Klaassen', $styles);
        $this->assertStringContainsString('[PERSOON: 2]', $styles, 'Header placeholder present');
        $this->assertStringContainsString('[PERSOON: 3]', $styles, 'Footer placeholder present');
    }//end testOdtEndToEndRedactsAllRegionsAndPreservesStructure()

    /**
     * The output is a valid ODT container and differs from the input (the
     * raw-text fallback would leave it byte-identical or corrupt).
     *
     * @return void
     */
    public function testOdtOutputIsValidOdtAndNotByteIdentical(): void
    {
        $bytes   = (string) file_get_contents($this->makeOdtFixturePath());
        $written = null;
        $node    = $this->makeNode(bytes: $bytes, name: 'brief.odt', written: $written);

        $this->makeHandler()->replaceWords(
            node: $node,
            replacements: ['Jan Jansen' => '[PERSOON: 1]'],
            outputName: 'brief.odt'
        );

        $this->assertIsString($written);
        $this->assertStringStartsWith('PK', $written, 'Output must be a ZIP container');
        $this->assertStringContainsString('application/vnd.oasis.opendocument.text', $written, 'Output must be an ODT');
        $this->assertNotSame($bytes, $written, 'Output must not be byte-identical to input');
    }//end testOdtOutputIsValidOdtAndNotByteIdentical()

    /**
     * A clean redaction reports no residual entities.
     *
     * @return void
     */
    public function testCleanRedactionReportsNoResiduals(): void
    {
        $bytes   = (string) file_get_contents($this->makeOdtFixturePath());
        $written = null;
        $node    = $this->makeNode(bytes: $bytes, name: 'brief.odt', written: $written);

        $handler = $this->makeHandler();
        $handler->replaceWords(
            node: $node,
            replacements: ['Jan Jansen' => '[PERSOON: 1]', '123456789' => '[BSN: 1]'],
            outputName: 'brief.odt'
        );

        $this->assertSame([], $handler->getLastResidualEntities());
    }//end testCleanRedactionReportsNoResiduals()

    /**
     * The validation gate records a surviving entity: an un-redacted ODT still
     * containing the entity is reported via getLastResidualEntities() with the
     * {text, type, id} shape — never a clean success.
     *
     * @return void
     */
    public function testValidationGateRecordsResidualEntity(): void
    {
        $fixture = $this->makeOdtFixturePath();

        $handler = $this->makeHandler();
        $this->invoke($handler, 'recordOdtResidualEntities', [$fixture, ['Jan Jansen' => '[PERSOON: 7]']]);

        $residuals = $handler->getLastResidualEntities();
        $this->assertCount(1, $residuals);
        $this->assertSame('Jan Jansen', $residuals[0]['text']);
        $this->assertSame('PERSOON', $residuals[0]['type']);
        $this->assertSame('7', $residuals[0]['id']);
    }//end testValidationGateRecordsResidualEntity()

    /**
     * The validation gate fails loud on an unreadable output: an invalid ZIP
     * means redaction cannot be proven, so every entity is recorded as residual.
     *
     * @return void
     */
    public function testValidationGateFailsLoudOnUnreadableOutput(): void
    {
        $bogus = tempnam(sys_get_temp_dir(), 'or_odt_bogus_').'.odt';
        $this->tempPaths[] = $bogus;
        file_put_contents($bogus, 'not a real ODT container');

        $handler = $this->makeHandler();
        $this->invoke($handler, 'recordOdtResidualEntities', [$bogus, ['Jan Jansen' => '[PERSOON: 1]', '123456789' => '[BSN: 1]']]);

        $this->assertCount(2, $handler->getLastResidualEntities(), 'Unreadable output must report all entities as residual');
    }//end testValidationGateFailsLoudOnUnreadableOutput()
}//end class
