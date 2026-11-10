<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject;

use OCA\OpenRegister\Service\Object\SaveObject\MetadataField;
use PHPUnit\Framework\TestCase;

/**
 * Only the name column resolves relation fields against the schema properties.
 *
 * @covers \OCA\OpenRegister\Service\Object\SaveObject\MetadataField
 */
final class MetadataFieldTest extends TestCase {
	private const PROPERTIES = ['owner' => ['$ref' => 'person']];

	public function testTheNameResolvesRelationsAgainstAllProperties(): void {
		$field = new MetadataField('name', 'owner', ['naam']);

		$this->assertSame(self::PROPERTIES, $field->relationProperties(self::PROPERTIES));
	}//end testTheNameResolvesRelationsAgainstAllProperties()

	public function testOtherColumnsResolveNoRelations(): void {
		foreach (['description', 'summary', 'Name'] as $column) {
			$this->assertSame([], (new MetadataField($column, 'owner', []))->relationProperties(self::PROPERTIES));
		}
	}//end testOtherColumnsResolveNoRelations()
}//end class
