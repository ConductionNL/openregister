<?php

/**
 * ObjectTextExtractionChunkJobTest
 *
 * The deferred half of object text extraction: which object ids reach the
 * extraction service when a chunk job runs, and when none do.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\BackgroundJob
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\ObjectTextExtractionChunkJob;
use OCA\OpenRegister\BackgroundJob\Support\ActorForwardingServices;
use OCA\OpenRegister\Kernel\Contracts\TextExtractionServiceInterface;
use OCA\OpenRegister\Service\Deferral\DeferredListenerContext;
use OCA\OpenRegister\Service\OrganisationServiceInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * A chunk job extracts each object of its chunk once, keeps going past a failure, and does nothing when extraction is off.
 *
 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-object-text-extraction-in-background-mode-is-deferred-in-chunks
 */
class ObjectTextExtractionChunkJobTest extends TestCase {

	/**
	 * The object ids the extraction service was asked for, in order.
	 *
	 * @var array<int, int>
	 */
	private array $extracted = [];

	/**
	 * A job whose extraction service records each id and throws for the ids in $failing.
	 *
	 * @param string          $objectManagement The objectManagement app config (JSON).
	 * @param array<int, int> $failing          Ids for which extraction throws.
	 *
	 * @return ObjectTextExtractionChunkJob
	 */
	private function job(string $objectManagement = '{}', array $failing = []): ObjectTextExtractionChunkJob {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $key === 'objectManagement' ? $objectManagement : $default
		);

		$extractor = $this->createMock(TextExtractionServiceInterface::class);
		$extractor->method('extractObject')->willReturnCallback(
			function (int $objectId, bool $forceReExtract = false) use ($failing): void {
				$this->extracted[] = $objectId;
				if (in_array($objectId, $failing, true) === true) {
					throw new RuntimeException('extraction failed');
				}
			}
		);

		return new ObjectTextExtractionChunkJob(
			time: $this->createMock(ITimeFactory::class),
			actor: new ActorForwardingServices(
				userSession: $this->createMock(IUserSession::class),
				userManager: $this->createMock(IUserManager::class),
				organisation: $this->createMock(OrganisationServiceInterface::class),
				logger: new NullLogger(),
			),
			config: $config,
			textExtractor: $extractor,
		);
	}//end job()

	/**
	 * Run the job with these entries, as cron would (session-less origin).
	 *
	 * @param ObjectTextExtractionChunkJob     $job     The job.
	 * @param array<int, array<string, mixed>> $entries The chunk.
	 *
	 * @return void
	 */
	private function runJob(ObjectTextExtractionChunkJob $job, array $entries): void {
		$argument = (new DeferredListenerContext(userId: null, orgUuid: null, entries: $entries))->toJobArguments();
		$method = (new \ReflectionClass(objectOrClass: $job))->getMethod('run');
		$method->invoke($job, $argument);
	}//end runJob()

	/**
	 * Every object of the chunk is extracted, in order.
	 *
	 * @return void
	 */
	public function testEveryObjectOfTheChunkIsExtracted(): void {
		$this->runJob(job: $this->job(), entries: [['id' => 3], ['id' => 1], ['id' => 2]]);

		$this->assertSame([3, 1, 2], $this->extracted);
	}//end testEveryObjectOfTheChunkIsExtracted()

	/**
	 * A failing object does not stop the rest of the chunk.
	 *
	 * @return void
	 */
	public function testAFailureOnOneObjectDoesNotStopTheChunk(): void {
		$this->runJob(job: $this->job(failing: [2]), entries: [['id' => 1], ['id' => 2], ['id' => 3]]);

		$this->assertSame([1, 2, 3], $this->extracted);
	}//end testAFailureOnOneObjectDoesNotStopTheChunk()

	/**
	 * An entry without a usable id is skipped.
	 *
	 * @return void
	 */
	public function testAnEntryWithoutAnIdIsSkipped(): void {
		$this->runJob(job: $this->job(), entries: [['id' => 0], ['uuid' => 'x'], ['id' => 5]]);

		$this->assertSame([5], $this->extracted);
	}//end testAnEntryWithoutAnIdIsSkipped()

	/**
	 * objectExtractionMode none: the chunk extracts nothing, as ObjectTextExtractionJob does.
	 *
	 * @return void
	 */
	public function testNothingIsExtractedWhenObjectExtractionIsOff(): void {
		$this->runJob(job: $this->job(objectManagement: '{"objectExtractionMode":"none"}'), entries: [['id' => 1], ['id' => 2]]);

		$this->assertSame([], $this->extracted);
	}//end testNothingIsExtractedWhenObjectExtractionIsOff()
}//end class
