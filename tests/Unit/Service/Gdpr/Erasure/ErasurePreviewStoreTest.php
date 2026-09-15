<?php

/**
 * Unit tests for the recorded erasure preview and its approval.
 *
 * Covers the spec delta's "an unapproved erasure does not run", the second run
 * that refuses on the consumed stamp rather than erasing twice, and the reach
 * rule that keeps one handler's preview — which names a data subject's records
 * — out of another handler's hands.
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

use DateTime;
use OCA\OpenRegister\Db\ErasurePreview;
use OCA\OpenRegister\Db\ErasurePreviewMapper;
use OCA\OpenRegister\Service\Gdpr\Erasure\ErasurePreviewStore;
use OCA\OpenRegister\Service\Gdpr\Erasure\ErasureRefusedException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ErasurePreviewStoreTest extends TestCase {
	private function preview(string $status, string $owner = 'handler', ?DateTime $consumedAt = null): ErasurePreview {
		$preview = new ErasurePreview();
		$preview->setUuid('preview-1');
		$preview->setSubject('jan@example.org');
		$preview->setEraseMode('whole-object');
		$preview->setDigest('abc');
		$preview->setStatus($status);
		$preview->setCreatedBy($owner);
		$preview->setConsumedAt($consumedAt);

		return $preview;
	}//end preview()

	private function session(?string $uid): IUserSession {
		$session = $this->createMock(IUserSession::class);
		if ($uid === null) {
			$session->method('getUser')->willReturn(null);

			return $session;
		}

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session->method('getUser')->willReturn($user);

		return $session;
	}//end session()

	/**
	 * @return array{0: ErasurePreviewStore, 1: MockObject&ErasurePreviewMapper}
	 */
	private function store(?ErasurePreview $row, string $uid = 'handler', bool $admin = false): array {
		$mapper = $this->createMock(ErasurePreviewMapper::class);
		if ($row === null) {
			$mapper->method('findByUuid')->willThrowException(new DoesNotExistException('no such row'));
		} else {
			$mapper->method('findByUuid')->willReturn($row);
		}

		$mapper->method('save')->willReturnArgument(0);
		$mapper->method('createFromArray')->willReturnCallback(
			static function (array $data): ErasurePreview {
				$created = new ErasurePreview();
				$created->hydrate($data);
				$created->setUuid('preview-new');

				return $created;
			}
		);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn($admin);

		return [
			new ErasurePreviewStore($mapper, $this->session($uid), $groups, new NullLogger()),
			$mapper,
		];
	}//end store()

	public function testRecordingKeepsTheAnswerVerbatimAndNamesItsAuthor(): void {
		[$store] = $this->store(null, 'anja');

		$recorded = $store->record(
			['subject' => 'jan@example.org', 'type' => 'email', 'eraseMode' => 'whole-object', 'digest' => 'abc'],
			'DSR-2026-14'
		);

		self::assertSame('jan@example.org', $recorded->getSubject());
		self::assertSame('DSR-2026-14', $recorded->getRequestId());
		self::assertSame('abc', $recorded->getDigest());
		self::assertSame('anja', $recorded->getCreatedBy());
		self::assertSame(ErasurePreview::STATUS_PENDING, $recorded->getStatus());
		// The report is kept whole, because the sentence sent to the data
		// subject is written from it.
		self::assertSame('email', $recorded->getReport()['type']);
	}//end testRecordingKeepsTheAnswerVerbatimAndNamesItsAuthor()

	public function testAnUnapprovedErasureDoesNotRun(): void {
		[$store] = $this->store($this->preview(ErasurePreview::STATUS_PENDING));

		try {
			$store->requireRunnable('preview-1');
			self::fail('A pending preview must refuse.');
		} catch (ErasureRefusedException $e) {
			self::assertSame('erasure-not-approved', $e->getRule());
			self::assertSame(409, $e->getStatusCode());
			self::assertSame('ERASURE_REFUSED', $e->toResponseBody()['error']);
		}
	}//end testAnUnapprovedErasureDoesNotRun()

	public function testAnApprovedPreviewIsRunnableAndNamesItsApprover(): void {
		[$store] = $this->store($this->preview(ErasurePreview::STATUS_PENDING, 'anja'), 'anja');

		$approved = $store->approve('preview-1');

		self::assertSame(ErasurePreview::STATUS_APPROVED, $approved->getStatus());
		self::assertSame('anja', $approved->getApprovedBy());
		self::assertNotNull($approved->getApprovedAt());
		self::assertTrue($approved->isRunnable());
	}//end testAnApprovedPreviewIsRunnableAndNamesItsApprover()

	public function testASpentPreviewRefusesRatherThanErasingTwice(): void {
		[$store] = $this->store(
			$this->preview(ErasurePreview::STATUS_CONSUMED, 'handler', new DateTime('2026-09-15T09:00:00+00:00'))
		);

		try {
			$store->requireRunnable('preview-1');
			self::fail('A consumed preview must refuse.');
		} catch (ErasureRefusedException $e) {
			self::assertSame('erasure-already-run', $e->getRule());
		}
	}//end testASpentPreviewRefusesRatherThanErasingTwice()

	public function testAnApprovedButConsumedRowIsStillNotRunnable(): void {
		// Belt and braces: the status says approved, the stamp says it already
		// ran. The stamp wins, because only a real run writes it.
		$row = $this->preview(ErasurePreview::STATUS_APPROVED, 'handler', new DateTime());
		self::assertFalse($row->isRunnable());
	}//end testAnApprovedButConsumedRowIsStillNotRunnable()

	public function testAnotherHandlersPreviewIsNotReachable(): void {
		[$store] = $this->store($this->preview(ErasurePreview::STATUS_APPROVED, 'anja'), 'bram');

		try {
			$store->load('preview-1');
			self::fail('A stranger must not reach a preview.');
		} catch (ErasureRefusedException $e) {
			// ANSWERED AS UNKNOWN, NOT AS FORBIDDEN. "Forbidden" would confirm
			// that somebody asked about this data subject.
			self::assertSame('erasure-preview-unknown', $e->getRule());
			self::assertSame(404, $e->getStatusCode());
		}
	}//end testAnotherHandlersPreviewIsNotReachable()

	public function testAnAdministratorReachesIt(): void {
		[$store] = $this->store($this->preview(ErasurePreview::STATUS_APPROVED, 'anja'), 'root', true);

		self::assertSame('preview-1', $store->load('preview-1')->getUuid());
	}//end testAnAdministratorReachesIt()

	public function testAnUnknownPreviewRefuses(): void {
		[$store] = $this->store(null);

		$this->expectException(ErasureRefusedException::class);
		$store->load('nope');
	}//end testAnUnknownPreviewRefuses()

	public function testConsumingStampsTheRunAndKeepsWhatItDid(): void {
		[$store] = $this->store($this->preview(ErasurePreview::STATUS_APPROVED));
		$row = $this->preview(ErasurePreview::STATUS_APPROVED);

		$consumed = $store->consume($row, ['destroyed' => [['uuid' => 'zaak-1']], 'complete' => true]);

		self::assertSame(ErasurePreview::STATUS_CONSUMED, $consumed->getStatus());
		self::assertNotNull($consumed->getConsumedAt());
		self::assertTrue($consumed->getOutcome()['complete']);
		self::assertFalse($consumed->isRunnable());
	}//end testConsumingStampsTheRunAndKeepsWhatItDid()
}//end class
