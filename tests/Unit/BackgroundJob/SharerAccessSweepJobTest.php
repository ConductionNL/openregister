<?php

/**
 * The hourly sweep that ends sharer views whose holder may no longer share.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\SharerAccessSweepJob;
use OCA\OpenRegister\Db\MagicMapper\StatisticsScope;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCA\OpenRegister\Service\File\FolderManagementHandler;
use OCA\OpenRegister\Service\Rbac\SharerFolderAccess;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\IUser;
use OCP\Share\IShare;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

#[CoversClass(SharerAccessSweepJob::class)]
#[UsesClass(ObjectEntity::class)]
#[UsesClass(StatisticsScope::class)]
#[UsesClass(ListQuery::class)]
final class SharerAccessSweepJobTest extends TestCase {
	private SharerFolderAccess&MockObject $sharerAccess;
	private FolderManagementHandler&MockObject $folders;
	private MagicMapperInterface&MockObject $objects;
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		$this->sharerAccess = $this->createMock(SharerFolderAccess::class);
		$this->folders = $this->createMock(FolderManagementHandler::class);
		$this->objects = $this->createMock(MagicMapperInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	public function testEachViewIsCheckedAgainstTheObjectItNames(): void {
		$this->ownedBy('openregister');
		$first = $this->view('object-1');
		$second = $this->view('object-2');
		$objectOne = new ObjectEntity();
		$objectTwo = new ObjectEntity();
		$this->sharerAccess->expects(self::once())->method('allViews')->with('openregister')->willReturn([$first, $second]);
		$this->objects->method('find')->willReturnCallback(
			static fn (string|int $identifier, mixed $register = null, mixed $schema = null, bool $includeDeleted = false, bool $_rbac = true, bool $_multitenancy = true): ObjectEntity => match ([$identifier, $_rbac, $_multitenancy]) {
				// The sweep reads past RBAC and tenancy: it runs as no user.
				['object-1', false, false] => $objectOne,
				['object-2', false, false] => $objectTwo,
			}
		);
		$calls = [];
		$this->sharerAccess->method('endStaleViews')->willReturnCallback(
			static function (?ObjectEntity $object, array $views) use (&$calls, $objectOne): int {
				$calls[] = [$object, $views];

				return $object === $objectOne ? 1 : 0;
			}
		);

		self::assertSame(1, $this->job()->sweep());
		self::assertSame([[$objectOne, [$first]], [$objectTwo, [$second]]], $calls);
	}

	public function testAViewOnAnObjectThatIsGoneIsEndedWithoutAnObject(): void {
		$this->ownedBy('openregister');
		$gone = $this->view('deleted-object');
		$this->sharerAccess->method('allViews')->willReturn([$gone]);
		$this->objects->method('find')->willThrowException(new DoesNotExistException('gone'));
		$this->sharerAccess->expects(self::once())->method('endStaleViews')->with(null, [$gone])->willReturn(1);

		self::assertSame(1, $this->job()->sweep());
	}

	public function testAViewWhoseNodeIsMissingIsEndedWithoutALookup(): void {
		$this->ownedBy('openregister');
		$view = $this->createMock(IShare::class);
		$view->method('getNode')->willThrowException(new NotFoundException('no node'));
		$this->sharerAccess->method('allViews')->willReturn([$view]);
		$this->objects->expects(self::never())->method('find');
		$this->sharerAccess->expects(self::once())->method('endStaleViews')->with(null, [$view])->willReturn(1);

		self::assertSame(1, $this->job()->sweep());
	}

	public function testAFailedLookupLeavesTheViewForTheNextRun(): void {
		$this->ownedBy('openregister');
		$this->sharerAccess->method('allViews')->willReturn([$this->view('object-1')]);
		$this->objects->method('find')->willThrowException(new RuntimeException('database away'));
		$this->sharerAccess->expects(self::never())->method('endStaleViews');

		self::assertSame(0, $this->job()->sweep());
	}

	public function testAFolderWithoutAnOwnerEndsNothing(): void {
		$folder = $this->createMock(Folder::class);
		$folder->method('getOwner')->willReturn(null);
		$this->folders->method('getOpenRegisterUserFolder')->willReturn($folder);
		$this->sharerAccess->expects(self::never())->method('allViews');

		self::assertSame(0, $this->job()->sweep());
	}

	public function testARunLogsHowManyViewsItEnded(): void {
		$this->ownedBy('openregister');
		$this->sharerAccess->method('allViews')->willReturn([$this->view('object-1')]);
		$this->objects->method('find')->willReturn(new ObjectEntity());
		$this->sharerAccess->method('endStaleViews')->willReturn(1);
		$this->logger->expects(self::once())->method('info')->with('[SharerAccessSweepJob] Ended {count} sharer views', ['count' => 1]);
		$this->logger->expects(self::never())->method('warning');

		$this->runJob($this->job());
	}

	public function testARunThatFailsIsLoggedAndDoesNotThrow(): void {
		$error = new RuntimeException('no folder');
		$this->folders->method('getOpenRegisterUserFolder')->willThrowException($error);
		$this->logger->expects(self::never())->method('info');
		$this->logger->expects(self::once())->method('warning')
			->with('[SharerAccessSweepJob] Sweep failed: {error}', ['error' => 'no folder', 'exception' => $error]);

		$this->runJob($this->job());
	}

	public function testTheJobRunsHourly(): void {
		self::assertSame(3600, $this->job()->getInterval());
	}

	private function ownedBy(string $uid): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$folder = $this->createMock(Folder::class);
		$folder->method('getOwner')->willReturn($user);
		$this->folders->method('getOpenRegisterUserFolder')->willReturn($folder);
	}

	private function view(string $nodeName): IShare&MockObject {
		$node = $this->createMock(Node::class);
		$node->method('getName')->willReturn($nodeName);
		$view = $this->createMock(IShare::class);
		$view->method('getNode')->willReturn($node);

		return $view;
	}

	private function job(): SharerAccessSweepJob {
		return new SharerAccessSweepJob($this->createMock(ITimeFactory::class), $this->sharerAccess, $this->folders, $this->objects, $this->logger);
	}

	private function runJob(SharerAccessSweepJob $job): void {
		(new ReflectionMethod($job, 'run'))->invoke($job, null);
	}
}
