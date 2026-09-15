<?php

/**
 * Unit tests for the run of an approved erasure.
 *
 * Covers the spec delta's "one destruction path, one record" — every destroyed
 * item carries a destruction record naming the request — plus the refusal that
 * makes an approval mean something: a preview approved for one reading of the
 * world does not run against another, and nothing is written when it refuses.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Gdpr\Erasure
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/data-subject-rights-across-the-instance/specs/gdpr-data-subject-rights/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Gdpr\Erasure;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\ErasurePreview;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Deletion\DestroyRightService;
use OCA\OpenRegister\Service\Deletion\DestructionRecorder;
use OCA\OpenRegister\Service\Deletion\DestructionRefusedException;
use OCA\OpenRegister\Service\Deletion\DestructionScopeService;
use OCA\OpenRegister\Service\Deletion\RetentionClockService;
use OCA\OpenRegister\Service\Gdpr\DataSubjectRequestService;
use OCA\OpenRegister\Service\Gdpr\Erasure\ErasureBucket;
use OCA\OpenRegister\Service\Gdpr\Erasure\ErasurePreviewService;
use OCA\OpenRegister\Service\Gdpr\Erasure\ErasurePreviewStore;
use OCA\OpenRegister\Service\Gdpr\Erasure\ErasureRefusedException;
use OCA\OpenRegister\Service\Gdpr\Erasure\ErasureRunner;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ErasureRunnerTest extends TestCase {
	private const SUBJECT = 'jan@example.org';

	/** @var array<int, array<string, mixed>> Every destruction record written. */
	private array $records = [];

	/** @var array<int, string> Every object uuid actually deleted. */
	private array $deleted = [];

	/** @var array<int, string> Every object uuid actually scrubbed. */
	private array $scrubbed = [];

	protected function setUp(): void {
		$this->records = [];
		$this->deleted = [];
		$this->scrubbed = [];
	}//end setUp()

	private function object(string $uuid): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setRegister('1');
		$object->setSchema('2');
		$object->setObject(['email' => self::SUBJECT]);

		return $object;
	}//end object()

	private function record(string $digest = 'abc'): ErasurePreview {
		$record = new ErasurePreview();
		$record->setUuid('preview-1');
		$record->setSubject(self::SUBJECT);
		$record->setEraseMode(DataSubjectRequestService::ERASE_MODE_WHOLE_OBJECT);
		$record->setDigest($digest);
		$record->setRequestId('DSR-2026-14');
		$record->setStatus(ErasurePreview::STATUS_APPROVED);
		$record->setApprovedBy('anja');

		return $record;
	}//end record()

	private function item(string $uuid, string $bucket, ?string $ground = null): array {
		return [
			'uuid' => $uuid,
			'register' => '1',
			'schema' => '2',
			'bucket' => $bucket,
			'ground' => $ground,
			'counts' => [ErasureBucket::OBJECTS => 1],
		];
	}//end item()

	/**
	 * Assemble the runner over staged collaborators.
	 *
	 * @param array<int, array>                    $items       The fresh preview items.
	 * @param array<int, string>                   $uuids       The uuids discovery can load.
	 * @param string                               $digest      The fresh preview's digest.
	 * @param DestructionRefusedException|null     $rightRefusal Per-object destroy-right refusal.
	 */
	private function runner(
		array $items,
		array $uuids,
		string $digest = 'abc',
		?DestructionRefusedException $rightRefusal = null,
	): ErasureRunner {
		$previewService = $this->createMock(ErasurePreviewService::class);
		$previewService->method('preview')->willReturn(['items' => $items, 'digest' => $digest]);

		$store = $this->createMock(ErasurePreviewStore::class);
		$store->method('consume')->willReturnArgument(0);

		$hits = [];
		foreach ($uuids as $uuid) {
			$hits[] = [
				'object' => $this->object($uuid),
				'gdprEntities' => [['type' => 'email', 'value' => self::SUBJECT]],
			];
		}

		$subjects = $this->createMock(DataSubjectRequestService::class);
		$subjects->method('findSubjectObjects')->willReturn($hits);
		$subjects->method('pseudonymiseObject')->willReturnCallback(
			function (ObjectEntity $object): ?array {
				$this->scrubbed[] = (string)$object->getUuid();

				return ['uuid' => $object->getUuid()];
			}
		);

		$objectMapper = $this->createMock(MagicMapper::class);
		$objectMapper->method('delete')->willReturnCallback(
			function (ObjectEntity $object): ObjectEntity {
				$this->deleted[] = (string)$object->getUuid();

				return $object;
			}
		);

		$destroyRight = $this->createMock(DestroyRightService::class);
		$destroyRight->method('refusalFor')->willReturn($rightRefusal);

		$clock = $this->createMock(RetentionClockService::class);
		$clock->method('refusalFor')->willReturn(null);

		$scopeService = $this->createMock(DestructionScopeService::class);
		$scopeService->method('destroy')->willReturn(
			['scope' => ['files'], 'destroyed' => ['files' => 2], 'total' => 2, 'failed' => []]
		);

		$recorder = $this->createMock(DestructionRecorder::class);
		$recorder->method('record')->willReturnCallback(
			function (ObjectEntity $object, array $scope, string $rule, array $context): array {
				$written = array_merge(['objectUuid' => (string)$object->getUuid(), 'rule' => $rule], $context);
				$this->records[] = $written;

				return $written;
			}
		);

		return new ErasureRunner(
			$previewService,
			$store,
			$subjects,
			$objectMapper,
			$this->createMock(SchemaMapper::class),
			$destroyRight,
			$clock,
			$scopeService,
			$recorder,
			new NullLogger()
		);
	}//end runner()

	public function testOneDestructionPathOneRecord(): void {
		$runner = $this->runner(
			items: [
				$this->item('zaak-1', ErasureBucket::ERASABLE),
				$this->item('zaak-2', ErasureBucket::ERASABLE),
			],
			uuids: ['zaak-1', 'zaak-2']
		);

		$outcome = $runner->run($this->record());

		self::assertSame(['zaak-1', 'zaak-2'], $this->deleted);
		self::assertCount(2, $this->records);

		foreach ($this->records as $written) {
			// D-3: the destruction record names the request that caused it, so
			// the destruction log and the data subject request are one history.
			self::assertSame('DSR-2026-14', $written['dataSubjectRequest']);
			self::assertSame('preview-1', $written['erasurePreview']);
			self::assertSame(self::SUBJECT, $written['dataSubject']);
			self::assertSame(ErasureRunner::RULE, $written['rule']);
			self::assertTrue($written['windowWaived']);
		}

		self::assertSame(2, $outcome['destroyedCount']);
		self::assertTrue($outcome['complete']);
	}//end testOneDestructionPathOneRecord()

	public function testAProtectedItemIsWithheldAndNothingIsDestroyed(): void {
		$runner = $this->runner(
			items: [
				$this->item('zaak-1', ErasureBucket::ERASABLE),
				$this->item('zaak-9', ErasureBucket::PROTECTED, 'ARCHIVAL_RETENTION_OBLIGATION'),
			],
			uuids: ['zaak-1', 'zaak-9']
		);

		$outcome = $runner->run($this->record());

		self::assertSame(['zaak-1'], $this->deleted);
		self::assertSame(1, $outcome['withheldCount']);
		self::assertSame('zaak-9', $outcome['withheld'][0]['uuid']);
		// A RUN THAT KEPT DATA BACK IS NOT COMPLETE, so a handler is never told
		// the subject's data is gone when it is not.
		self::assertFalse($outcome['complete']);
	}//end testAProtectedItemIsWithheldAndNothingIsDestroyed()

	public function testAPseudonymisedItemIsScrubbedNotDestroyed(): void {
		$runner = $this->runner(
			items: [$this->item('zaak-gedeeld', ErasureBucket::PSEUDONYMISED, ErasureBucket::GROUND_SHARED_RECORD)],
			uuids: ['zaak-gedeeld']
		);

		$outcome = $runner->run($this->record());

		self::assertSame(['zaak-gedeeld'], $this->scrubbed);
		self::assertSame([], $this->deleted);
		self::assertSame([], $this->records);
		self::assertSame(1, $outcome['pseudonymisedCount']);
	}//end testAPseudonymisedItemIsScrubbedNotDestroyed()

	public function testAPreviewApprovedForAnotherWorldDoesNotRun(): void {
		$runner = $this->runner(
			items: [$this->item('zaak-1', ErasureBucket::ERASABLE)],
			uuids: ['zaak-1'],
			digest: 'moved'
		);

		try {
			$runner->run($this->record('abc'));
			self::fail('A stale preview must refuse.');
		} catch (ErasureRefusedException $e) {
			self::assertSame('erasure-preview-stale', $e->getRule());
			self::assertSame(409, $e->getStatusCode());
			self::assertSame('abc', $e->toResponseBody()['approvedDigest']);
			self::assertSame('moved', $e->toResponseBody()['currentDigest']);
		}

		// NOTHING WAS WRITTEN. The refusal is the whole point, so the absence of
		// a destruction is the assertion, not a side note.
		self::assertSame([], $this->deleted);
		self::assertSame([], $this->records);
		self::assertSame([], $this->scrubbed);
	}//end testAPreviewApprovedForAnotherWorldDoesNotRun()

	public function testAHoldPlacedAfterTheApprovalStillWins(): void {
		$runner = $this->runner(
			items: [$this->item('zaak-1', ErasureBucket::ERASABLE)],
			uuids: ['zaak-1'],
			rightRefusal: new DestructionRefusedException(
				rule: 'destroy-right-missing',
				reason: 'User anja does not hold the destroy right on schema zaak.',
				statusCode: 403
			)
		);

		$outcome = $runner->run($this->record());

		self::assertSame([], $this->deleted);
		self::assertSame([], $this->records);
		self::assertSame(1, $outcome['refusedCount']);
		self::assertSame('destroy-right-missing', $outcome['refused'][0]['rule']);
		self::assertFalse($outcome['complete']);
	}//end testAHoldPlacedAfterTheApprovalStillWins()

	public function testAnItemThatVanishedBetweenPreviewAndRunFails(): void {
		$runner = $this->runner(
			items: [$this->item('zaak-weg', ErasureBucket::ERASABLE)],
			uuids: []
		);

		$outcome = $runner->run($this->record());

		self::assertSame(1, $outcome['failedCount']);
		self::assertSame('zaak-weg', $outcome['failed'][0]['uuid']);
		self::assertSame([], $this->deleted);
	}//end testAnItemThatVanishedBetweenPreviewAndRunFails()
}//end class
