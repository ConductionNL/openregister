<?php

/**
 * ResidualReportTest
 *
 * This file is part of the OpenRegister app for Nextcloud.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\File\Anonymisation;

use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Service\File\DocumentProcessingHandler;
use OCP\Files\IRootFolder;
use OCP\IL10N;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Covers the two-kind residual report.
 *
 * Before this, `getLastResidualEntities()` returned `[]` unconditionally for
 * plain text, docx and EML — indistinguishable from "fully anonymised" — so a
 * partially anonymised document was reported to the operator as complete. The
 * ODT path had a re-read validation gate; nothing else did.
 *
 * The two kinds are deliberately separate. `unmatched` means the text MAY still
 * be in the output. `partial` means the text IS gone but removal took more than
 * one range. Both make the run incomplete; only `unmatched` is a residual, so
 * `residual_count` keeps the meaning existing consumers rely on.
 *
 * @category Test
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 *
 * @spec openspec/changes/entity-replacement-planner/specs/entity-replacement-planner/spec.md
 */
final class ResidualReportTest extends TestCase
{


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
     * Set a private property.
     *
     * @param DocumentProcessingHandler $handler The handler.
     * @param string                    $name    Property name.
     * @param mixed                     $value   Value to set.
     *
     * @return void
     */
    private function setProp(DocumentProcessingHandler $handler, string $name, $value): void
    {
        $property = new ReflectionProperty(DocumentProcessingHandler::class, $name);
        $property->setAccessible(true);
        $property->setValue($handler, $value);
    }//end setProp()


    /**
     * Redact a flat text through the real apply helper, then finalise the report.
     *
     * @param array<string, string> $replacements Needle => placeholder.
     * @param array<string, string> $types        Needle => raw entity type.
     * @param string                $text         Input text.
     *
     * @return array{handler: DocumentProcessingHandler, output: string}
     */
    private function redactAndFinalise(string $text, array $replacements, array $types=[]): array
    {
        $handler = $this->makeHandler();
        $this->setProp(handler: $handler, name: 'entityTypesByNeedle', value: $types);

        $apply = new ReflectionMethod(DocumentProcessingHandler::class, 'applyPlanned');
        $apply->setAccessible(true);
        $output = (string) $apply->invoke($handler, $text, $replacements, null);

        $finalise = new ReflectionMethod(DocumentProcessingHandler::class, 'finaliseResidualReport');
        $finalise->setAccessible(true);
        $finalise->invoke($handler, $replacements);

        return [
            'handler' => $handler,
            'output'  => $output,
        ];
    }//end redactAndFinalise()


    /**
     * A cleanly redacted text reports nothing and is complete.
     *
     * @return void
     */
    public function testCleanRedactionReportsNothing(): void
    {
        $result  = $this->redactAndFinalise(
            text: 'Betrokkene is Jan Jansen vandaag.',
            replacements: ['Jan Jansen' => '[PERSOON: 1]'],
            types: ['Jan Jansen' => 'PERSON']
        );
        $handler = $result['handler'];

        $this->assertSame('Betrokkene is [PERSOON: 1] vandaag.', $result['output']);
        $this->assertSame([], $handler->getLastResidualEntities());
        $this->assertSame([], $handler->getLastPartialEntities());
    }//end testCleanRedactionReportsNothing()


    /**
     * A needle that matched nowhere is reported as a residual, with its type and
     * id recovered from the placeholder.
     *
     * This is the case that previously returned `[]` and read as complete.
     *
     * @return void
     */
    public function testUnmatchedNeedleIsReportedAsResidual(): void
    {
        $result  = $this->redactAndFinalise(
            text: 'Dit document noemt niemand.',
            replacements: ['Jan Jansen' => '[PERSOON: 7]'],
            types: ['Jan Jansen' => 'PERSON']
        );
        $handler = $result['handler'];

        $residuals = $handler->getLastResidualEntities();

        $this->assertCount(1, $residuals);
        $this->assertSame('Jan Jansen', $residuals[0]['text']);
        $this->assertSame('PERSOON', $residuals[0]['type']);
        $this->assertSame('7', $residuals[0]['id']);
        $this->assertSame([], $handler->getLastPartialEntities());
    }//end testUnmatchedNeedleIsReportedAsResidual()


    /**
     * A needle rejected by the boundary policy is reported, so the miss is
     * visible rather than silent. This is what makes bounded matching a safe
     * default.
     *
     * @return void
     */
    public function testBoundaryRejectedNeedleIsReported(): void
    {
        $result  = $this->redactAndFinalise(
            text: 'BSN123456789 staat er',
            replacements: ['123456789' => '[BSN: 3]'],
            types: ['123456789' => 'SSN']
        );
        $handler = $result['handler'];

        $this->assertSame('BSN123456789 staat er', $result['output'], 'not redacted');
        $this->assertCount(1, $handler->getLastResidualEntities(), 'but the miss IS reported');
        $this->assertSame('123456789', $handler->getLastResidualEntities()[0]['text']);
    }//end testBoundaryRejectedNeedleIsReported()


