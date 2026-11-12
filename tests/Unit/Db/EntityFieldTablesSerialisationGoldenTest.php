<?php

/**
 * Characterisation of Chunk, ExportProfile and EndpointLog: the registered field types and the serialised shape,
 * empty and with every field set, pinned key for key and in order against a golden file.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\EndpointLog;
use OCA\OpenRegister\Db\ExportProfile;
use OCA\OpenRegister\Files\Db\Chunk;
use OCP\AppFramework\Db\Entity;
use PHPUnit\Framework\TestCase;

/**
 * Golden field types and serialisation.
 *
 * @covers \OCA\OpenRegister\Files\Db\Chunk
 * @covers \OCA\OpenRegister\Db\ExportProfile
 * @covers \OCA\OpenRegister\Db\EndpointLog
 */
final class EntityFieldTablesSerialisationGoldenTest extends TestCase {

	/**
	 * The entities under test.
	 *
	 * @return array<string, array{0: class-string<Entity>}>
	 */
	public static function entities(): array {
		return [
			'Chunk' => [Chunk::class],
			'ExportProfile' => [ExportProfile::class],
			'EndpointLog' => [EndpointLog::class],
		];
	}//end entities()

	/**
	 * Field types, empty shape and filled shape match the golden file.
	 *
	 * @param class-string<Entity> $class The entity.
	 *
	 * @return void
	 *
	 * @dataProvider entities
	 */
	public function testFieldTypesAndSerialisedShape(string $class): void {
		$golden = json_decode((string) file_get_contents(__DIR__ . '/fixtures/entityFieldTablesSerialisationGoldenTest.json'), true)[$class];

		$empty = new $class();
		$this->assertSame($golden['types'], $empty->getFieldTypes());
		$this->assertSame($golden['empty'], $this->withoutDefaultExpiry(json: $this->roundTrip($empty->jsonSerialize())));
		$this->assertSame($golden['filled'], $this->roundTrip(self::filled($class)->jsonSerialize()));
	}//end testFieldTypesAndSerialisedShape()

	/**
	 * An endpoint log expires one week after it is made: check that, then stand the value in for the golden file.
	 *
	 * @param array<string, mixed> $json The serialised entity.
	 *
	 * @return array<string, mixed>
	 */
	private function withoutDefaultExpiry(array $json): array {
		if (array_key_exists('size', $json) === false || array_key_exists('expires', $json) === false) {
			return $json;
		}

		$expires = new DateTime((string) $json['expires']);
		$expected = new DateTime('+1 week');
		$this->assertLessThan(120, abs($expected->getTimestamp() - $expires->getTimestamp()));
		$json['expires'] = '<one week from now>';

		return $json;
	}//end withoutDefaultExpiry()

	/**
	 * An entity with every registered field set to a distinct value of its type.
	 *
	 * @param class-string<Entity> $class The entity.
	 *
	 * @return Entity
	 */
	public static function filled(string $class): Entity {
		$entity = new $class();
		$entity->setId(7);
		$index = 0;
		foreach ($entity->getFieldTypes() as $field => $type) {
			if ($field === 'id') {
				continue;
			}

			$index++;
			$entity->{'set' . ucfirst($field)}(self::valueFor(type: $type, index: $index));
		}

		return $entity;
	}//end filled()

	/**
	 * A distinct value of a field type.
	 *
	 * @param string $type  The field type.
	 * @param int    $index The field's position.
	 *
	 * @return mixed
	 */
	private static function valueFor(string $type, int $index): mixed {
		return match ($type) {
			'integer' => 100 + $index,
			'boolean' => ($index % 2 === 0),
			'float' => 0.5 + $index,
			'json' => ['k' . $index => 'v' . $index],
			'datetime' => new DateTime(sprintf('2026-0%d-1%dT0%d:04:05+0%d:00', ($index % 9) + 1, $index % 10, $index % 10, $index % 3)),
			default => 's' . $index,
		};
	}//end valueFor()

	/**
	 * The value as the golden file stores it (JSON encoded and decoded).
	 *
	 * @param array<string, mixed> $value The serialised entity.
	 *
	 * @return array<string, mixed>
	 */
	private function roundTrip(array $value): array {
		return json_decode((string) json_encode($value, JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION), true);
	}//end roundTrip()
}//end class
