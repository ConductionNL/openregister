<?php

/**
 * Tests for TransferResultProcessor.
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

use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Edepot\TransferAuditLog;
use OCA\OpenRegister\Service\Edepot\TransferContext;
use OCA\OpenRegister\Service\Edepot\TransferDelivery;
use OCA\OpenRegister\Service\Edepot\TransferListService;
use OCA\OpenRegister\Service\Edepot\TransferOutcomeRecorder;
use OCA\OpenRegister\Service\Edepot\TransferRecordService;
use OCA\OpenRegister\Service\Edepot\TransferResultProcessor;
use OCA\OpenRegister\Service\Edepot\TransferSettings;
use OCA\OpenRegister\Service\Edepot\Transport\TransportResult;
use OCP\IAppConfig;
use OCP\Notification\IManager as INotificationManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Service\Edepot\TransferResultProcessor
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Service\Edepot\AuditRecording
 * @uses \OCA\OpenRegister\Service\Edepot\ObjectConfirmation
 * @uses \OCA\OpenRegister\Service\Edepot\TransferAuditLog
 * @uses \OCA\OpenRegister\Service\Edepot\TransferContext
 * @uses \OCA\OpenRegister\Service\Edepot\TransferDelivery
 * @uses \OCA\OpenRegister\Service\Edepot\TransferFailure
 * @uses \OCA\OpenRegister\Service\Edepot\TransferOutcomeRecorder
 * @uses \OCA\OpenRegister\Service\Edepot\TransferSettings
 * @uses \OCA\OpenRegister\Service\Edepot\TransferStores
 * @uses \OCA\OpenRegister\Service\Edepot\Transport\TransportResult
 */
class TransferResultProcessorTest extends TestCase {

	/**
	 * Retention written per uuid, in order.
	 *
	 * @var list<array{0: string|null, 1: array<string, mixed>}>
	 */
	private array $updates = [];

	/**
	 * Proofs created.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $proofs = [];

	/**
	 * A processor over recording collaborators.
	 *
	 * @return TransferResultProcessor
	 */
	private function processor(): TransferResultProcessor {
		$mapper = $this->createMock(MagicMapperInterface::class);
		$mapper->method('update')->willReturnCallback(
			function (ObjectEntity $object): ObjectEntity {
				$this->updates[] = [$object->getUuid(), $object->getRetention()];
				return $object;
			}
		);

		$records = $this->createMock(TransferRecordService::class);
		$records->method('createProof')->willReturnCallback(
			function (array $proof): array {
				$this->proofs[] = $proof;
				return ['uuid' => 'p-'.$proof['objectUuid']];
			}
		);

		$logger   = new NullLogger();
		$auditLog = new TransferAuditLog(new \OCA\OpenRegister\Service\Edepot\AuditRecording($this->createMock(AuditTrailMapperInterface::class), $logger), new TransferSettings($this->createMock(IAppConfig::class), $logger), $this->createMock(INotificationManager::class));

		return new TransferResultProcessor(new TransferOutcomeRecorder(new \OCA\OpenRegister\Service\Edepot\TransferStores(objects: $mapper, records: $records, settings: $this->createMock(\OCP\IAppConfig::class), logger: $logger), $auditLog), $auditLog);
	}//end processor()

	/**
	 * One object with its files.
	 *
	 * @param string $uuid The uuid.
	 *
	 * @return array{object: ObjectEntity, files: list<array<string, string>>}
	 */
	private static function item(string $uuid): array {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setRetention([]);

		return ['object' => $object, 'files' => [['name' => $uuid.'.pdf', 'checksum' => 'c'], ['checksum' => 'unnamed']]];
	}//end item()

	public function testOnlyAnAcceptedObjectIsMarkedTransferredAndProven(): void {
		$context = new TransferContext(transferUuid: 'l', packageFormat: 'zip', transportName: 'sftp', confirmedAt: 'T');
		$results = [new TransportResult(objectResults: ['a' => ['accepted' => true, 'reference' => 'r'], 'b' => ['accepted' => false, 'error' => 'no']])];

		$list = $this->processor()->process(['uuid' => 'l', 'objectReferences' => []], new TransferDelivery($results, [self::item(uuid: 'a'), self::item(uuid: 'b')], $context));

		$this->assertSame(TransferListService::STATUS_PARTIALLY_FAILED, $list['status']);
		$this->assertSame(['completedAt' => 'T', 'objectResults' => $results[0]->getObjectResults()], $list['transferResult']);
		$this->assertSame(
			[
				['a', ['archiefstatus' => 'transferred', 'eDepotReferentie' => 'r', 'transferDate' => 'T']],
				['a', ['archiefstatus' => 'transferred', 'eDepotReferentie' => 'r', 'transferDate' => 'T', 'transferProof' => 'p-a']],
				['b', ['transferErrors' => [['error' => 'no', 'timestamp' => 'T']]]],
			],
			$this->updates
		);
		$this->assertCount(1, $this->proofs);
		$this->assertSame([['name' => 'a.pdf', 'sha256' => 'c']], $this->proofs[0]['fileChecksums']);
		$this->assertSame('r', $this->proofs[0]['transportReceipt']);
		$manifest = hash('sha256', (string)json_encode([['name' => 'a.pdf', 'sha256' => 'c']]));
		$this->assertSame($manifest, $this->proofs[0]['packageManifestSha256']);
	}

	public function testAnOverallSuccessWithoutPerObjectResultsAcceptsEveryObject(): void {
		$context = new TransferContext(transferUuid: 'l', packageFormat: 'bagit', transportName: 'rest', confirmedAt: 'T');

		$results = [new TransportResult(transferReference: 'ref')];

		$list = $this->processor()->process(['uuid' => 'l', 'objectReferences' => []], new TransferDelivery($results, [self::item(uuid: 'a')], $context));

		$this->assertSame(TransferListService::STATUS_COMPLETED, $list['status']);
		$this->assertSame(['a' => ['accepted' => true, 'reference' => 'ref', 'error' => null]], $list['transferResult']['objectResults']);
		$this->assertSame('bagit', $this->proofs[0]['packageFormat']);
	}

	public function testAnOverallFailureRefusesEveryObject(): void {
		$context = new TransferContext(transferUuid: 'l', packageFormat: 'zip', transportName: 'rest', confirmedAt: 'T');
		$results = [(new TransportResult())->failed('down'), (new TransportResult())->failed('still down')];

		$list = $this->processor()->process(['uuid' => 'l', 'objectReferences' => []], new TransferDelivery($results, [self::item(uuid: 'a')], $context));

		$this->assertSame(TransferListService::STATUS_FAILED, $list['status']);
		$this->assertSame(['a' => ['accepted' => false, 'reference' => null, 'error' => 'down']], $list['transferResult']['objectResults']);
		$this->assertSame([], $this->proofs);
		$this->assertSame('down', TransferResultProcessor::firstError(results: $results));
		$this->assertNull(TransferResultProcessor::firstError(results: [new TransportResult()]));
	}
}//end class