    /**
     * A split-matched needle is reported as PARTIAL, not as a residual — its
     * text is fully absent from the output.
     *
     * @return void
     */
    public function testSplitMatchIsReportedAsPartialNotResidual(): void
    {
        $result  = $this->redactAndFinalise(
            text: 'Betreft Jan de Vries-Bakker vandaag.',
            replacements: [
                'Jan de Vries' => '[PERSOON: 1]',
                'Vries-Bakker' => '[PERSOON: 2]',
            ],
            types: ['Jan de Vries' => 'PERSON', 'Vries-Bakker' => 'PERSON']
        );
        $handler = $result['handler'];

        $this->assertStringNotContainsString('Vries', $result['output']);
        $this->assertStringNotContainsString('Bakker', $result['output']);

        $this->assertSame([], $handler->getLastResidualEntities(), 'nothing remains, so no residual');

        $partials = $handler->getLastPartialEntities();
        $this->assertCount(1, $partials);
        $this->assertSame('Vries-Bakker', $partials[0]['text']);
    }//end testSplitMatchIsReportedAsPartialNotResidual()


    /**
     * A subsumed needle — one wholly inside another entity's accepted range —
     * is reported as NEITHER kind. Its text is gone, and flagging it would tell
     * the operator PII remains on the commonest containment case.
     *
     * @return void
     */
    public function testSubsumedNeedleIsReportedAsNeitherKind(): void
    {
        $result  = $this->redactAndFinalise(
            text: 'Mail robert@rjzondervan.nl voor vragen.',
            replacements: [
                'rjzondervan'           => '[PERSOON: 1]',
                'robert@rjzondervan.nl' => '[EMAIL: 2]',
            ],
            types: ['rjzondervan' => 'PERSON', 'robert@rjzondervan.nl' => 'EMAIL']
        );
        $handler = $result['handler'];

        $this->assertSame('Mail [EMAIL: 2] voor vragen.', $result['output']);
        $this->assertSame([], $handler->getLastResidualEntities());
        $this->assertSame([], $handler->getLastPartialEntities());
    }//end testSubsumedNeedleIsReportedAsNeitherKind()


    /**
     * Match state accumulates ACROSS passes, so a needle present in only one
     * part of a multi-part document is not reported as missing.
     *
     * This is why reporting cannot be computed per pass: an EML's subject, body
     * and attachments are separate passes, as are an ODT's content and styles.
     *
     * @return void
     */
    public function testMatchStateAccumulatesAcrossPasses(): void
    {
        $handler = $this->makeHandler();
        $this->setProp(handler: $handler, name: 'entityTypesByNeedle', value: ['Jan Jansen' => 'PERSON']);

        $replacements = ['Jan Jansen' => '[PERSOON: 1]'];

        $apply = new ReflectionMethod(DocumentProcessingHandler::class, 'applyPlanned');
        $apply->setAccessible(true);

        // Pass 1 — a subject line that does not mention the entity.
        $apply->invoke($handler, 'Onderwerp: kwartaalrapport', $replacements, null);
        // Pass 2 — the body, which does.
        $body = (string) $apply->invoke($handler, 'Met vriendelijke groet, Jan Jansen', $replacements, null);

        $finalise = new ReflectionMethod(DocumentProcessingHandler::class, 'finaliseResidualReport');
        $finalise->setAccessible(true);
        $finalise->invoke($handler, $replacements);

        $this->assertStringContainsString('[PERSOON: 1]', $body);
        $this->assertSame(
            [],
            $handler->getLastResidualEntities(),
            'matched in a later pass, so it must not be reported as missing'
        );
    }//end testMatchStateAccumulatesAcrossPasses()


    /**
     * Finalising merges with residuals a format already found by re-reading its
     * own output, rather than overwriting them. The ODT validation gate is
     * stronger evidence than a plan and must survive.
     *
     * @return void
     */
    public function testFinalisingMergesWithAnExistingGateResult(): void
    {
        $handler = $this->makeHandler();
        $this->setProp(handler: $handler, name: 'entityTypesByNeedle', value: ['Piet Pietersen' => 'PERSON']);

        // Simulate a format's own verification gate having already recorded one.
        $this->setProp(
            handler: $handler,
            name: 'lastResidualEntities',
            value: [['text' => 'Klaas Klaassen', 'type' => 'PERSOON', 'id' => '9']]
        );

        $replacements = ['Piet Pietersen' => '[PERSOON: 4]'];

        $finalise = new ReflectionMethod(DocumentProcessingHandler::class, 'finaliseResidualReport');
        $finalise->setAccessible(true);
        $finalise->invoke($handler, $replacements);

        $texts = array_column($handler->getLastResidualEntities(), 'text');
        sort($texts);

        $this->assertSame(['Klaas Klaassen', 'Piet Pietersen'], $texts, 'gate result must not be discarded');
    }//end testFinalisingMergesWithAnExistingGateResult()


    /**
     * An empty replacement map produces no findings at all.
     *
     * @return void
     */
    public function testEmptyMapProducesNoFindings(): void
    {
        $result  = $this->redactAndFinalise(text: 'Niets te doen hier.', replacements: []);
        $handler = $result['handler'];

        $this->assertSame('Niets te doen hier.', $result['output']);
        $this->assertSame([], $handler->getLastResidualEntities());
        $this->assertSame([], $handler->getLastPartialEntities());
    }//end testEmptyMapProducesNoFindings()
}//end class
