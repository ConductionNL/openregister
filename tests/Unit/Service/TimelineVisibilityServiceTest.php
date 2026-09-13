<?php

/**
 * Unit tests for TimelineVisibilityService — the internal/public decision every
 * timeline entry passes through.
 *
 * Covers the default at read time, the vocabulary, the `update` guard, the
 * enforced filter a caller cannot step around, and the audit entry a move
 * writes on the object.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/timeline-entry-visibility/specs/object-interactions/spec.md
 */

declare(strict_types=1);

namespace Unit\Service;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\TimelineVisibilityService;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * TimelineVisibilityServiceTest.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class TimelineVisibilityServiceTest extends TestCase {
	private SchemaMapper&MockObject $schemaMapper;
	private PermissionHandler&MockObject $permissionHandler;
	private AuditTrailMapper&MockObject $auditTrailMapper;
	private IUserSession&MockObject $userSession;
	private LoggerInterface&MockObject $logger;
	private TimelineVisibilityService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->permissionHandler = $this->createMock(PermissionHandler::class);
		$this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->service = new TimelineVisibilityService(
			$this->schemaMapper,
			$this->permissionHandler,
			$this->auditTrailMapper,
			$this->userSession,
			$this->logger
		);
	}

	private function object(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('obj-uuid');
		$object->setSchema('3');
		return $object;
	}

	private function signIn(string $uid = 'handler'): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}

	private function allowUpdate(bool $allowed): void {
		$this->schemaMapper->method('find')->willReturn($this->createMock(Schema::class));
		$this->permissionHandler->method('hasPermission')->willReturn($allowed);
	}

	public function testAbsentValueIsInternal(): void {
		$this->assertSame(TimelineVisibilityService::INTERNAL, $this->service->normalise(null));
	}

	public function testAnythingOutsideTheVocabularyIsInternal(): void {
		$this->assertSame(TimelineVisibilityService::INTERNAL, $this->service->normalise('everyone'));
		$this->assertSame(TimelineVisibilityService::INTERNAL, $this->service->normalise(''));
	}

	public function testPublicIsRecognisedWhateverItsCasing(): void {
		$this->assertSame(TimelineVisibilityService::PUBLIC_ENTRY, $this->service->normalise('Public'));
		$this->assertSame(TimelineVisibilityService::PUBLIC_ENTRY, $this->service->normalise(' public '));
	}

	public function testOnlyTheTwoValuesAreKnown(): void {
		$this->assertTrue($this->service->isKnownValue('internal'));
		$this->assertTrue($this->service->isKnownValue('public'));
		$this->assertFalse($this->service->isKnownValue('intern'));
		$this->assertFalse($this->service->isKnownValue(null));
	}

	public function testMayManageFollowsUpdateOnTheObject(): void {
		$this->signIn();
		$this->allowUpdate(true);

		$this->assertTrue($this->service->mayManage($this->object()));
	}

	public function testMayManageIsFalseForAReader(): void {
		$this->signIn('reader');
		$this->allowUpdate(false);

		$this->assertFalse($this->service->mayManage($this->object()));
	}

	public function testMayManageIsFalseWithoutAnObject(): void {
		$this->assertFalse($this->service->mayManage(null));
	}

	public function testAnUnresolvableSchemaRefuses(): void {
		$this->signIn();
		$this->schemaMapper->method('find')->willThrowException(new \RuntimeException('gone'));
		$this->permissionHandler->expects($this->never())->method('hasPermission');

		$this->assertFalse($this->service->mayManage($this->object()));
	}

	public function testAReaderIsServedThePublicViewWithoutAskingForIt(): void {
		$this->signIn('reader');
		$this->allowUpdate(false);

		$this->assertSame(
			TimelineVisibilityService::PUBLIC_ENTRY,
			$this->service->effectiveFilter($this->object(), null)
		);
	}

	public function testAReaderCannotAskForTheInternalView(): void {
		$this->signIn('reader');
		$this->allowUpdate(false);

		$this->assertSame(
			TimelineVisibilityService::PUBLIC_ENTRY,
			$this->service->effectiveFilter($this->object(), 'internal')
		);
	}

	public function testAHandlerWithoutAFilterSeesEverything(): void {
		$this->signIn();
		$this->allowUpdate(true);

		$this->assertNull($this->service->effectiveFilter($this->object(), null));
	}

	public function testAHandlerGetsTheFilterItAskedFor(): void {
		$this->signIn();
		$this->allowUpdate(true);

		$this->assertSame(
			TimelineVisibilityService::PUBLIC_ENTRY,
			$this->service->effectiveFilter($this->object(), 'public')
		);
	}

	public function testANonsenseFilterIsNoFilterForAHandler(): void {
		$this->signIn();
		$this->allowUpdate(true);

		$this->assertNull($this->service->effectiveFilter($this->object(), 'everyone'));
	}

	public function testFilterRowsKeepsOnlyTheAskedForSide(): void {
		$rows = [
			['id' => 1, 'visibility' => 'internal'],
			['id' => 2, 'visibility' => 'public'],
			['id' => 3],
		];

		$public = $this->service->filterRows($rows, 'public');
		$this->assertCount(1, $public);
		$this->assertSame(2, $public[0]['id']);

		$internal = $this->service->filterRows($rows, 'internal');
		$this->assertCount(2, $internal);
		$this->assertSame([1, 3], array_column($internal, 'id'));
	}

	public function testFilterRowsWithoutAFilterReturnsEverything(): void {
		$rows = [['id' => 1], ['id' => 2, 'visibility' => 'public']];

		$this->assertSame($rows, $this->service->filterRows($rows, null));
	}

	public function testOnlyTheTimelineLeavesAreFiltered(): void {
		$this->assertTrue($this->service->isTimelineIntegration('notes'));
		$this->assertTrue($this->service->isTimelineIntegration('activity'));
		$this->assertFalse($this->service->isTimelineIntegration('files'));
		$this->assertFalse($this->service->isTimelineIntegration('deck'));
	}

	public function testMakingANotePublicIsAudited(): void {
		$object = $this->object();
		$this->auditTrailMapper->expects($this->once())
			->method('createAuditTrailEntry')
			->with(
				$object,
				TimelineVisibilityService::AUDIT_ACTION,
				['noteId' => 42, 'from' => 'internal', 'to' => 'public']
			);

		$this->assertTrue($this->service->auditVisibilityChange($object, 42, 'internal', 'public'));
	}

	public function testAFlagThatDidNotMoveWritesNothing(): void {
		$this->auditTrailMapper->expects($this->never())->method('createAuditTrailEntry');

		$this->assertFalse(
			$this->service->auditVisibilityChange($this->object(), 42, 'internal', 'internal')
		);
	}

	public function testALostAuditEntryIsLoggedRatherThanThrown(): void {
		$this->auditTrailMapper->method('createAuditTrailEntry')
			->willThrowException(new \RuntimeException('trail unavailable'));
		$this->logger->expects($this->once())->method('error');

		$this->assertFalse(
			$this->service->auditVisibilityChange($this->object(), 42, 'internal', 'public')
		);
	}
}
