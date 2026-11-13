<?php

/**
 * SourceRecordChangeListener payload contract (D3): masters resolved inline, one recompute per master.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener
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
 * @spec openspec/changes/object-event-sync-async-split/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\BackgroundJob\SourceRecordRecomputeJob;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Listener\SourceRecordChangeListener;
use OCA\OpenRegister\Listener\Support\SourceRecordChangeServices;
use OCA\OpenRegister\Service\Deferral\DeferralQueueServices;
use OCA\OpenRegister\Service\Deferral\ListenerDeferralService;
use OCA\OpenRegister\Service\Merge\MasterRecomputeService;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Tests\Support\ShutdownFunctionSpy;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/../../Support/ShutdownFunctionSpy.php';

/**
 * The listener with the real deferral buffer: only the job list, the session
 * and the Nextcloud services are doubles, so what reaches the job list is what
 * a request would enqueue.
 */
class SourceRecordChangeListenerPayloadTest extends TestCase {

	/**
	 * Every job the buffer enqueued: [jobClass, argument].
	 *
	 * @var array<int, array{0: string, 1: array}>
	 */
	private array $enqueued = [];

	private ListenerDeferralService $deferral;

	private SourceRecordChangeListener $listener;

	protected function setUp(): void {
		parent::setUp();
		ShutdownFunctionSpy::reset();

		$jobList = $this->createMock(IJobList::class);
		$jobList->method('add')->willReturnCallback(
			function (string $jobClass, $argument): void {
				$this->enqueued[] = [$jobClass, $argument];
			}
		);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnArgument(2);

		$this->deferral = new ListenerDeferralService(
			userSession: $session,
			organisation: $this->createMock(OrganisationService::class),
			queue: new DeferralQueueServices(jobList: $jobList, appConfig: $appConfig, logger: $this->createMock(LoggerInterface::class)),
		);

		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('findAll')->willReturn([$this->masterSchema()]);
		$sourceSchema = new Schema();
		$sourceSchema->setSlug('sourceRecord');
		$sourceSchema->setConfiguration([]);
		$schemaMapper->method('find')->willReturn($sourceSchema);

		$recompute = $this->createMock(MasterRecomputeService::class);
		$recompute->expects($this->never())->method('recompute');

		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willThrowException(new \RuntimeException('no cache in tests'));

		$this->listener = new SourceRecordChangeListener(
			new SourceRecordChangeServices($schemaMapper, $this->deferral, $recompute),
			$this->createMock(LoggerInterface::class),
			$cacheFactory
		);
	}

	private function masterSchema(): Schema {
		$schema = new Schema();
		$schema->setConfiguration(
			[
				'x-openregister-survivorship' => [
					'sourceLink' => ['mode' => 'reverseFk', 'sourceSchema' => 'sourceRecord', 'referenceField' => 'currentMasterEntity'],
				],
			]
		);
		return $schema;
	}

	private function source(string $uuid, ?string $master): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setSchema('106');
		$object->setObject(['currentMasterEntity' => $master]);
		return $object;
	}

	/**
	 * The master uuids in every enqueued recompute job, in order.
	 *
	 * @return string[]
	 */
	private function recomputedMasters(): array {
		$masters = [];
		foreach ($this->enqueued as [$jobClass, $argument]) {
			$this->assertSame(SourceRecordRecomputeJob::class, $jobClass);
			foreach ($argument['entries'] as $entry) {
				$masters[] = $entry['masterUuid'];
			}
		}

		return $masters;
	}

	public function testABulkEditOf500SourceRecordsOnOneMasterIsOneRecompute(): void {
		for ($i = 0; $i < 500; $i++) {
			$this->listener->handle(
				new ObjectUpdatedEvent($this->source('src-'.$i, 'master-1'), $this->source('src-'.$i, 'master-1'))
			);
		}

		$this->deferral->flushAll();

		$this->assertCount(1, $this->enqueued);
		$this->assertSame(['master-1'], $this->recomputedMasters());
	}

	public function testReParentingRefreshesBothMastersOnce(): void {
		$this->listener->handle(new ObjectUpdatedEvent($this->source('src-1', 'master-new'), $this->source('src-1', 'master-old')));
		$this->listener->handle(new ObjectUpdatedEvent($this->source('src-2', 'master-new'), $this->source('src-2', 'master-old')));

		$this->deferral->flushAll();

		$masters = $this->recomputedMasters();
		sort($masters);
		$this->assertSame(['master-new', 'master-old'], $masters);
	}

	public function testADeletedSourceCarriesItsMasterResolvedInlineNotTheSourceUuid(): void {
		$this->listener->handle(new ObjectDeletedEvent($this->source('src-gone', 'master-1')));

		$this->deferral->flushAll();

		$this->assertSame(['master-1'], $this->recomputedMasters());
		$this->assertSame([['masterUuid' => 'master-1']], $this->enqueued[0][1]['entries']);
		$this->assertSame('alice', $this->enqueued[0][1]['userId']);
	}

	public function testASourceWithoutAMasterEnqueuesNothing(): void {
		$this->listener->handle(new ObjectDeletedEvent($this->source('src-orphan', null)));

		$this->deferral->flushAll();

		$this->assertSame([], $this->enqueued);
	}
}
