<?php

/**
 * Unit test for BundledImporters.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\SchemaImport
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/schema-import/spec.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\SchemaImport;

use OCA\OpenRegister\Service\SchemaImport\BundledImporters;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\SchemaImport\BundledImporters
 */
class BundledImportersTest extends TestCase {

	public function testBundledBuildsSchemaOrgThenGgmOverTheShippedSnapshots(): void {
		$importers = BundledImporters::bundled();

		$this->assertSame(['schema.org', 'ggm'], array_map(fn ($importer) => $importer->dialect(), $importers));
		$this->assertStringStartsNotWith('schemaorg-unknown', $importers[0]->snapshotVersion());
		$this->assertStringStartsNotWith('ggm-unknown', $importers[1]->snapshotVersion());
	}

	public function testARootWithoutVersionFilesFallsBackToUnknown(): void {
		$importers = BundledImporters::at(sys_get_temp_dir() . '/or-bundled-importers-missing');

		$this->assertSame('schemaorg-unknown', $importers[0]->snapshotVersion());
		$this->assertSame('ggm', $importers[1]->dialect());
	}
}
