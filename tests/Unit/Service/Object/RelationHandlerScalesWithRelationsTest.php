<?php

/**
 * Regression: Related on a pipelinq lead took seconds for two results.
 *
 * Measured on :8099 on 9 October 2026: `/uses` took 4.5 to 7 seconds and
 * `/used` 6.4 to 8 seconds for lead 09071313-..., each answering two objects.
 * getUses() loaded every register and called schemaMapper->find() for every
 * schema (326), then queried the tables one by one; getUsedBy() ran a JSON
 * query in every magic table (333) with a register and schema find per table.
 * Both now ask one cross-table lookup which tables hold a match, and read only
 * those tables through the same filtered query as before.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/notification-links-in-releases-and-case-insensitive-order/specs/linked-entity-types/spec.md#requirement-related-objects-must-be-found-without-scanning-every-schema
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\PerformanceHandler;
use OCA\OpenRegister\Service\Object\RelationHandler;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Locks that a relation lookup touches only the tables that hold a match.
 */
class RelationHandlerScalesWithRelationsTest extends TestCase {

	private const LEAD = '09071313-4c14-420e-a419-d3f2a7d270b8';

	/**
	 * An object as the cross-table lookup returns it.
	 *
	 * @param string $uuid      The uuid.
	 * @param int    $register  The register id.
	 * @param int    $schema    The schema id.
	 * @param array  $relations The object's relations.
	 *
	 * @return ObjectEntity The object.
	 */
	private function object(string $uuid, int $register, int $schema, array $relations = []): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setRegister((string) $register);
		$object->setSchema((string) $schema);
		$object->setRelations($relations);

		return $object;
	}//end object()

	/**
	 * The handler on mappers that refuse the scans the old code ran.
	 *
	 * @param MagicMapper $magic The magic mapper double.
	 *
	 * @return RelationHandler The handler.
	 */
	private function handler(MagicMapper $magic): RelationHandler {
		$registerMapper = $this->createMock(RegisterMapper::class);
		// Loading every register was the first step of the old scan.
		$registerMapper->expects($this->never())->method('findAll');
		$registerMapper->method('find')->willReturnCallback(
			static function ($id): Register {
				$register = new Register();
				$register->setId((int) $id);
				return $register;
			}
		);

		$schemaFinds = 0;
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturnCallback(
			function ($id) use (&$schemaFinds): Schema {
				$schemaFinds++;
				$this->assertLessThanOrEqual(3, $schemaFinds, 'schemas are loaded for matching tables only');
				$schema = new Schema();
				$schema->setId((int) $id);
				return $schema;
			}
		);

		$rbac = $this->createMock(MagicRbacHandler::class);
		$rbac->method('isAdmin')->willReturn(true);

		return new RelationHandler(
			objectEntityMapper: $magic,
			schemaMapper: $schemaMapper,
			performanceHandler: $this->createMock(PerformanceHandler::class),
			rbacHandler: $rbac,
			logger: $this->createMock(LoggerInterface::class),
			registerMapper: $registerMapper
		);
	}//end handler()

	public function testUsesReadsOnlyTheTablesHoldingTheRelatedObjects(): void {
		$lead = $this->object(self::LEAD, 20, 30, ['client' => 'c-1', 'contacts' => ['p-1']]);
		$client = $this->object('c-1', 20, 28);
		$contact = $this->object('p-1', 20, 29);

		$magic = $this->createMock(MagicMapper::class);
		$magic->method('find')->willReturn($lead);
		$magic->expects($this->once())->method('findMultipleAcrossAllMagicTables')->willReturn([$client, $contact]);
		$magic->expects($this->exactly(2))->method('findAllInRegisterSchemaTable')->willReturnCallback(
			static fn (Register $register, Schema $schema, ?int $limit = null, ?int $offset = null, ?array $filters = null): array => match ($schema->getId()) {
				28 => [$client],
				29 => [$contact],
			}
		);

		$result = $this->handler($magic)->getUses(self::LEAD);

		$this->assertSame(2, $result['total']);
		$this->assertSame(['c-1', 'p-1'], array_map(static fn (array $row): string => $row['@self']['id'] ?? $row['id'], $result['results']));
	}//end testUsesReadsOnlyTheTablesHoldingTheRelatedObjects()

	public function testUsedByReadsOnlyTheTablesHoldingAReference(): void {
		$lead = $this->object(self::LEAD, 20, 30);
		$product = $this->object('lp-1', 20, 31, ['lead' => self::LEAD]);

		$magic = $this->createMock(MagicMapper::class);
		$magic->method('find')->willReturn($lead);
		// The old code listed every magic table and queried each one.
		$magic->expects($this->never())->method('getExistingRegisterSchemaTables');
		$magic->expects($this->once())->method('findByRelationAcrossAllMagicTables')->with(self::LEAD)->willReturn([$product]);
		$magic->expects($this->once())->method('findAllInRegisterSchemaTable')->willReturn([$product]);

		$result = $this->handler($magic)->getUsedBy(self::LEAD);

		$this->assertSame(1, $result['total']);
	}//end testUsedByReadsOnlyTheTablesHoldingAReference()
}//end class
