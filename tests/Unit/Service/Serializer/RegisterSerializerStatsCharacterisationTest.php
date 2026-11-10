<?php

/**
 * Characterisation test for RegisterSerializer's `@self.stats` branches: the
 * counts it attaches when stats are missing, partial or absent for a register,
 * pinned before its helper parameters change shape.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Serializer
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Serializer;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Serializer\RegisterExpansion;
use OCA\OpenRegister\Service\Serializer\RegisterListExtension;
use OCA\OpenRegister\Service\Serializer\RegisterSerializer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\Serializer\RegisterSerializer
 */
class RegisterSerializerStatsCharacterisationTest extends TestCase {

	/**
	 * A serializer whose mapper resolves every numeric id to a schema with that id.
	 *
	 * @return RegisterSerializer
	 */
	private function serializer(): RegisterSerializer {
		$mapper = $this->createMock(SchemaMapper::class);
		$mapper->method('find')->willReturnCallback(
			static function ($id): Schema {
				$schema = new Schema();
				$schema->setId((int)$id);
				$schema->setSlug('s' . (string)$id);
				return $schema;
			}
		);
		$mapper->method('findMultipleOptimized')->willReturn([]);

		return new RegisterSerializer($mapper, $this->createMock(LoggerInterface::class));
	}

	/**
	 * A register with an id and schema ids.
	 *
	 * @param int             $id      The register id.
	 * @param array<int, int> $schemas The schema ids.
	 *
	 * @return Register
	 */
	private function register(int $id, array $schemas): Register {
		$register = new Register();
		$register->setId($id);
		$register->setSchemas($schemas);
		return $register;
	}

	/**
	 * The totals attached to each expanded schema, or 'none' when no stats key was attached.
	 *
	 * @param array<string, mixed> $payload A serialized register.
	 *
	 * @return array<int, int|string>
	 */
	private function totals(array $payload): array {
		return array_map(
			static fn (array $schema): int|string => ($schema['stats']['objects']['total'] ?? 'none'),
			$payload['schemas']
		);
	}

	public function testStatsWithoutPrecomputedCountsAttachZero(): void {
		$result = $this->serializer()->serializeExpanded($this->register(1, [10, 20]), RegisterExpansion::fromExtend(extend: ['schemas', '@self.stats']));

		$this->assertSame([0, 0], $this->totals($result));
		$this->assertSame(['objects' => ['total' => 0]], $result['schemas'][0]['stats']);
	}

	public function testACountWithoutTotalOrForAnotherSchemaAttachesZero(): void {
		$stats = [10 => ['other' => 4], 99 => ['total' => 8], 20 => ['total' => '5']];

		$result = $this->serializer()->serializeExpanded($this->register(1, [10, 20, 30]), RegisterExpansion::fromExtend(extend: ['schemas', '@self.stats'], schemaStats: $stats));

		$this->assertSame([0, 5, 0], $this->totals($result));
	}

	public function testSchemasWithoutStatsCarryNoStatsKey(): void {
		$result = $this->serializer()->serializeExpanded($this->register(1, [10]), RegisterExpansion::fromExtend(extend: ['schemas'], schemaStats: [10 => ['total' => 3]]));

		$this->assertSame(['none'], $this->totals($result));
	}

	public function testSerializeManyGivesARegisterMissingFromTheMapZero(): void {
		$registers = [$this->register(1, [10]), $this->register(2, [20])];

		$result = $this->serializer()->serializePage($registers, new RegisterListExtension(extend: ['schemas', '@self.stats'], schemaStatsByRegisterId: [1 => [10 => ['total' => 3]]]));

		$this->assertSame([3], $this->totals($result[0]));
		$this->assertSame([0], $this->totals($result[1]));
	}

	public function testSerializeManyWithoutAMapAttachesZeroEverywhere(): void {
		$registers = [$this->register(1, [10]), $this->register(2, [20])];

		$result = $this->serializer()->serializePage($registers, new RegisterListExtension(extend: ['schemas', '@self.stats']));

		$this->assertSame([0], $this->totals($result[0]));
		$this->assertSame([0], $this->totals($result[1]));
	}

	public function testSerializeManyWithoutExtendKeepsIds(): void {
		$result = $this->serializer()->serializeMany([$this->register(1, [10])]);

		$this->assertSame([[10]], array_column($result, 'schemas'));
	}
}//end class
