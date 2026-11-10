<?php

/**
 * Golden test for the calls TransferRecordService makes.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service\Edepot
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Edepot;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Edepot\TransferRecordService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins every object-store call (and its arguments, including the RBAC and
 * multitenancy defaults it leaves in place) and every outcome of the
 * transfer-record persistence.
 *
 * @covers \OCA\OpenRegister\Service\Edepot\TransferRecordService
 */
class TransferRecordServiceGoldenTest extends TestCase {

	/**
	 * Every recorded call, in order.
	 *
	 * @var list<array<int, mixed>>
	 */
	private array $calls = [];

	/**
	 * What findAll returns, in order; null throws.
	 *
	 * @var list<array<mixed>|null>
	 */
	private array $rows = [];

	/**
	 * What find returns; false throws.
	 *
	 * @var ObjectEntity|null|false
	 */
	private ObjectEntity|null|false $found = null;

	/**
	 * The service over a recording object store.
	 *
	 * @return TransferRecordService
	 */
	private function service(): TransferRecordService {
		$store = $this->createMock(ObjectOperationsInterface::class);
		$store->method('saveObject')->willReturnCallback(
			function (mixed ...$args): ObjectEntity {
				$this->calls[] = ['saveObject', $args];
				return self::entity(uuid: 'saved-uuid', data: ['stored' => true]);
			}
		);
		$store->method('createObject')->willReturnCallback(
			function (mixed ...$args): ObjectEntity {
				$this->calls[] = ['createObject', $args];
				return self::entity(uuid: 'proof-uuid', data: ['proof' => true, 'uuid' => 'ignored']);
			}
		);
		$store->method('find')->willReturnCallback(
			function (mixed ...$args): ?ObjectEntity {
				$this->calls[] = ['find', $args];
				if ($this->found === false) {
					throw new \RuntimeException('not there');
				}

				return $this->found;
			}
		);
		$store->method('findAll')->willReturnCallback(
			function (mixed ...$args): array {
				$this->calls[] = ['findAll', $args];
				$rows = array_shift($this->rows);
				if ($rows === null) {
					throw new \RuntimeException('index down');
				}

				return $rows;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message): void {
				$this->calls[] = ['warning', $message];
			}
		);

		return new TransferRecordService(objectService: $store, logger: $logger);
	}//end service()

	/**
	 * An entity with a uuid and data.
	 *
	 * @param string               $uuid The uuid.
	 * @param array<string, mixed> $data The data.
	 *
	 * @return ObjectEntity
	 */
	private static function entity(string $uuid, array $data): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setObject($data);

		return $object;
	}//end entity()

	public function testSavingAListAlignsItsUuid(): void {
		$service = $this->service();
		$this->calls[] = ['result', $service->saveTransferList(['uuid' => 'l1', 'status' => 'in_review'])];
		$this->calls[] = ['result', $service->saveTransferList(['status' => 'in_review'])];
		$this->calls[] = ['result', $service->saveTransferList(['uuid' => '', 'status' => 'x'])];

		$this->assertMatchesGolden(name: 'save');
	}

	public function testLoadingAListDegradesToNull(): void {
		$service = $this->service();
		$this->found = self::entity(uuid: 'l1', data: ['status' => 'approved']);
		$this->calls[] = ['result', $service->loadTransferList('l1')];
		$this->found = null;
		$this->calls[] = ['result', $service->loadTransferList('l2')];
		$this->found = false;
		$this->calls[] = ['result', $service->loadTransferList('l3')];

		$this->assertMatchesGolden(name: 'load');
	}

	public function testListingKeepsArrayRowsAndDegradesToEmpty(): void {
		$this->rows = [[['uuid' => 'a'], 'junk', self::entity(uuid: 'x', data: []), ['uuid' => 'b']], null];
		$service = $this->service();
		$this->calls[] = ['result', $service->listTransferLists()];
		$this->calls[] = ['result', $service->listTransferLists()];

		$this->assertMatchesGolden(name: 'list');
	}

	public function testAProofIsWrittenOnce(): void {
		$service = $this->service();
		$this->rows = [[], ['junk', ['uuid' => 'existing']], null];
		$this->calls[] = ['result', $service->createProof(['objectUuid' => 'o', 'transferUuid' => 't', 'confirmedAt' => 'T'])];
		$this->calls[] = ['result', $service->createProof(['objectUuid' => 'o', 'transferUuid' => 't'])];
		$this->calls[] = ['result', $service->createProof(['objectUuid' => 'o', 'transferUuid' => 't', 'confirmedAt' => 'T2'])];
		$this->calls[] = ['result', $service->createProof(['objectUuid' => '', 'transferUuid' => 't', 'confirmedAt' => 'T3'])];

		$this->assertMatchesGolden(name: 'proof');
	}

	public function testFindingAProofNeedsBothUuids(): void {
		$service = $this->service();
		$this->rows = [['junk'], [['uuid' => 'p']]];
		$this->calls[] = ['result', $service->findProof('o', '')];
		$this->calls[] = ['result', $service->findProof('', 't')];
		$this->calls[] = ['result', $service->findProof('o', 't')];
		$this->calls[] = ['result', $service->findProof('o', 't')];

		$this->assertMatchesGolden(name: 'find-proof');
	}

	/**
	 * Compare the recorded calls with the stored golden file.
	 *
	 * @param string $name The golden name.
	 *
	 * @return void
	 */
	private function assertMatchesGolden(string $name): void {
		$calls = json_encode($this->calls, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		$calls = (string)preg_replace('/"\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}"/', '"<now>"', (string)$calls);
		$path  = __DIR__.'/fixtures/transfer-record-golden/'.$name.'.json';
		if (getenv('GOLDEN_WRITE') === '1') {
			@mkdir(dirname($path), 0777, true);
			file_put_contents($path, $calls."\n");
		}

		$this->assertFileExists($path);
		$this->assertSame(file_get_contents($path), $calls."\n");
	}//end assertMatchesGolden()
}//end class
