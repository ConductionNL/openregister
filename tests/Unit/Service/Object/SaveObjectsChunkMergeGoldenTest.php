<?php

/**
 * Golden tests for how a bulk save folds each chunk's outcome into the run result.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 * @author   Conduction <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObjects;
use OCA\OpenRegister\Service\Object\SaveObjects\BulkSaveRun;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;

/**
 * Pins saveBulkChunk: the merged rows, the summed counters and one statistics entry per chunk.
 *
 * @covers \OCA\OpenRegister\Service\Object\SaveObjects
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Service\Object\SaveObjects\BulkSaveRun
 */
class SaveObjectsChunkMergeGoldenTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$ref = new ReflectionClass(SaveObjects::class);
		foreach (['schemaCache', 'schemaAnalysisCache', 'registerCache'] as $prop) {
			$ref->getProperty($prop)->setValue(null, []);
		}
	}//end setUp()

	/**
	 * A bulk mapper row in the given state.
	 *
	 * @param string $uuid   The uuid.
	 * @param string $status created, updated or unchanged.
	 *
	 * @return array
	 */
	private static function row(string $uuid, string $status): array {
		return ['uuid' => $uuid, 'object_status' => $status, 'created' => '2024-01-01T00:00:00+00:00', 'updated' => '2024-01-02T00:00:00+00:00'];
	}//end row()

	public function testTwoChunksAreMergedAndCountedInOrder(): void {
		$mapper = $this->createMock(MagicMapper::class);
		$mapper->method('ultraFastBulkSave')->willReturnOnConsecutiveCalls(
			[self::row('c1-a', 'created'), self::row('c1-b', 'updated')],
			[self::row('c2-a', 'created'), self::row('c2-b', 'unchanged'), self::row('c2-c', 'created')]
		);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$organisations = $this->createMock(OrganisationService::class);
		$organisations->method('getOrganisationForNewEntity')->willReturn('org-1');
		$handler = new SaveObjects(
			$mapper,
			$this->createMock(SchemaMapper::class),
			$this->createMock(RegisterMapper::class),
			$this->createMock(SaveObject::class),
			$session,
			$organisations,
			$this->createMock(LoggerInterface::class)
		);

		$register = new Register();
		$register->setId(1);
		$schema = new Schema();
		$schema->setId(2);
		$schema->setProperties([]);
		$schema->setConfiguration([]);

		$run = new BulkSaveRun();
		$run->register = $register;
		$run->schema = $schema;
		$run->rbac = false;
		$run->multitenancy = false;
		$run->totalObjects = 5;
		$run->result = (new ReflectionMethod(SaveObjects::class, 'initializeSaveResult'))->invoke($handler, 5);
		$run->result['saved'] = [['uuid' => 'before']];
		$run->result['statistics']['saved'] = 10;

		$prepared = (new ReflectionMethod(SaveObjects::class, 'prepareBulkObjects'))->invoke(
			$handler,
			$run,
			[['name' => 'a'], ['name' => 'b'], ['name' => 'c'], ['name' => 'd'], ['name' => 'e']]
		);
		$save = new ReflectionMethod(SaveObjects::class, 'saveBulkChunk');
		$run->chunkIndex = 0;
		$save->invoke($handler, $run, array_slice($prepared, 0, 2));
		$run->chunkIndex = 1;
		$save->invoke($handler, $run, array_slice($prepared, 2));

		$result = $run->result;
		$this->assertSame(['before', 'c1-a', 'c2-a', 'c2-c'], array_column($result['saved'], 'uuid'));
		$this->assertSame(['c1-b'], array_column($result['updated'], 'uuid'));
		$this->assertSame(['c2-b'], array_column($result['unchanged'], 'uuid'));
		$this->assertSame([], $result['invalid']);
		$this->assertSame([], $result['errors']);
		$this->assertSame(
			['totalProcessed' => 5, 'saved' => 13, 'updated' => 1, 'unchanged' => 1, 'invalid' => 0, 'errors' => 0],
			array_intersect_key($result['statistics'], array_flip(['totalProcessed', 'saved', 'updated', 'unchanged', 'invalid', 'errors']))
		);

		$this->assertCount(2, $result['chunkStatistics']);
		$untimed = [];
		foreach ($result['chunkStatistics'] as $entry) {
			$this->assertSame(['chunkIndex', 'count', 'saved', 'updated', 'unchanged', 'invalid', 'processingTime', 'speed', 'phaseMs'], array_keys($entry));
			$this->assertIsFloat($entry['processingTime']);
			$this->assertGreaterThanOrEqual(0.0, $entry['processingTime']);
			$this->assertIsFloat($entry['speed']);
			$this->assertGreaterThan(0.0, $entry['speed']);
			$this->assertSame(['transform', 'guards', 'persist', 'buildResults', 'sideEffects'], array_keys($entry['phaseMs']));
			unset($entry['processingTime'], $entry['speed'], $entry['phaseMs']);
			$untimed[] = $entry;
		}

		$this->assertSame(
			[
				['chunkIndex' => 0, 'count' => 2, 'saved' => 1, 'updated' => 1, 'unchanged' => 0, 'invalid' => 0],
				['chunkIndex' => 1, 'count' => 3, 'saved' => 2, 'updated' => 0, 'unchanged' => 1, 'invalid' => 0],
			],
			$untimed
		);
	}//end testTwoChunksAreMergedAndCountedInOrder()
}//end class
