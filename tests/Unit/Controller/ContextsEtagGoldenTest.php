<?php

/**
 * Golden test pinning the ETag ContextsController derives from a register and
 * its schemas' update stamps.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use DateTime;
use OCA\OpenRegister\Controller\ContextsController;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class ContextsEtagGoldenTest extends TestCase {

	private static function etag(Register $register, array $schemas): string {
		$reflection = new ReflectionClass(ContextsController::class);
		return $reflection->getMethod('buildEtag')->invoke($reflection->newInstanceWithoutConstructor(), $register, $schemas);
	}

	private static function schema(int $id, ?int $updated): Schema {
		$schema = new Schema();
		$schema->setId($id);
		if ($updated !== null) {
			$schema->setUpdated((new DateTime())->setTimestamp($updated));
		}

		return $schema;
	}

	public function testTheEtagHashesTheRegisterAndEachSchemaStamp(): void {
		$register = new Register();
		$register->setUpdated((new DateTime())->setTimestamp(1700000000));

		$this->assertSame(
			'"' . hash('xxh128', '1700000000:5-1700000100:6-0') . '"',
			self::etag($register, [self::schema(5, 1700000100), 'not a schema', self::schema(6, null)])
		);
	}

	public function testANeverUpdatedRegisterStampsZero(): void {
		$this->assertSame('"' . hash('xxh128', '0') . '"', self::etag(new Register(), []));
	}
}//end class
