<?php

/**
 * The bulk writer keeps the slug decision and a stored slug (#4037).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/object-slugs-configurable-and-unique/specs/object-lifecycle/spec.md#requirement-bulk-save-must-apply-the-same-slug-rules-as-a-single-save
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\MagicMapper\MagicBulkHandler;
use OCA\OpenRegister\Db\Schema;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Tests for MagicBulkHandler's `_slug` column handling.
 */
class MagicBulkHandlerObjectSlugTest extends TestCase {

	/**
	 * Call the private withDisplayColumns() on a handler built without its constructor.
	 *
	 * @param array $selfData The @self block.
	 * @param array $object   The raw object.
	 * @param bool  $managed  Whether the schema has objectSlugField.
	 *
	 * @return array The prepared row.
	 */
	private function displayColumns(array $selfData, array $object, bool $managed): array {
		$handler = (new ReflectionClass(MagicBulkHandler::class))->newInstanceWithoutConstructor();
		$method = new ReflectionMethod(MagicBulkHandler::class, 'withDisplayColumns');
		return $method->invoke($handler, [], $selfData, $object, $managed);
	}

	public function testAnExplicitNullSlugIsKeptForAManagedSchema(): void {
		$row = $this->displayColumns(['slug' => null], ['slug' => 'business-value'], true);

		$this->assertNull($row['_slug']);
	}

	public function testAnUnmanagedSchemaKeepsTheFallbackToTheObject(): void {
		$row = $this->displayColumns(['slug' => null], ['slug' => 'business-value'], false);

		$this->assertSame('business-value', $row['_slug']);
	}

	public function testADecidedSlugIsWritten(): void {
		$row = $this->displayColumns(['slug' => 'bulk-item'], [], true);

		$this->assertSame('bulk-item', $row['_slug']);
	}

	public function testManagedSlugsFollowTheSchemaConfiguration(): void {
		$handler = (new ReflectionClass(MagicBulkHandler::class))->newInstanceWithoutConstructor();
		$method = new ReflectionMethod(MagicBulkHandler::class, 'hasManagedSlugs');

		$managed = new Schema();
		$managed->setConfiguration(['objectSlugField' => 'title']);
		$plain = new Schema();
		$plain->setConfiguration(['objectNameField' => 'title']);

		$this->assertTrue($method->invoke($handler, $managed));
		$this->assertFalse($method->invoke($handler, $plain));
	}

	public function testTheUpsertKeepsAStoredSlugOnBothPlatforms(): void {
		$source = file_get_contents((new ReflectionClass(MagicBulkHandler::class))->getFileName());

		$this->assertStringContainsString("COALESCE(NULLIF(`_slug`, \\'\\'), VALUES(`_slug`))", $source);
		$this->assertStringContainsString('COALESCE(NULLIF(\"{$fullTableName}\".\"_slug\", \'\'), EXCLUDED.\"_slug\")', $source);
		$this->assertStringContainsString("if (\$column === '_slug' && \$freezeSlug === true) {", $source);
	}
}//end class
