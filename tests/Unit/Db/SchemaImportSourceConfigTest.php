<?php

declare(strict_types=1);

/**
 * Schema importSource Provenance Config Round-Trip Test
 *
 * Regression guard for the schema-import provenance block. The `importSource`
 * configuration fragment (dialect, reference, snapshot version, baseline) is
 * written by SchemaImportService when a schema is imported from an external
 * standard (Schema.org / GGM) and read back by the reimport / update-from-
 * source endpoint. It MUST be in the setConfiguration() passThrough allowlist
 * or it is silently discarded on save — which persists the schema without any
 * provenance and makes the entire update-from-source feature dead (the schema
 * reports "not imported from a standard"). See the schema-import + schema-
 * migration Newman integration collections for the end-to-end coverage.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 *
 * @spec openspec/specs/schema-import/spec.md
 */

namespace Unit\Db;

use OCA\OpenRegister\Db\Schema;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the importSource provenance persistence path.
 */
class SchemaImportSourceConfigTest extends TestCase {

	/**
	 * An `importSource` block supplied under `configuration` survives the
	 * hydrate round-trip unchanged (passThrough allowlist).
	 *
	 * @return void
	 */
	public function testImportSourceSurvivesHydrate(): void {
		$importSource = [
			'dialect' => 'schema.org',
			'reference' => 'https://schema.org/Person',
			'snapshotVersion' => '2026-01',
			'baseline' => ['email' => ['type' => 'string']],
		];

		$schema = new Schema();
		$schema->hydrate(
			object: [
				'title' => 'Person',
				'properties' => ['email' => ['type' => 'string']],
				'configuration' => [
					'jsonld' => ['@vocab' => 'https://schema.org/'],
					'importSource' => $importSource,
				],
			]
		);

		$config = $schema->getConfiguration();

		$this->assertNotNull($config);
		$this->assertArrayHasKey('importSource', $config);
		$this->assertSame($importSource, $config['importSource']);
		// The sibling jsonld block must also survive alongside it.
		$this->assertArrayHasKey('jsonld', $config);
	}//end testImportSourceSurvivesHydrate()

	/**
	 * The provenance block round-trips through jsonSerialize() so the reimport
	 * endpoint can read `configuration.importSource` back off a persisted schema.
	 *
	 * @return void
	 */
	public function testImportSourceSurvivesSerialization(): void {
		$schema = new Schema();
		$schema->hydrate(
			object: [
				'configuration' => [
					'importSource' => ['dialect' => 'ggm', 'reference' => 'ZAAK'],
				],
			]
		);

		$serialized = $schema->jsonSerialize();

		$this->assertIsArray($serialized['configuration']);
		$this->assertArrayHasKey('importSource', $serialized['configuration']);
		$this->assertSame('ggm', $serialized['configuration']['importSource']['dialect']);
	}//end testImportSourceSurvivesSerialization()
}//end class
