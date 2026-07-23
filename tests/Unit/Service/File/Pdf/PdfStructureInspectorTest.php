<?php

/**
 * PdfStructureInspectorTest
 *
 * Unit tests for {@see \OCA\OpenRegister\Service\File\Pdf\PdfStructureInspector}
 * covering taggedness detection and `/StructElem` counting over the SAPP
 * object model.
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

namespace Unit\Service\File\Pdf;

use ddn\sapp\PDFDoc;
use OCA\OpenRegister\Service\File\Pdf\PdfStructureInspector;
use PHPUnit\Framework\TestCase;

/**
 * Tests for {@see PdfStructureInspector}.
 *
 * Fixtures are synthesised in-test (per the `pdf-anonymisation` change
 * convention — no binary blobs checked into the repo), mirroring
 * {@see \Unit\Service\File\Pdf\PdfTextReplacerTest::buildFixture()}'s
 * hand-built classic-xref shape, extended with a `/StructTreeRoot`,
 * `/MarkInfo` and N `/StructElem` objects for the tagged case.
 */
class PdfStructureInspectorTest extends TestCase
{

    /**
     * System-under-test instance, recreated per test in setUp.
     *
     * @var PdfStructureInspector
     */
    private PdfStructureInspector $inspector;

    /**
     * Reset the system-under-test before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // PdfStructureInspector is backed by the optional `ddn/sapp` library
        // (fetched from a Codeberg VCS repo). Some deploy/CI containers ship
        // without it; skip rather than error so the gateable subset stays
        // honest where the dependency is absent.
        if (class_exists(PDFDoc::class) === false) {
            $this->markTestSkipped('ddn/sapp (PDFDoc) is not installed in this environment.');
        }

        $this->inspector = new PdfStructureInspector();
    }//end setUp()

    /**
     * Build a minimal one-page PDF containing a single Tj operator, WITHOUT
     * any structure tree (`/StructTreeRoot` / `/MarkInfo` absent).
     *
     * @return string The synthesised PDF bytes.
     */
    private static function buildUntaggedFixture(): string
    {
        $contentStream = "BT\n/F1 12 Tf\n72 720 Td\n(Aanvraag zonder tags.) Tj\nET\n";
        $compressed    = gzcompress($contentStream, 6);
        if ($compressed === false) {
            throw new \RuntimeException('gzcompress failed in fixture builder');
        }

        $obj = static function (int $oid, string $body): string {
            return $oid." 0 obj\n".$body."\nendobj\n";
        };

        $obj1 = $obj(1, "<<\n  /Type /Catalog\n  /Pages 2 0 R\n>>");
        $obj2 = $obj(2, "<<\n  /Type /Pages\n  /Kids [ 3 0 R ]\n  /Count 1\n>>");
        $obj3 = $obj(
            3,
            "<<\n  /Type /Page\n  /Parent 2 0 R\n"
            ."  /MediaBox [ 0 0 612 792 ]\n"
            ."  /Resources <<\n    /Font << /F1 5 0 R >>\n    /ProcSet [ /PDF /Text ]\n  >>\n"
            ."  /Contents 4 0 R\n>>"
        );
        $obj4 = $obj(
            4,
            "<<\n  /Length ".strlen($compressed)."\n  /Filter /FlateDecode\n>>\n"
            ."stream\n".$compressed."\nendstream"
        );
        $obj5 = $obj(
            5,
            "<<\n  /Type /Font\n  /Subtype /Type1\n  /BaseFont /Helvetica\n  /Encoding /WinAnsiEncoding\n>>"
        );

        $pdf     = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ([1 => $obj1, 2 => $obj2, 3 => $obj3, 4 => $obj4, 5 => $obj5] as $oid => $body) {
            $offsets[$oid] = strlen($pdf);
            $pdf          .= $body;
        }

        $xrefStart = strlen($pdf);
        $pdf      .= "xref\n0 6\n0000000000 65535 f \n";
        foreach ([1, 2, 3, 4, 5] as $oid) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$oid]);
        }

        $pdf .= "trailer\n<<\n  /Size 6\n  /Root 1 0 R\n>>\nstartxref\n".$xrefStart."\n%%EOF\n";

        return $pdf;
    }//end buildUntaggedFixture()

    /**
     * Build a minimal one-page PDF carrying a `/StructTreeRoot`,
     * `/MarkInfo << /Marked true >>` and `$structElemCount` `/StructElem`
     * objects, all referenced (directly or transitively) from the Catalog.
     *
     * @param int $structElemCount Number of `/StructElem` objects to emit.
     *
     * @return string The synthesised PDF bytes.
     */
    private static function buildTaggedFixture(int $structElemCount): string
    {
        $contentStream = "BT\n/F1 12 Tf\n72 720 Td\n(Aanvraag met tags.) Tj\nET\n";
        $compressed    = gzcompress($contentStream, 6);
        if ($compressed === false) {
            throw new \RuntimeException('gzcompress failed in fixture builder');
        }

        $obj = static function (int $oid, string $body): string {
            return $oid." 0 obj\n".$body."\nendobj\n";
        };

        $structElemRefs = [];
        for ($i = 0; $i < $structElemCount; $i++) {
            $structElemRefs[] = (8 + $i).' 0 R';
        }

        $objects    = [];
        $objects[1] = $obj(1, "<<\n  /Type /Catalog\n  /Pages 2 0 R\n  /StructTreeRoot 6 0 R\n  /MarkInfo 7 0 R\n>>");
        $objects[2] = $obj(2, "<<\n  /Type /Pages\n  /Kids [ 3 0 R ]\n  /Count 1\n>>");
        $objects[3] = $obj(
            3,
            "<<\n  /Type /Page\n  /Parent 2 0 R\n"
            ."  /MediaBox [ 0 0 612 792 ]\n"
            ."  /Resources <<\n    /Font << /F1 5 0 R >>\n    /ProcSet [ /PDF /Text ]\n  >>\n"
            ."  /Contents 4 0 R\n  /StructParents 0\n>>"
        );
        $objects[4] = $obj(
            4,
            "<<\n  /Length ".strlen($compressed)."\n  /Filter /FlateDecode\n>>\n"
            ."stream\n".$compressed."\nendstream"
        );
        $objects[5] = $obj(
            5,
            "<<\n  /Type /Font\n  /Subtype /Type1\n  /BaseFont /Helvetica\n  /Encoding /WinAnsiEncoding\n>>"
        );
        $objects[6] = $obj(6, "<<\n  /Type /StructTreeRoot\n  /K [ ".implode(' ', $structElemRefs)." ]\n>>");
        $objects[7] = $obj(7, "<<\n  /Marked true\n>>");

        for ($i = 0; $i < $structElemCount; $i++) {
            $oid          = (8 + $i);
            $objects[$oid] = $obj($oid, "<<\n  /Type /StructElem\n  /S /P\n  /P 6 0 R\n  /Pg 3 0 R\n>>");
        }

        ksort($objects);

        $pdf     = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $oid => $body) {
            $offsets[$oid] = strlen($pdf);
            $pdf          .= $body;
        }

        $maxOid    = max(array_keys($objects));
        $xrefStart = strlen($pdf);
        $pdf      .= "xref\n0 ".($maxOid + 1)."\n0000000000 65535 f \n";
        for ($oid = 1; $oid <= $maxOid; $oid++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$oid]);
        }

        $pdf .= "trailer\n<<\n  /Size ".($maxOid + 1)."\n  /Root 1 0 R\n>>\nstartxref\n".$xrefStart."\n%%EOF\n";

        return $pdf;
    }//end buildTaggedFixture()

    /**
     * A tagged PDF (StructTreeRoot + MarkInfo + N StructElem) is detected as
     * tagged with the correct element count.
     *
     * @return void
     */
    public function testTaggedPdfIsDetectedWithElementCount(): void
    {
        $pdf = self::buildTaggedFixture(structElemCount: 5);

        $doc = PDFDoc::from_string(buffer: $pdf);
        self::assertNotFalse($doc);

        self::assertTrue($this->inspector->isTagged(doc: $doc));
        self::assertSame(5, $this->inspector->countStructElements(doc: $doc));

        $inspected = $this->inspector->inspect(doc: $doc);
        self::assertSame(['tagged' => true, 'structElementCount' => 5], $inspected);
    }//end testTaggedPdfIsDetectedWithElementCount()

    /**
     * An untagged PDF (no StructTreeRoot, no MarkInfo) reports a zero
     * element count and is not detected as tagged.
     *
     * @return void
     */
    public function testUntaggedPdfHasZeroTags(): void
    {
        $pdf = self::buildUntaggedFixture();

        $doc = PDFDoc::from_string(buffer: $pdf);
        self::assertNotFalse($doc);

        self::assertFalse($this->inspector->isTagged(doc: $doc));
        self::assertSame(0, $this->inspector->countStructElements(doc: $doc));

        $inspected = $this->inspector->inspect(doc: $doc);
        self::assertSame(['tagged' => false, 'structElementCount' => 0], $inspected);
    }//end testUntaggedPdfHasZeroTags()
}//end class
