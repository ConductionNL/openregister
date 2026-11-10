<?php

/**
 * Unit tests for a relationship batch that loads but cannot be indexed.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Db\MagicMapper\RelatedRowRbacInterface;
use OCA\OpenRegister\Service\Object\RelationHandler;
use OCA\OpenRegister\Service\Object\RelationHandlerStores;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A batch whose objects cannot be indexed is logged with its number and size and dropped; the other batches still load.
 *
 * @covers \OCA\OpenRegister\Service\Object\RelationHandler
 */
class RelationHandlerBatchFailureTest extends TestCase {

	/**
	 * The second batch fails; the first and third are returned, indexed by uuid and id.
	 *
	 * @return void
	 */
	public function testAFailingBatchIsLoggedAndSkipped(): void {
		$ids = array_map(static fn (int $i): string => 'u' . $i, range(1, 120));

		$rows = [];
		$mapper = $this->createMock(MagicMapperInterface::class);
		$mapper->method('findMultipleAcrossAllMagicTables')->willReturnCallback(
			function (array $uuids) use (&$rows): array {
				$located = [];
				foreach ($uuids as $id) {
					$rows[$id] = $this->row(id: $id, broken: in_array('u51', $uuids, true));
					$located[] = $rows[$id];
				}

				return $located;
			}
		);
		$mapper->method('findAllInRegisterSchemaTable')->willReturnCallback(
			static function ($register, $schema, $limit = null, $offset = null, ?array $filters = null) use (&$rows): array {
				return array_values(array_intersect_key($rows, array_flip($filters['_ids'] ?? [])));
			}
		);

		$errors = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			static function (string $message, array $context) use (&$errors): void {
				$errors[] = [$message, $context['batch'], $context['error'], $context['idsInBatch']];
			}
		);

		$handler = new RelationHandler(
			new RelationHandlerStores(
				objects: $mapper,
				schemas: $this->createMock(SchemaMapperInterface::class),
				registers: $this->createMock(RegisterMapperInterface::class)
			),
			$this->admin(),
			$logger
		);

		$loaded = $handler->bulkLoadRelationshipsBatched($ids);

		$this->assertSame([['[RelationHandler] ❌ BATCH LOADING FAILED', 2, 'corrupt row', 50]], $errors);
		$this->assertArrayHasKey('u1', $loaded);
		$this->assertArrayHasKey('u120', $loaded);
		$this->assertArrayNotHasKey('u51', $loaded);
		$this->assertSame('u101', $loaded[1101]->getUuid());
		$this->assertCount(140, $loaded, '70 objects from batches 1 and 3, each under uuid and id');

	}//end testAFailingBatchIsLoggedAndSkipped()

	/**
	 * A related row in register 1, schema 1; a broken one cannot give its id.
	 *
	 * @param string $id     The uuid.
	 * @param bool   $broken Whether the row is broken.
	 *
	 * @return ObjectEntity The row.
	 */
	private function row(string $id, bool $broken): ObjectEntity {
		$object = new ObjectEntity();
		if ($broken === true) {
			$object = new class extends ObjectEntity {
				public function getId(): int {
					throw new RuntimeException('corrupt row');
				}
			};
		}

		$object->setUuid($id);
		$object->setId((int)substr($id, 1) + 1000);
		$object->setRegister('1');
		$object->setSchema('1');
		return $object;
	}//end row()

	/**
	 * An RBAC handler for an admin, who may read every row.
	 *
	 * @return RelatedRowRbacInterface The handler.
	 */
	private function admin(): RelatedRowRbacInterface {
		$rbac = $this->createMock(RelatedRowRbacInterface::class);
		$rbac->method('isAdmin')->willReturn(true);
		return $rbac;
	}//end admin()
}//end class
