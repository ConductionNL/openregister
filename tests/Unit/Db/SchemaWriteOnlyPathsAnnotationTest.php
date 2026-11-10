<?php

/**
 * Validation of the `x-openregister-writeonly-paths` annotation (openconnector#235).
 *
 * This repo has a documented history of phantom annotations: a key with a typo round-trips
 * through the configuration column, looks correct to a reviewer, and silently never fires.
 * For a SECURITY annotation that failure mode is not merely inert — it is fail-open: the
 * schema saves, the reviewer sees the declaration, and the secret is served in cleartext.
 *
 * So this annotation is deliberately the strictest key in the configuration column. It is
 * the only one exempt from #419's per-key isolation (which drops a bad key and keeps the
 * rest of the config): a malformed write-only declaration aborts the whole save instead.
 * These tests pin that it fails LOUDLY rather than no-opping.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use InvalidArgumentException;
use OCA\OpenRegister\Db\Schema;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\Schema
 */
class SchemaWriteOnlyPathsAnnotationTest extends TestCase {

	/**
	 * A schema declaring `name` + an untyped `configuration` object.
	 *
	 * @return Schema
	 */
	private function schema(): Schema {
		$schema = new Schema();
		$schema->setSlug('source');
		$schema->setProperties(
			[
				'name' => ['type' => 'string'],
				'configuration' => ['type' => 'object'],
			]
		);

		return $schema;
	}

	/**
	 * A well-formed declaration round-trips and is readable through the accessors.
	 *
	 * @return void
	 */
	public function testAValidDeclarationRoundTrips(): void {
		$schema = $this->schema();
		$schema->setConfiguration(
			[
				Schema::WRITEONLY_PATHS_ANNOTATION => [
					'configuration.authentication.client_secret',
					'configuration.authentication.keys',
				],
			]
		);

		$this->assertTrue($schema->hasWriteOnlyPaths());
		$this->assertSame(
			['configuration.authentication.client_secret', 'configuration.authentication.keys'],
			$schema->getWriteOnlyPaths()
		);
	}

	/**
	 * A schema with no annotation reports no paths — opt-in, and no accidental strip.
	 *
	 * @return void
	 */
	public function testASchemaWithoutTheAnnotationHasNoPaths(): void {
		$schema = $this->schema();
		$schema->setConfiguration(['objectNameField' => 'name']);

		$this->assertFalse($schema->hasWriteOnlyPaths());
		$this->assertSame([], $schema->getWriteOnlyPaths());
	}

	/**
	 * THE key test: a path rooted at a property the schema does not declare is rejected.
	 *
	 * `configuratio.authentication.keys` is the realistic typo. Without the root check it
	 * would save happily, strip nothing, and leak — a phantom annotation that is fail-open.
	 *
	 * @return void
	 */
	public function testATypoInTheRootSegmentThrows(): void {
		$schema = $this->schema();

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/not a declared property/');

		$schema->setConfiguration(
			[Schema::WRITEONLY_PATHS_ANNOTATION => ['configuratio.authentication.keys']]
		);
	}

	/**
	 * A malformed declaration is NOT silently dropped by #419's per-key isolation.
	 *
	 * Every other configuration key degrades gracefully: the bad key is dropped, the rest of
	 * the configuration survives, and the corresponding feature simply does not fire. If
	 * this key did that, the schema would save with its secrets unprotected. It must abort
	 * the save instead — even though a sibling key is perfectly valid.
	 *
	 * @return void
	 */
	public function testAMalformedDeclarationAbortsTheWholeSaveRatherThanBeingDropped(): void {
		$schema = $this->schema();

		$this->expectException(InvalidArgumentException::class);

		$schema->setConfiguration(
			[
				'objectNameField' => 'name',
				Schema::WRITEONLY_PATHS_ANNOTATION => ['configuration..keys'],
			]
		);
	}

	/**
	 * A non-list value (an object/map instead of an array of strings) is rejected.
	 *
	 * @return void
	 */
	public function testANonListValueThrows(): void {
		$schema = $this->schema();

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/must be a list/');

		$schema->setConfiguration(
			[Schema::WRITEONLY_PATHS_ANNOTATION => ['configuration' => 'authentication.keys']]
		);
	}

	/**
	 * A non-string entry is rejected.
	 *
	 * @return void
	 */
	public function testANonStringEntryThrows(): void {
		$schema = $this->schema();

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/non-empty strings/');

		$schema->setConfiguration([Schema::WRITEONLY_PATHS_ANNOTATION => [42]]);
	}

	/**
	 * An empty segment (`a..b`, leading/trailing dot) is rejected — it would otherwise
	 * silently match nothing.
	 *
	 * @param string $path The malformed path under test.
	 *
	 * @return void
	 *
	 * @dataProvider malformedPathProvider
	 */
	public function testAnEmptySegmentThrows(string $path): void {
		$schema = $this->schema();

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/empty segment/');

		$schema->setConfiguration([Schema::WRITEONLY_PATHS_ANNOTATION => [$path]]);
	}

	/**
	 * Malformed dot-paths that must each throw.
	 *
	 * @return array<string, array<int, string>>
	 */
	public static function malformedPathProvider(): array {
		return [
			'double dot' => ['configuration..keys'],
			'trailing dot' => ['configuration.authentication.'],
			'leading dot' => ['.configuration.authentication'],
			'blank segment' => ['configuration. .keys'],
		];
	}

	/**
	 * A single-segment path (the whole top-level property) is legal: it is the same thing
	 * as `writeOnly: true` on that property, expressed through this annotation.
	 *
	 * @return void
	 */
	public function testASingleSegmentPathIsAccepted(): void {
		$schema = $this->schema();
		$schema->setConfiguration([Schema::WRITEONLY_PATHS_ANNOTATION => ['configuration']]);

		$this->assertSame(['configuration'], $schema->getWriteOnlyPaths());
	}

	/**
	 * Duplicate declarations collapse — a repeated path is not an error, just noise.
	 *
	 * @return void
	 */
	public function testDuplicatePathsAreDeduplicated(): void {
		$schema = $this->schema();
		$schema->setConfiguration(
			[
				Schema::WRITEONLY_PATHS_ANNOTATION => [
					'configuration.authentication.keys',
					'configuration.authentication.keys',
				],
			]
		);

		$this->assertSame(['configuration.authentication.keys'], $schema->getWriteOnlyPaths());
	}
}//end class
