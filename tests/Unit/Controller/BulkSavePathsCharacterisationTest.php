<?php

/**
 * BulkController save-path characterisation tests.
 *
 * Pins which write path a bulk save takes for each combination of `atomic`,
 * `stream` and `partial`, and what that path is handed, so the paths can take
 * one batch value without the outcome moving.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Service\Object\SaveObject\StreamingSave;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\BatchOperationStatus;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\BulkControllerFactory;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Characterisation of BulkController::save() path selection and arguments.
 */
class BulkSavePathsCharacterisationTest extends TestCase {

	/**
	 * Every call the doubles saw, in order, with its arguments.
	 *
	 * @var list<array>
	 */
	private array $calls = [];

	/**
	 * Run a bulk save as an administrator into register 1, the given schema.
	 *
	 * @param array  $params      The request body.
	 * @param int    $schemaId    The schema id the route resolves to (0 = mixed).
	 * @param bool   $checkedFail Whether the checked save refuses the second row.
	 *
	 * @return JSONResponse
	 */
	private function save(array $params, int $schemaId, bool $checkedFail = false): JSONResponse {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('root');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(true);

		$schema = new Schema();
		$schema->setId(2);
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willReturn($schema);

		$objects = $this->createMock(ObjectService::class);
		$objects->method('setRegister')->willReturnSelf();
		$objects->method('setSchema')->willReturnSelf();
		$objects->method('getRegister')->willReturn(1);
		$objects->method('getSchema')->willReturn($schemaId);
		$objects->method('saveObjectsStreaming')->willReturnCallback(
			function (StreamingSave $save): BatchOperationStatus {
				$this->calls[] = ['stream', [$save->rows, $save->register, $save->schema]];
				$status = new BatchOperationStatus();
				$status->start();
				$status->recordCreated('11111111-1111-1111-1111-111111111111');
				$status->complete();
				return $status;
			}
		);
		$objects->method('saveObjects')->willReturnCallback(
			function (...$args) use ($checkedFail): array {
				$this->calls[] = ['checked', array_slice($args, 0, 7)];
				if ($checkedFail === true) {
					return [
						'statistics' => ['saved' => 1],
						'invalid' => [['index' => 1, 'error' => 'name is required', 'type' => 'ValidationException']],
					];
				}

				return ['statistics' => ['saved' => 2, 'updated' => 0]];
			}
		);

		$db = $this->createMock(IDBConnection::class);
		foreach (['beginTransaction', 'commit', 'rollBack'] as $method) {
			$db->method($method)->willReturnCallback(
				function () use ($method): bool {
					$this->calls[] = [$method];
					return true;
				}
			);
		}

		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params + ['objects' => [['name' => 'a'], ['name' => 'b']]]);

		return BulkControllerFactory::create(
			'openregister',
			$request,
			$objects,
			$this->createMock(RegisterMapper::class),
			$schemas,
			$session,
			$groups,
			$db
		)->save('1', (string)$schemaId);
	}

	public function testTheCheckedPathGetsTheRowsRegisterSchemaAndFlags(): void {
		$response = $this->save([], 2);

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(
			[['checked', [[['name' => 'a'], ['name' => 'b']], 1, 2, true, true, true, false]]],
			$this->calls
		);
		$this->assertFalse($response->getData()['partial']);
		$this->assertSame('Bulk save operation completed successfully', $response->getData()['message']);
	}

	public function testAMixedSchemaStreamGetsANullSchemaAndKeepsPartial(): void {
		$response = $this->save(['stream' => 'true', 'partial' => '1'], 0);

		$this->assertSame(200, $response->getStatus());
		$this->assertSame([['stream', [[['name' => 'a'], ['name' => 'b']], 1, null]]], $this->calls);
		$data = $response->getData();
		$this->assertTrue($data['partial']);
		$this->assertFalse($data['success']);
		$this->assertSame(1, $data['saved_count']);
		$this->assertSame(2, $data['requested_count']);
		$this->assertArrayHasKey('saved_objects', $data);
	}

	public function testAtomicWinsOverStreamAndPartialAndRollsBackARefusedRow(): void {
		$response = $this->save(['atomic' => 'yes', 'stream' => true, 'partial' => true], 2, true);

		$this->assertSame(422, $response->getStatus());
		$this->assertSame(
			[
				['beginTransaction'],
				['checked', [[['name' => 'a'], ['name' => 'b']], 1, 2, true, true, true, false]],
				['rollBack'],
			],
			$this->calls
		);
		$data = $response->getData();
		$this->assertFalse($data['partial']);
		$this->assertTrue($data['atomic']);
		$this->assertSame('Atomic batch rolled back: row 1 was refused (name is required). No row was written.', $data['message']);
	}

	public function testACleanAtomicBatchCommitsWithPartialFalse(): void {
		$response = $this->save(['atomic' => true, 'partial' => true], 2);

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['beginTransaction'], $this->calls[0]);
		$this->assertSame(['commit'], $this->calls[2]);
		$this->assertFalse($response->getData()['partial']);
		$this->assertTrue($response->getData()['atomic']);
	}

	public function testAPartialCheckedShortfallIs200WithSuccessFalse(): void {
		$response = $this->save(['partial' => true], 2, true);

		$this->assertSame(200, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertTrue($data['partial']);
		$this->assertSame(1, $data['failed_count']);
		$this->assertSame(
			'Bulk save incomplete: 1 of 2 objects were rejected and NOT written. See "failures" for the reason per object.',
			$data['message']
		);
	}
}
