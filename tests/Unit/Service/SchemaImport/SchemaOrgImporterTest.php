<?php

/**
 * Unit tests for SchemaOrgImporter (+ SchemaOrgSnapshot) over the bundled
 * snapshot. Pure mapping — no Nextcloud/DB dependency.
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
use OCA\OpenRegister\Service\SchemaImport\ImportOptions;
use OCA\OpenRegister\Service\SchemaImport\SchemaOrgImporter;
use OCA\OpenRegister\Service\SchemaImport\SchemaOrgSnapshot;
use PHPUnit\Framework\TestCase;

class SchemaOrgImporterTest extends TestCase {
	private SchemaOrgImporter $importer;

	protected function setUp(): void {
		$file = dirname(__DIR__, 4) . '/lib/Resources/schemaorg/schemaorg-current-https.jsonld';
		$snapshot = new SchemaOrgSnapshot($file, 'test-version');
		$this->importer = new SchemaOrgImporter($snapshot);
	}

	public function testImportPersonSubset(): void {
		$result = $this->importer->import(
			'Person',
			new ImportOptions(propertySubset: ['givenName', 'familyName', 'email', 'birthDate'])
		);

		$this->assertCount(4, $result->properties);
		$this->assertArrayHasKey('givenName', $result->properties);
		$this->assertSame('string', $result->properties['email']['type']);
		$this->assertNotEmpty($result->properties['email']['description']);

		// birthDate → string/date.
		$this->assertSame('string', $result->properties['birthDate']['type']);
		$this->assertSame('date', $result->properties['birthDate']['format']);

		$this->assertSame('Person', $result->title);
	}

	public function testJsonLdBlockPreFilled(): void {
		$result = $this->importer->import('Person', new ImportOptions(propertySubset: ['email']));

		$this->assertSame('https://schema.org/', $result->jsonld['@vocab']);
		$this->assertSame('https://schema.org/Person', $result->jsonld['type']);
		$this->assertSame('https://schema.org/email', $result->jsonld['properties']['email']);
	}

	public function testAncestorPropertiesAreOptIn(): void {
		// Without ancestors: Thing-level name/description not present.
		$direct = $this->importer->import('Person', new ImportOptions());
		$this->assertArrayNotHasKey('name', $direct->properties);
		$this->assertArrayNotHasKey('description', $direct->properties);

		// With ancestors: Thing-level properties pulled in.
		$withAncestors = $this->importer->import('Person', new ImportOptions(includeAncestors: true));
		$this->assertArrayHasKey('name', $withAncestors->properties);
		$this->assertArrayHasKey('description', $withAncestors->properties);
	}

	public function testDatatypeMappingTable(): void {
		// numberOfEmployees → integer; legalName → string.
		$org = $this->importer->import('Organization', new ImportOptions());
		$this->assertSame('integer', $org->properties['numberOfEmployees']['type']);
		$this->assertSame('string', $org->properties['legalName']['type']);

		// url → string/uri.
		$thing = $this->importer->import('Thing', new ImportOptions(propertySubset: ['url']));
		$this->assertSame('string', $thing->properties['url']['type']);
		$this->assertSame('uri', $thing->properties['url']['format']);
	}

	public function testObjectRangeBecomesUriReference(): void {
		// founder ranges over Person (a class) → string/uri reference.
		$org = $this->importer->import('Organization', new ImportOptions(propertySubset: ['founder']));
		$this->assertSame('string', $org->properties['founder']['type']);
		$this->assertSame('uri', $org->properties['founder']['format']);
	}

	public function testMultiRangeCollapsesToMostPermissive(): void {
		// identifier ranges over Text + URL → most permissive = plain string.
		$thing = $this->importer->import('Thing', new ImportOptions(propertySubset: ['identifier']));
		$this->assertSame('string', $thing->properties['identifier']['type']);
		$this->assertArrayNotHasKey('format', $thing->properties['identifier']);
	}

	public function testUnknownRequestedPropertiesReported(): void {
		$result = $this->importer->import(
			'Person',
			new ImportOptions(propertySubset: ['givenName', 'doesNotExist'])
		);

		$this->assertArrayHasKey('givenName', $result->properties);
		$this->assertSame(['doesNotExist'], $result->unknownRequested);
	}

	public function testUnknownTypeThrows404(): void {
		$this->expectException(SchemaImportException::class);
		try {
			$this->importer->import('Persoon', new ImportOptions());
		} catch (SchemaImportException $e) {
			$this->assertSame(404, $e->getHttpStatus());
			throw $e;
		}
	}

	public function testBaselineRecordedInProvenance(): void {
		$result = $this->importer->import('Person', new ImportOptions(propertySubset: ['email']));

		$this->assertSame('schema.org', $result->importSource['dialect']);
		$this->assertSame('https://schema.org/Person', $result->importSource['reference']);
		$this->assertSame('test-version', $result->importSource['snapshotVersion']);
		$this->assertArrayHasKey('email', $result->importSource['baseline']);
		$this->assertNotEmpty($result->importSource['importedAt']);
	}

	public function testDiscoveryFindsPersonWithParent(): void {
		$results = $this->importer->discover('person');
		$names = array_column($results, 'label');
		$this->assertContains('Person', $names);

		foreach ($results as $candidate) {
			if ($candidate['label'] === 'Person') {
				$this->assertSame('https://schema.org/Person', $candidate['id']);
				$this->assertSame('Thing', $candidate['parent']);
				$this->assertSame('test-version', $candidate['snapshotVersion']);
			}
		}
	}

	public function testImportByIriReference(): void {
		$result = $this->importer->import('https://schema.org/Person', new ImportOptions(propertySubset: ['email']));
		$this->assertSame('Person', $result->title);
	}
}
