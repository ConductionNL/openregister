<?php

/**
 * ObjectTextExtractionInChunksTest
 *
 * ObjectChangeListener in background mode, wired to the real
 * ListenerDeferralService: what reaches the job list after N object events in
 * one process. Before openregister#4241 that was N ObjectTextExtractionJobs;
 * a flow writing 700 objects item by item left 1,375 of them, and the cron
 * pass that worked through them never reached the next scheduled flow.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Listener
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\BackgroundJob\JobHandles;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Kernel\Contracts\AppSettingsInterface;
use OCA\OpenRegister\Kernel\Contracts\TextExtractionServiceInterface;
use OCA\OpenRegister\Listener\ObjectChangeListener;
use OCA\OpenRegister\Service\Deferral\ListenerDeferralService;
use OCA\OpenRegister\Service\OrganisationServiceInterface;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * N object events in background mode reach the job list as ⌈N / chunk size⌉ chunk jobs.
 *
 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-object-text-extraction-in-background-mode-is-deferred-in-chunks
 */
class ObjectTextExtractionInChunksTest extends TestCase {

	/**
	 * What the job list received: [job class, argument] per add().
	 *
	 * @var array<int, array{0: string, 1: mixed}>
	 */
	private array $added = [];

	/**
	 * The deferral the listener writes into; flushed by each test.
	 *
	 * @var ListenerDeferralService
	 */
	private ListenerDeferralService $deferral;

