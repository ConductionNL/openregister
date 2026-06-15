<?php

/**
 * Unit tests for GgmImporter (+ GgmSnapshot) over the bundled snapshot and an
 * uploaded normalised intermediate. Pure mapping — no Nextcloud/DB dependency.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\SchemaImport
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Service\SchemaImport;

use OCA\OpenRegister\Exception\SchemaImportException;
use OCA\OpenRegister\Service\SchemaImport\GgmImporter;
use OCA\OpenRegister\Service\SchemaImport\GgmSnapshot;
use OCA\OpenRegister\Service\SchemaImport\ImportOptions;
use PHPUnit\Framework\TestCase;

class GgmImporterTest extends TestCase
{
    private GgmImporter $importer;
    private string $snapshotFile;


    protected function setUp(): void
    {
        $this->snapshotFile = dirname(__DIR__, 4).'/lib/Resources/ggm/ggm-snapshot.json';
        $snapshot           = new GgmSnapshot($this->snapshotFile, 'ggm-test');
        $this->importer     = new GgmImporter($snapshot);
    }


    public function testImportPreservesDutchMetadata(): void
    {
        $result = $this->importer->import('ZAAK', new ImportOptions());

        $this->assertSame('Zaak', $result->title);
        $this->assertStringContainsString('samenhangende hoeveelheid werk', $result->description);
        $this->assertStringContainsString('unieke identificatie', $result->properties['identificatie']['description']);
    }


    public function testAttributeTypeMappingTable(): void
    {
        $result = $this->importer->import('ZAAK', new ImportOptions());
        $props  = $result->properties;

        $this->assertSame('string', $props['identificatie']['type']);
        $this->assertSame('integer', $props['doorlooptijd']['type']);
        $this->assertSame('number', $props['kosten']['type']);
        $this->assertSame('boolean', $props['betaaldHeffing']['type']);

        $this->assertSame('string', $props['startdatum']['type']);
        $this->assertSame('date', $props['startdatum']['format']);

        $this->assertSame('string', $props['registratiedatum']['type']);
        $this->assertSame('date-time', $props['registratiedatum']['format']);
    }


    public function testReferentielijstBecomesEnum(): void
    {
        $result = $this->importer->import('ZAAK', new ImportOptions());
        $this->assertSame(
            ['openbaar', 'intern', 'vertrouwelijk', 'zeer_geheim'],
            $result->properties['vertrouwelijkheidaanduiding']['enum']
        );
    }


    public function testRelationBecomesReferenceNotRecursive(): void
    {
        $result = $this->importer->import('ZAAK', new ImportOptions());
        $rel    = $result->properties['heeftBetrekkingOp'];

        $this->assertSame('string', $rel['type']);
        $this->assertSame('uri', $rel['format']);
        // Dutch definition is preserved verbatim as the description.
        $this->assertSame('Het object waarop de zaak betrekking heeft.', $rel['description']);
        // A single schema is produced — the importer returns one ImportedSchema.
        $this->assertSame('Zaak', $result->title);
        // No second schema is created implicitly: the relation is a plain
        // reference, so the result carries no nested objecttype.
        $this->assertArrayNotHasKey('heeftBetrekkingOp', $result->properties['heeftBetrekkingOp']);
    }


    public function testProvenanceRecordsReleaseVersion(): void
    {
        $result = $this->importer->import('ZAAK', new ImportOptions());
        $this->assertSame('ggm', $result->importSource['dialect']);
        $this->assertSame('ZAAK', $result->importSource['reference']);
        $this->assertSame('ggm-2.2.0', $result->importSource['snapshotVersion']);
        $this->assertSame('snapshot', $result->importSource['source']);
    }


    public function testUnknownObjecttypeThrows404(): void
    {
        $this->expectException(SchemaImportException::class);
        try {
            $this->importer->import('NIET_BESTAAND', new ImportOptions());
        } catch (SchemaImportException $e) {
            $this->assertSame(404, $e->getHttpStatus());
            throw $e;
        }
    }


    public function testUploadEquivalentToSnapshotImport(): void
    {
        $normalised = json_decode((string) file_get_contents($this->snapshotFile), true);

        $uploadImporter = new GgmImporter(GgmSnapshot::fromNormalised($normalised), 'upload');
        $uploadResult   = $uploadImporter->import('ZAAK', new ImportOptions());
        $snapshotResult = $this->importer->import('ZAAK', new ImportOptions());

        $this->assertSame($snapshotResult->properties, $uploadResult->properties);
        $this->assertSame('upload', $uploadResult->importSource['source']);
    }


    public function testImportByDutchName(): void
    {
        $result = $this->importer->import('Ingeschreven persoon', new ImportOptions());
        $this->assertSame('INGESCHREVEN_PERSOON', $result->importSource['reference']);
    }


    public function testDiscovery(): void
    {
        $results = $this->importer->discover('zaak');
        $this->assertNotEmpty($results);
        $this->assertSame('ZAAK', $results[0]['id']);
        $this->assertSame('ggm-2.2.0', $results[0]['snapshotVersion']);
    }
}
