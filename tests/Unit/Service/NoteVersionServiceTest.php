<?php

declare(strict_types=1);

namespace Unit\Service;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable Squiz.Commenting.VariableComment.Missing -- PHPUnit fixture properties are named by their type.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\NoteVersion;
use OCA\OpenRegister\Db\NoteVersionMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\NoteVersionService;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Unit tests for NoteVersionService.
 *
 * @package Unit\Service
 */
class NoteVersionServiceTest extends TestCase {
	private NoteVersionMapper&MockObject $mapper;
	private AuditTrailMapper&MockObject $auditTrail;
	private IUserManager&MockObject $userManager;
	private LoggerInterface&MockObject $logger;
	private NoteVersionService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->mapper = $this->createMock(NoteVersionMapper::class);
		$this->auditTrail = $this->createMock(AuditTrailMapper::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->service = new NoteVersionService(
			$this->mapper,
			$this->auditTrail,
			$this->userManager,
			$this->logger
		);
	}

	private function user(string $uid, string $displayName): IUser&MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getDisplayName')->willReturn($displayName);
		return $user;
	}

	private function version(string $message, string $author, string $editedBy): NoteVersion {
		$version = new NoteVersion();
		$version->setCommentId(1);
		$version->setMessage($message);
		$version->setAuthor($author);
		$version->setAuthorType('users');
		$version->setEditedBy($editedBy);
		$version->setEditedAt(new \DateTime('2026-09-16T10:00:00+00:00'));
		return $version;
	}

	public function testRecordStoresThePreviousTextUnderItsAuthor(): void {
		$stored = null;
		$this->mapper->expects($this->once())
			->method('insert')
			->willReturnCallback(
				static function (NoteVersion $version) use (&$stored): NoteVersion {
					$stored = $version;
					return $version;
				}
			);

		$this->service->record(7, 'Applicant called', 'a', 'users', 'b');

		$this->assertInstanceOf(NoteVersion::class, $stored);
		$this->assertSame(7, $stored->getCommentId());
		$this->assertSame('Applicant called', $stored->getMessage());
		$this->assertSame('a', $stored->getAuthor());
		$this->assertSame('users', $stored->getAuthorType());
		$this->assertSame('b', $stored->getEditedBy());
		$this->assertNotNull($stored->getEditedAt());
		// The uuid and created stamp are the service's own, because a mapper
		// override could not narrow its inherited parameter to NoteVersion.
		$this->assertNotNull($stored->getUuid());
		$this->assertNotNull($stored->getCreated());
	}

	public function testVersionsCarryResolvedDisplayNames(): void {
		$this->mapper->method('findByComment')->willReturn(
			[$this->version('Applicant called', 'a', 'b')]
		);
		$this->userManager->method('get')->willReturnMap(
			[
				['a', $this->user('a', 'Anna')],
				['b', $this->user('b', 'Bram')],
			]
		);

		$rows = $this->service->versions(1);

		$this->assertCount(1, $rows);
		$this->assertSame('Applicant called', $rows[0]['message']);
		$this->assertSame('Anna', $rows[0]['authorDisplayName']);
		$this->assertSame('Bram', $rows[0]['editedByDisplayName']);
	}

	public function testAnUnknownActorFallsBackToItsId(): void {
		$this->mapper->method('findByComment')->willReturn(
			[$this->version('Gone', 'departed', 'departed')]
		);
		$this->userManager->method('get')->willReturn(null);

		$rows = $this->service->versions(1);

		$this->assertSame('departed', $rows[0]['authorDisplayName']);
	}

	public function testEveryIdAskedForComesBackInTheSummaries(): void {
		$this->mapper->method('summariesFor')->willReturn(
			[
				1 => ['editedAt' => '2026-09-16T10:00:00+00:00', 'editedBy' => 'a', 'versionCount' => 2],
			]
		);
		$this->userManager->method('get')->willReturn($this->user('a', 'Anna'));

		$summaries = $this->service->summaries([1, 2]);

		$this->assertSame(2, $summaries[1]['versionCount']);
		$this->assertSame('Anna', $summaries[1]['editedByDisplayName']);
		// The note nobody edited is present and says so, rather than being
		// absent and leaving the caller to guess.
		$this->assertSame(0, $summaries[2]['versionCount']);
		$this->assertNull($summaries[2]['editedBy']);
	}

	public function testAskingForNothingQueriesNothing(): void {
		$this->mapper->expects($this->never())->method('summariesFor');

		$this->assertSame([], $this->service->summaries([]));
	}

	public function testAHistoryThatCannotBeReadStillRendersTheNotes(): void {
		$this->mapper->method('summariesFor')->willThrowException(new RuntimeException('db down'));
		$this->logger->expects($this->once())->method('error');

		$summaries = $this->service->summaries([1]);

		$this->assertSame(0, $summaries[1]['versionCount']);
	}

	public function testForgetDropsTheRowsAndReportsHowMany(): void {
		$this->mapper->expects($this->once())
			->method('deleteByComments')
			->with([4, 5])
			->willReturn(3);

		$this->assertSame(3, $this->service->forget([4, 5]));
	}

	public function testTheTrailRecordsTheEditAndNotTheText(): void {
		$object = new ObjectEntity();
		$object->setUuid('obj-uuid');

		$context = null;
		$this->auditTrail->expects($this->once())
			->method('createAuditTrailEntry')
			->willReturnCallback(
				function (ObjectEntity $written, string $action, array $ctx) use (&$context) {
					$this->assertSame(NoteVersionService::AUDIT_ACTION, $action);
					$context = $ctx;
					return $this->createMock(\OCA\OpenRegister\Db\AuditTrail::class);
				}
			);

		$this->assertTrue($this->service->auditEdit($object, 9, 1));

		$this->assertSame(['noteId' => 9, 'versionCount' => 1], $context);
		$this->assertSame('note.edited', NoteVersionService::AUDIT_ACTION);
	}

	public function testALostTrailEntryDoesNotLoseTheEdit(): void {
		$object = new ObjectEntity();
		$object->setUuid('obj-uuid');

		$this->auditTrail->method('createAuditTrailEntry')
			->willThrowException(new RuntimeException('trail unavailable'));
		$this->logger->expects($this->once())->method('error');

		$this->assertFalse($this->service->auditEdit($object, 9, 1));
	}
}