	/**
	 * A listener in the given deferral mode and chunk size, sharing the job list.
	 *
	 * @param string   $deferralMode `background` (default) or `inline` (kill switch).
	 * @param int|null $chunkSize    The textExtractionChunkSize app config; null = not set.
	 *
	 * @return ObjectChangeListener
	 */
	private function listener(string $deferralMode = 'background', ?int $chunkSize = null): ObjectChangeListener {
		$jobList = $this->createMock(IJobList::class);
		$jobList->method('add')->willReturnCallback(
			function (string $job, mixed $argument = null): void {
				$this->added[] = [$job, $argument];
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $key === 'listenerDeferral' ? $deferralMode : $default
		);
		$appConfig->method('getValueInt')->willReturnCallback(
			static fn (string $app, string $key, int $default = 0): int => $key === ObjectChangeListener::CHUNK_SIZE_KEY && $chunkSize !== null ? $chunkSize : $default
		);

		$this->deferral = new ListenerDeferralService(
			userSession: $this->createMock(IUserSession::class),
			organisation: $this->createMock(OrganisationServiceInterface::class),
			jobList: $jobList,
			appConfig: $appConfig,
			logger: new NullLogger(),
			argumentLimit: ListenerDeferralService::ARGUMENT_LENGTH_NC32,
		);

		$settings = $this->createMock(AppSettingsInterface::class);
		$settings->method('getFileSettingsOnly')->willReturn(['extractionMode' => 'background']);

		return new ObjectChangeListener(
			$this->createMock(TextExtractionServiceInterface::class),
			$settings,
			$jobList,
			new NullLogger(),
			$this->deferral,
			$appConfig,
		);
	}//end listener()

	/**
	 * Fire one created event per id, then end the "request".
	 *
	 * @param ObjectChangeListener $listener The listener.
	 * @param array<int, int>      $ids      The object ids, in order.
	 *
	 * @return void
	 */
	private function save(ObjectChangeListener $listener, array $ids): void {
		foreach ($ids as $id) {
			$object = new ObjectEntity();
			$object->setId($id);
			$object->setUuid('uuid-' . $id);
			$listener->handle(new ObjectCreatedEvent($object));
		}

		$this->deferral->flushAll();
	}//end save()

	/**
	 * The ids of every chunk job, one list per job.
	 *
	 * @return array<int, array<int, int>>
	 */
	private function chunks(): array {
		$chunks = [];
		foreach ($this->added as [$job, $argument]) {
			if ($job === JobHandles::OBJECT_TEXT_EXTRACTION_CHUNK) {
				$chunks[] = array_map(static fn (array $entry): int => $entry['id'], $argument['entries']);
			}
		}

		return $chunks;
	}//end chunks()

	/**
	 * The jobs of a given class.
	 *
	 * @param string $class The job class.
	 *
	 * @return int
	 */
	private function jobsOf(string $class): int {
		return count(array_filter($this->added, static fn (array $add): bool => $add[0] === $class));
	}//end jobsOf()

	/**
	 * 250 saved objects with the default chunk size: 3 chunk jobs, no per-object job.
	 *
	 * @return void
	 */
	public function testObjectsOfOneProcessAreQueuedInChunks(): void {
		$this->save(listener: $this->listener(), ids: range(1, 250));

		$this->assertSame(0, $this->jobsOf(JobHandles::OBJECT_TEXT_EXTRACTION), 'no job per object');
		$this->assertSame([range(1, 100), range(101, 200), range(201, 250)], $this->chunks());
	}//end testObjectsOfOneProcessAreQueuedInChunks()

	/**
	 * The chunk size comes from textExtractionChunkSize.
	 *
	 * @return void
	 */
	public function testTheChunkSizeIsTheAppConfig(): void {
		$this->save(listener: $this->listener(chunkSize: 25), ids: range(1, 60));

		$this->assertSame([range(1, 25), range(26, 50), range(51, 60)], $this->chunks());
	}//end testTheChunkSizeIsTheAppConfig()

	/**
	 * A chunk size below 1 falls back to the default.
	 *
	 * @return void
	 */
	public function testAnInvalidChunkSizeFallsBackToTheDefault(): void {
		$this->save(listener: $this->listener(chunkSize: 0), ids: range(1, 150));

		$this->assertSame([range(1, 100), range(101, 150)], $this->chunks());
	}//end testAnInvalidChunkSizeFallsBackToTheDefault()

	/**
	 * Every chunk of 100 fits the 4,000-character job argument limit of Nextcloud 32 and 33.
	 *
	 * @return void
	 */
	public function testAChunkOfOneHundredFitsTheSmallestJobArgumentLimit(): void {
		$this->save(listener: $this->listener(), ids: range(1000000, 1000099));

		$this->assertCount(1, $this->chunks(), 'one job, not split by the size limit');
		$argument = $this->added[0][1];
		$this->assertLessThanOrEqual(ListenerDeferralService::ARGUMENT_LENGTH_NC32, strlen((string)json_encode($argument)));
	}//end testAChunkOfOneHundredFitsTheSmallestJobArgumentLimit()

	/**
	 * An object created and then updated in one process is extracted once.
	 *
	 * @return void
	 */
	public function testAnObjectSavedTwiceInOneProcessIsQueuedOnce(): void {
		$listener = $this->listener();
		$object = new ObjectEntity();
		$object->setId(7);
		$object->setUuid('uuid-7');
		$listener->handle(new ObjectCreatedEvent($object));
		$listener->handle(new ObjectUpdatedEvent($object, $object));
		$this->deferral->flushAll();

		$this->assertSame([[7]], $this->chunks());
	}//end testAnObjectSavedTwiceInOneProcessIsQueuedOnce()

	/**
	 * A single save outside any bulk context still reaches the job list, as a chunk of one.
	 *
	 * @return void
	 */
	public function testASingleSaveIsAChunkOfOne(): void {
		$this->save(listener: $this->listener(), ids: [42]);

		$this->assertSame([[42]], $this->chunks());
	}//end testASingleSaveIsAChunkOfOne()

	/**
	 * Kill switch listenerDeferral=inline: one ObjectTextExtractionJob per object, as before.
	 *
	 * @return void
	 */
	public function testTheKillSwitchQueuesOneJobPerObject(): void {
		$this->save(listener: $this->listener(deferralMode: 'inline'), ids: range(1, 5));

		$this->assertSame([], $this->chunks());
		$this->assertSame(5, $this->jobsOf(JobHandles::OBJECT_TEXT_EXTRACTION));
		$this->assertSame(['object_id' => 3], $this->added[2][1]);
	}//end testTheKillSwitchQueuesOneJobPerObject()
}//end class
