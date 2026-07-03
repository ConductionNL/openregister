<?php

/**
 * DocumentProcessingHandlerSoftBreakTest
 *
 * Unit tests for the soft-line-break workaround in
 * {@see \OCA\OpenRegister\Service\File\DocumentProcessingHandler::wrapSoftLineBreaksInXml()}.
 *
 * PhpWord's Word2007 writer emits a soft line break as a bare `<w:br/>` under
 * `<w:p>` (PHPOffice/PHPWord #1274); its own reader only reads breaks inside a
 * `<w:r>`, so the break is lost on the next load. The handler wraps the writer's
 * bare breaks in a run so they survive; these tests pin that transform and its
 * idempotency.
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
use OCP\Files\IRootFolder;
use OCP\IL10N;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Tests for {@see DocumentProcessingHandler::wrapSoftLineBreaksInXml()}.
 */
class DocumentProcessingHandlerSoftBreakTest extends TestCase
{
    /**
     * Build a handler with all collaborators mocked (the transform touches
     * none of them).
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
    }

    /**
     * Invoke the private wrapSoftLineBreaksInXml() via reflection.
     *
     * @param string $xml Input WordprocessingML.
     *
     * @return string Transformed XML.
     */
    private function wrap(string $xml): string
    {
        $method = new ReflectionMethod(DocumentProcessingHandler::class, 'wrapSoftLineBreaksInXml');
        $method->setAccessible(true);
        return (string) $method->invoke($this->makeHandler(), $xml);
    }

    /**
     * A bare `<w:br/>` between runs (exactly what PhpWord's writer emits) is
     * wrapped in a run so the reader can read it back.
     *
     * @return void
     */
    public function testWrapsBareBreakBetweenRuns(): void
    {
        $xml = '<w:p><w:r><w:t>Naam</w:t></w:r><w:br/><w:r><w:t>BSN</w:t></w:r></w:p>';
        $out = $this->wrap($xml);

        $this->assertStringContainsString('<w:r><w:br/></w:r>', $out);
        $this->assertStringNotContainsString('</w:r><w:br/><w:r>', $out);
    }

    /**
     * Every break in a multi-break paragraph is wrapped (count preserved).
     *
     * @return void
     */
    public function testWrapsAllBreaks(): void
    {
        $xml = '<w:p><w:r><w:t>A</w:t></w:r><w:br/><w:r><w:t>B</w:t></w:r><w:br/><w:r><w:t>C</w:t></w:r></w:p>';
        $out = $this->wrap($xml);

        $this->assertSame(2, substr_count($out, '<w:r><w:br/></w:r>'));
        $this->assertSame(2, substr_count($out, '<w:br'));
    }

    /**
     * A typed break (`<w:br w:type="page"/>`) keeps its attributes and is
     * wrapped in a run.
     *
     * @return void
     */
    public function testPreservesBreakAttributes(): void
    {
        $xml = '<w:p><w:r><w:t>A</w:t></w:r><w:br w:type="page"/><w:r><w:t>B</w:t></w:r></w:p>';
        $out = $this->wrap($xml);

        $this->assertStringContainsString('<w:r><w:br w:type="page"/></w:r>', $out);
    }

    /**
     * The transform is idempotent: an already-wrapped break is not double
     * nested, and re-running yields the same output.
     *
     * @return void
     */
    public function testIsIdempotent(): void
    {
        $xml   = '<w:p><w:r><w:t>A</w:t></w:r><w:br/><w:r><w:t>B</w:t></w:r></w:p>';
        $once  = $this->wrap($xml);
        $twice = $this->wrap($once);

        $this->assertSame($once, $twice);
        $this->assertStringNotContainsString('<w:r><w:r>', $twice);
    }

    /**
     * An already-correctly-wrapped break is left as a single wrap.
     *
     * @return void
     */
    public function testDoesNotDoubleWrapExistingRun(): void
    {
        $xml = '<w:p><w:r><w:br/></w:r></w:p>';
        $out = $this->wrap($xml);

        $this->assertSame(1, substr_count($out, '<w:r><w:br/></w:r>'));
        $this->assertStringNotContainsString('<w:r><w:r>', $out);
    }

    /**
     * XML without any break is returned unchanged.
     *
     * @return void
     */
    public function testLeavesXmlWithoutBreaksUnchanged(): void
    {
        $xml = '<w:p><w:r><w:t>No breaks here</w:t></w:r></w:p>';

        $this->assertSame($xml, $this->wrap($xml));
    }
}