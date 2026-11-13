<?php

/**
 * UrnService after a move: a record keeps its URN, and resolving the URN it
 * had before the move answers its new address.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/capability-rows-stated-requirements/specs/urn-resource-addressing/spec.md#requirement-a-record-keeps-its-urn-when-it-moves-to-another-register-or-schema
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.
// phpcs:disable Squiz.Commenting.VariableComment.Missing -- typed mock fixtures; the declaration IS the description.

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrail\ActionLookupOptions;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaCatalog;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\UrnService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\UrnService
 */
class UrnServiceMoveTest extends TestCase {

	private const UUID = '1c1c970f-d50c-4943-8128-78999e240eec';
	private const BASE = 'https://cloud.example.com';
	private const OLD_URN = 'urn:nl-or:cloud-example-com:decidesk:meeting:' . self::UUID;

	/** @var list<array{uuid: string, options: ActionLookupOptions}> */
	private array $lookups = [];

	/**
	 * A register or schema with an id and a slug.
	 *
	 * @param class-string<Register|Schema> $class The entity class.
	 * @param int                           $id    The id.
	 * @param string                        $slug  The slug.
	 *
	 * @return Register|Schema
	 */
	private static function entity(string $class, int $id, string $slug): Register|Schema {
		$entity = new $class();
		$entity->setId($id);
		$entity->setSlug($slug);

		return $entity;
	}

	/**
	 * A `moved` entry from one address to another.
	 *
	 * @param array{register: int, schema: int} $from The old address ids.
	 * @param array{register: int, schema: int} $to   The new address ids.
	 *
	 * @return AuditTrail
	 */
	private function moved(array $from, array $to): AuditTrail {
		$entry = $this->createMock(AuditTrail::class);
		$entry->method('getChanged')->willReturn(['from' => $from, 'to' => $to]);

		return $entry;
	}

	/**
	 * The service over two registers and two schemas, with these `moved` entries.
	 *
	 * @param list<AuditTrail>|RuntimeException $entries The trail's answer, newest first, or its failure.
	 *
	 * @return UrnService
	 */
	private function service(array|RuntimeException $entries): UrnService {
		$registers = $this->createMock(RegisterMapperInterface::class);
		$registers->method('find')->willReturnCallback(
			static fn (string|int $ref): Register => match ((string)$ref) {
				'1', 'decidesk' => self::entity(Register::class, 1, 'decidesk'),
				'3', 'archief' => self::entity(Register::class, 3, 'archief'),
				default => throw new DoesNotExistException('no register'),
			}
		);
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('find')->willReturnCallback(
			static fn (string|int $ref): Schema => match ((string)$ref) {
				'2', 'meeting' => self::entity(Schema::class, 2, 'meeting'),
				'4', 'besluit' => self::entity(Schema::class, 4, 'besluit'),
				default => throw new DoesNotExistException('no schema'),
			}
		);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $path): string => self::BASE . $path);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('');

		$trail = $this->createMock(AuditTrailMapperInterface::class);
		$trail->method('findObjectActions')->willReturnCallback(
			function (string $objectUuid, ActionLookupOptions $options) use ($entries): array {
				$this->lookups[] = ['uuid' => $objectUuid, 'options' => $options];
				if ($entries instanceof RuntimeException) {
					throw $entries;
				}

				return $entries;
			}
		);

		return new UrnService(
			new SchemaCatalog(registers: $registers, schemas: $schemas, tables: $this->createMock(MagicMapperInterface::class)),
			$urls,
			$config,
			$this->createMock(LoggerInterface::class),
			$trail
		);
	}

	public function testTheOldUrnOfAMovedRecordAnswersItsNewAddress(): void {
		$service = $this->service([$this->moved(from: ['register' => 1, 'schema' => 2], to: ['register' => 3, 'schema' => 4])]);

		$this->assertSame(self::BASE . '/apps/openregister/api/objects/archief/besluit/' . self::UUID, $service->resolveUrl(self::OLD_URN));
		$this->assertSame(self::UUID, $this->lookups[0]['uuid'], 'the uuid is kept');
		$this->assertSame(['moved'], $this->lookups[0]['options']->actions);
	}

	public function testARecordMovedTwiceAnswersWhereItLivesNow(): void {
		$service = $this->service(
			[
				$this->moved(from: ['register' => 3, 'schema' => 4], to: ['register' => 3, 'schema' => 2]),
				$this->moved(from: ['register' => 1, 'schema' => 2], to: ['register' => 3, 'schema' => 4]),
			]
		);

		$this->assertSame(self::BASE . '/apps/openregister/api/objects/archief/meeting/' . self::UUID, $service->resolveUrl(self::OLD_URN));
	}

	public function testARecordThatNeverLeftTheUrnsAddressStaysThere(): void {
		$this->assertSame(
			self::BASE . '/apps/openregister/api/objects/decidesk/meeting/' . self::UUID,
			$this->service([])->resolveUrl(self::OLD_URN),
			'no move'
		);
		$this->assertSame(
			self::BASE . '/apps/openregister/api/objects/decidesk/meeting/' . self::UUID,
			$this->service([$this->moved(from: ['register' => 3, 'schema' => 4], to: ['register' => 3, 'schema' => 2])])->resolveUrl(self::OLD_URN),
			'it moved, but never from the address this URN names'
		);
	}

	public function testATrailThatCannotBeReadKeepsTheUrnsOwnAddress(): void {
		$this->assertSame(
			self::BASE . '/apps/openregister/api/objects/decidesk/meeting/' . self::UUID,
			$this->service(new RuntimeException('db down'))->resolveUrl(self::OLD_URN)
		);
	}

	public function testAMoveTargetThatNoLongerResolvesKeepsTheUrnsOwnAddress(): void {
		$service = $this->service([$this->moved(from: ['register' => 1, 'schema' => 2], to: ['register' => 9, 'schema' => 9])]);

		$this->assertSame(self::BASE . '/apps/openregister/api/objects/decidesk/meeting/' . self::UUID, $service->resolveUrl(self::OLD_URN));
	}
}
