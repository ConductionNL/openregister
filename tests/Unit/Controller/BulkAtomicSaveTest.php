<?php

/**
 * An atomic bulk save is written whole or not at all (REQ-ATOMIC-001).
 *
 * The bulk endpoint writes row by row and keeps what succeeded. With
 * `atomic: true` it runs in one transaction and a refused row rolls back every
 * row. The transaction here is a REAL SQLite transaction: the save the double
 * stands in for writes its rows into a table on the same connection, so a
 * rollback that did not happen leaves rows behind that the test can count.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/objects-crud/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use OCA\OpenRegister\Controller\BulkController;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Controller\BulkController
 */
class BulkAtomicSaveTest extends TestCase {

	private Connection $sqlite;

	private IRequest&MockObject $request;

	private ObjectService&MockObject $objectService;

	private BulkController $controller;

	protected function setUp(): void {
		$this->sqlite = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
		$this->sqlite->setNestTransactionsWithSavepoints(true);
		$this->sqlite->executeStatement('CREATE TABLE rows_written (name TEXT)');

		// Nextcloud's connection over the same SQLite handle: the three calls
		// the controller makes go to the real transaction.
		$db = $this->getMockBuilder(IDBConnection::class)->getMock();
		$db->method('beginTransaction')->willReturnCallback(fn () => $this->sqlite->beginTransaction());
		$db->method('commit')->willReturnCallback(fn () => $this->sqlite->commit());
		$db->method('rollBack')->willReturnCallback(fn () => $this->sqlite->rollBack());
		$db->method('inTransaction')->willReturnCallback(fn () => $this->sqlite->isTransactionActive());

		$this->request = $this->createMock(IRequest::class);
		$this->objectService = $this->createMock(ObjectService::class);
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();
		$this->objectService->method('getRegister')->willReturn(1);
		$this->objectService->method('getSchema')->willReturn(2);

		// The save writes every valid row, and refuses the one without a name,
		// as the real save path does: rows before it are already written.
		$this->objectService->method('saveObjects')->willReturnCallback(
			function (array $objects): array {
				$invalid = [];
				$saved = 0;
				foreach ($objects as $index => $object) {
					if (($object['name'] ?? '') === '') {
						$invalid[] = ['index' => $index, 'object' => $object, 'error' => 'name is required', 'type' => 'ValidationException'];
						continue;
					}

					$this->sqlite->insert('rows_written', ['name' => $object['name']]);
					$saved++;
				}

				return ['statistics' => ['saved' => $saved], 'invalid' => $invalid];
			}
		);

		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturn(new Schema());

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(true);

		$this->controller = new BulkController(
			'openregister',
			$this->request,
			$this->objectService,
			$this->createMock(RegisterMapper::class),
			$schemaMapper,
			$session,
			$groups,
			$db
		);
	}//end setUp()

	/**
	 * Rows in the table.
	 *
	 * @return int
	 */
	private function stored(): int {
		return (int) $this->sqlite->fetchOne('SELECT COUNT(*) FROM rows_written');
	}//end stored()

	/**
	 * One bad row stops the batch: nothing is stored and the answer names row 2.
	 */
	public function testOneBadRowStopsAnAtomicBatch(): void {
		$this->request->method('getParams')->willReturn(
			['atomic' => true, 'objects' => [['name' => 'order'], ['name' => 'line 1'], ['name' => '']]]
		);

		$response = $this->controller->save('1', '2');

		$this->assertSame(0, $this->stored());
		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertTrue($data['atomic']);
		$this->assertSame(0, $data['saved_count']);
		$this->assertSame(2, $data['failures'][0]['index']);
		$this->assertStringContainsString('row 2', $data['message']);
	}//end testOneBadRowStopsAnAtomicBatch()

	/**
	 * A clean atomic batch is committed.
	 */
	public function testACleanAtomicBatchIsCommitted(): void {
		$this->request->method('getParams')->willReturn(
			['atomic' => true, 'objects' => [['name' => 'order'], ['name' => 'line 1']]]
		);

		$response = $this->controller->save('1', '2');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(2, $this->stored());
		$this->assertFalse($this->sqlite->isTransactionActive());
	}//end testACleanAtomicBatchIsCommitted()

	/**
	 * Without `atomic`, the endpoint keeps the rows that wrote, as before.
	 */
	public function testWithoutAtomicTheRowsThatWroteStay(): void {
		$this->request->method('getParams')->willReturn(
			['objects' => [['name' => 'order'], ['name' => '']]]
		);

		$this->controller->save('1', '2');

		$this->assertSame(1, $this->stored());
	}//end testWithoutAtomicTheRowsThatWroteStay()

	/**
	 * An atomic batch over the limit is refused before anything is written.
	 */
	public function testAnAtomicBatchOverTheLimitIsRefused(): void {
		$rows = array_fill(0, BulkController::ATOMIC_BATCH_LIMIT + 1, ['name' => 'x']);
		$this->request->method('getParams')->willReturn(['atomic' => true, 'objects' => $rows]);

		$response = $this->controller->save('1', '2');

		$this->assertSame(Http::STATUS_REQUEST_ENTITY_TOO_LARGE, $response->getStatus());
		$this->assertSame(BulkController::ATOMIC_BATCH_LIMIT, $response->getData()['limit']);
		$this->assertSame(0, $this->stored());
	}//end testAnAtomicBatchOverTheLimitIsRefused()

	/**
	 * A save that throws mid-batch rolls back what it wrote.
	 */
	public function testAThrowingSaveRollsBack(): void {
		// The second row's insert fails in the database, after the first wrote.
		$this->sqlite->executeStatement("CREATE TRIGGER boom BEFORE INSERT ON rows_written WHEN NEW.name = 'b' BEGIN SELECT RAISE(ABORT, 'disk full'); END");
		$this->request->method('getParams')->willReturn(['atomic' => true, 'objects' => [['name' => 'a'], ['name' => 'b']]]);

		$response = $this->controller->save('1', '2');

		$this->assertSame(0, $this->stored());
		$this->assertFalse($this->sqlite->isTransactionActive());
		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
	}//end testAThrowingSaveRollsBack()
}//end class
