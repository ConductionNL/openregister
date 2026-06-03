<?php
/**
 * Word-family text extraction tests for TextExtractionService.
 *
 * Covers the `text-extraction-word-completeness` change: full recursive
 * extraction (body + tables incl. nested + headers/footers + footnotes/
 * endnotes), MIME/extension → reader selection (DOCX/DOC/ODT), and graceful
 * null fallback on unparseable / empty input. Fixtures are generated at
 * runtime with PhpWord so no binary blobs are committed.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Service
 * @author   Conduction B.V. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://www.openregister.app
 */

namespace OCA\OpenRegister\Tests\Service;

use OCA\OpenRegister\Service\TextExtractionService;
use OCP\Files\File;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * @group DB
 */
class TextExtractionWordExtractionTest extends TestCase
{

    /**
     * Service under test.
     *
     * @var TextExtractionService
     */
    private TextExtractionService $service;

    /**
     * Temp fixture paths to clean up.
     *
     * @var string[]
     */
    private array $tmpFiles = [];

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->service = \OC::$server->get(TextExtractionService::class);
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $path) {
            if (is_file($path) === true) {
                @unlink($path);
            }
        }

        parent::tearDown();
    }

    /**
     * Invoke a private/protected method via reflection.
     *
     * @param object $object     Target instance.
     * @param string $methodName Method name.
     * @param array  $args       Positional arguments.
     *
     * @return mixed
     */
    private function invokePrivate(object $object, string $methodName, array $args=[]): mixed
    {
        $ref = new ReflectionMethod($object, $methodName);
        $ref->setAccessible(true);
        return $ref->invoke($object, ...$args);
    }

    /**
     * Build a mock Nextcloud File returning the given bytes / MIME / name.
     *
     * @param string $bytes Raw file content.
     * @param string $mime  MIME type.
     * @param string $name  File name.
     *
     * @return File
     */
    private function mockFile(string $bytes, string $mime, string $name): File
    {
        $file = $this->createMock(File::class);
        $file->method('getContent')->willReturn($bytes);
        $file->method('getMimeType')->willReturn($mime);
        $file->method('getName')->willReturn($name);
        $file->method('getId')->willReturn(424242);
        return $file;
    }

    /**
     * Save a PhpWord document with the given writer and return its bytes.
     *
     * @param PhpWord $phpWord    Document.
     * @param string  $writerName 'Word2007' | 'ODText'.
     * @param string  $ext        File extension for the temp path.
     *
     * @return string
     */
    private function renderBytes(PhpWord $phpWord, string $writerName, string $ext): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ortest_').'.'.$ext;
        $this->tmpFiles[] = $path;
        IOFactory::createWriter($phpWord, $writerName)->save($path);
        return (string) file_get_contents($path);
    }

    /**
     * Build a rich DOCX: body paragraph, a table whose cells hold a nested
     * table / list item, a header, a footer, a footnote and an endnote.
     *
     * @return PhpWord
     */
    private function buildRichDocument(): PhpWord
    {
        $phpWord = new PhpWord();
        $section = $phpWord->addSection();

        $section->addText('BODY_PARAGRAPH_TEXT');

        $header = $section->addHeader();
        $header->addText('HEADER_MARKER');
        $footer = $section->addFooter();
        $footer->addText('FOOTER_MARKER');

        $table = $section->addTable();
        $table->addRow();
        $cellA = $table->addCell();
        $cellA->addText('CELL_A_TEXT');
        // Nested table inside cell A.
        $nested = $cellA->addTable();
        $nested->addRow();
        $nested->addCell()->addText('NESTED_CELL_TEXT');
        $cellB = $table->addCell();
        $cellB->addListItem('LIST_ITEM_IN_CELL');

        $footnote = $section->addFootnote();
        $footnote->addText('FOOTNOTE_MARKER');
        $endnote = $section->addEndnote();
        $endnote->addText('ENDNOTE_MARKER');

        return $phpWord;
    }

    /**
     * DOCX → reader Word2007, DOC → MsDoc, ODT → ODText, unknown → Word2007.
     *
     * @return void
     */
    public function testResolveWordReaderMapsMimeAndExtension(): void
    {
        $docx = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
        $odt  = 'application/vnd.oasis.opendocument.text';

        $this->assertSame('Word2007', $this->invokePrivate($this->service, 'resolveWordReader', [$docx, 'a.docx']));
        $this->assertSame('MsDoc', $this->invokePrivate($this->service, 'resolveWordReader', ['application/msword', 'a.doc']));
        $this->assertSame('ODText', $this->invokePrivate($this->service, 'resolveWordReader', [$odt, 'a.odt']));
        // Generic MIME → fall back to extension.
        $this->assertSame('MsDoc', $this->invokePrivate($this->service, 'resolveWordReader', ['application/octet-stream', 'legacy.doc']));
        $this->assertSame('ODText', $this->invokePrivate($this->service, 'resolveWordReader', ['application/octet-stream', 'open.odt']));
        // Unknown MIME and extension → safe default.
        $this->assertSame('Word2007', $this->invokePrivate($this->service, 'resolveWordReader', ['application/octet-stream', 'mystery.bin']));
    }

    /**
     * DOCX extraction must capture table cells (incl. nested), in-cell list
     * items, headers, footers, footnotes and endnotes — plus body text.
     *
     * @return void
     */
    public function testDocxExtractionCapturesAllNiches(): void
    {
        $bytes = $this->renderBytes($this->buildRichDocument(), 'Word2007', 'docx');
        $file  = $this->mockFile(
            $bytes,
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'rich.docx'
        );

        $text = $this->invokePrivate($this->service, 'extractWord', [$file]);

        $this->assertIsString($text);
        $this->assertStringContainsString('BODY_PARAGRAPH_TEXT', $text, 'body text (non-regression)');
        $this->assertStringContainsString('CELL_A_TEXT', $text, 'table cell text');
        $this->assertStringContainsString('NESTED_CELL_TEXT', $text, 'nested table cell text');
        $this->assertStringContainsString('LIST_ITEM_IN_CELL', $text, 'in-cell list item text');
        $this->assertStringContainsString('HEADER_MARKER', $text, 'header text');
        $this->assertStringContainsString('FOOTER_MARKER', $text, 'footer text');
        $this->assertStringContainsString('FOOTNOTE_MARKER', $text, 'footnote text');
        $this->assertStringContainsString('ENDNOTE_MARKER', $text, 'endnote text');
    }

    /**
     * ODT input produces populated text (validates ODText reader selection end-to-end).
     *
     * @return void
     */
    public function testOdtExtractionProducesText(): void
    {
        $phpWord = new PhpWord();
        $phpWord->addSection()->addText('ODT_BODY_TEXT');
        $bytes = $this->renderBytes($phpWord, 'ODText', 'odt');

        $file = $this->mockFile($bytes, 'application/vnd.oasis.opendocument.text', 'doc.odt');
        $text = $this->invokePrivate($this->service, 'extractWord', [$file]);

        $this->assertIsString($text);
        $this->assertStringContainsString('ODT_BODY_TEXT', $text);
    }

    /**
     * Unparseable content degrades to null instead of throwing.
     *
     * @return void
     */
    public function testExtractWordReturnsNullOnUnparseableContent(): void
    {
        $file = $this->mockFile(
            'this is not a real office document at all',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'broken.docx'
        );

        $this->assertNull($this->invokePrivate($this->service, 'extractWord', [$file]));
    }

    /**
     * An empty document (no text) returns null.
     *
     * @return void
     */
    public function testExtractWordReturnsNullOnEmptyDocument(): void
    {
        $phpWord = new PhpWord();
        $phpWord->addSection();
        $bytes = $this->renderBytes($phpWord, 'Word2007', 'docx');

        $file = $this->mockFile(
            $bytes,
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'empty.docx'
        );

        $this->assertNull($this->invokePrivate($this->service, 'extractWord', [$file]));
    }

    /**
     * The walker stops at MAX_WORD_DEPTH and never recurses a too-deep leaf,
     * without crashing.
     *
     * @return void
     */
    public function testWalkerDepthGuardStopsDescent(): void
    {
        // Build a chain of nested container stubs deeper than the cap (50).
        $deepLeaf = new class {
            /**
             * @return string
             */
            public function getText(): string
            {
                return 'DEEP_LEAF_TEXT';
            }
        };

        $node = $deepLeaf;
        for ($i = 0; $i < 60; $i++) {
            $node = new class($node) {

                /** @var object */
                private object $inner;

                /**
                 * @param object $inner Wrapped child.
                 */
                public function __construct(object $inner)
                {
                    $this->inner = $inner;
                }

                /**
                 * @return array
                 */
                public function getElements(): array
                {
                    return [$this->inner];
                }
            };
        }

        $text = $this->invokePrivate($this->service, 'walkWordElements', [[$node], 0]);

        $this->assertIsString($text);
        $this->assertStringNotContainsString('DEEP_LEAF_TEXT', $text, 'leaf beyond MAX_WORD_DEPTH must not be reached');
    }

    /**
     * A shallow nested-container chain is fully walked (sanity counterpart to the depth guard).
     *
     * @return void
     */
    public function testWalkerReachesShallowLeaf(): void
    {
        $leaf = new class {
            /**
             * @return string
             */
            public function getText(): string
            {
                return 'SHALLOW_LEAF_TEXT';
            }
        };

        $container = new class($leaf) {

            /** @var object */
            private object $inner;

            /**
             * @param object $inner Wrapped child.
             */
            public function __construct(object $inner)
            {
                $this->inner = $inner;
            }

            /**
             * @return array
             */
            public function getElements(): array
            {
                return [$this->inner];
            }
        };

        $text = $this->invokePrivate($this->service, 'walkWordElements', [[$container], 0]);
        $this->assertStringContainsString('SHALLOW_LEAF_TEXT', $text);
    }
}//end class
