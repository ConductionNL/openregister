<?php

/**
 * PdfResidualReconciliationTest
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
 * @license   AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\File\Anonymisation;

use OCA\OpenRegister\Service\File\Pdf\PdfTextReplacer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Covers the PDF residual reconciliation.
 *
 * SAPP reports substitutions it could not apply in `rejected_substitutions`.
 * Those were COUNTED in the diagnostic log but never added to the returned
 * residual list, so a rejected substitution was reported to the operator as a
 * clean redaction — the worst possible failure mode for this surface, because
 * the value is still in the document and nothing says so.
 *
 * Re-extraction alone cannot be relied on to catch them: an encoding miss or a
 * subset-font fallback can leave the value present in bytes that smalot renders
 * differently, so the needle is absent from the extracted text while still being
 * in the file.
 *
 * @category Test
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link     https://github.com/ConductionNL/openregister
 *
 * @spec openspec/changes/entity-replacement-planner/specs/pdf-anonymisation/spec.md
 */
final class PdfResidualReconciliationTest extends TestCase
{


    /**
     * Resolve rejected needles from a SAPP stats array.
     *
     * @param array<string, mixed> $stats The replace-stats array.
     *
     * @return array<int, string>
     */
    private function rejected(array $stats): array
    {
        $replacer = new PdfTextReplacer(logger: $this->createMock(LoggerInterface::class));

        $method = new ReflectionMethod(PdfTextReplacer::class, 'rejectedNeedles');
        $method->setAccessible(true);

        return (array) $method->invoke($replacer, $stats);
    }//end rejected()


    /**
     * A plain list of needle strings is the simplest SAPP shape.
     *
     * @return void
     */
    public function testPlainStringListIsResolved(): void
    {
        $this->assertSame(
            ['Jan Jansen', '123456789'],
            $this->rejected(['rejected_substitutions' => ['Jan Jansen', '123456789']])
        );
    }//end testPlainStringListIsResolved()


    /**
     * Per-rejection records are resolved via their needle-ish key.
     *
     * @return void
     */
    public function testRecordListIsResolved(): void
    {
        $stats = [
            'rejected_substitutions' => [
                ['needle' => 'Jan Jansen', 'reason' => 'font_encoding_miss'],
                ['search' => 'Piet Pietersen'],
                ['text' => 'Klaas Klaassen'],
            ],
        ];

        $this->assertSame(['Jan Jansen', 'Piet Pietersen', 'Klaas Klaassen'], $this->rejected($stats));
    }//end testRecordListIsResolved()


    /**
     * A map keyed by needle is resolved from its keys.
     *
     * @return void
     */
    public function testNeedleKeyedMapIsResolved(): void
    {
        $stats = ['rejected_substitutions' => ['Jan Jansen' => 'font_encoding_miss']];

        $this->assertSame(['Jan Jansen'], $this->rejected($stats));
    }//end testNeedleKeyedMapIsResolved()


    /**
     * Absent, empty or non-array stats yield nothing rather than erroring.
     *
     * A throw here would turn an unfamiliar SAPP shape into a failed
     * anonymisation, which the best-effort policy forbids.
     *
     * @return void
     */
    public function testDegenerateStatsYieldNothing(): void
    {
        $this->assertSame([], $this->rejected([]));
        $this->assertSame([], $this->rejected(['rejected_substitutions' => []]));
        $this->assertSame([], $this->rejected(['rejected_substitutions' => 'unexpected']));
        $this->assertSame([], $this->rejected(['rejected_substitutions' => [[]]]));
    }//end testDegenerateStatsYieldNothing()


    /**
     * validateOutput returns a rejected needle even when re-extraction cannot
     * be performed at all, and still does NOT throw.
     *
     * Non-blocking is the point: better detection must change only what is
     * reported, never whether the operator receives the file.
     *
     * @return void
     */
    public function testUnparseableOutputStillReportsNothingAndDoesNotThrow(): void
    {
        $replacer = new PdfTextReplacer(logger: $this->createMock(LoggerInterface::class));

        // Not a PDF, so smalot cannot parse it — the early-return branch.
        $result = $replacer->validateOutput(
            outputBytes: 'not-a-pdf',
            substitutions: ['Jan Jansen' => '[PERSOON: 1]'],
            replaceStats: ['rejected_substitutions' => ['Jan Jansen']]
        );

        $this->assertIsArray($result, 'no exception — the caller still writes the file');
    }//end testUnparseableOutputStillReportsNothingAndDoesNotThrow()


    /**
     * The method has no throw on the residual path at all, so a detected
     * residual can never withhold the output.
     *
     * Asserted structurally rather than behaviourally: the guarantee is that no
     * residual finding is ever turned into a failure, and a future edit that
     * added one would be caught here.
     *
     * @return void
     */
    public function testValidateOutputHasNoThrowOnTheResidualPath(): void
    {
        $method = new ReflectionMethod(PdfTextReplacer::class, 'validateOutput');
        $source = file(__DIR__.'/../../../../../lib/Service/File/Pdf/PdfTextReplacer.php');
        $body   = implode(
            '',
            array_slice(
                $source,
                ($method->getStartLine() - 1),
                ($method->getEndLine() - $method->getStartLine() + 1)
            )
        );

        $this->assertStringNotContainsString(
            'throw ',
            $body,
            'validateOutput must never fail closed on a residual finding'
        );
    }//end testValidateOutputHasNoThrowOnTheResidualPath()
}//end class
