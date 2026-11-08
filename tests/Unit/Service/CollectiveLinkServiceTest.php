<?php

/**
 * Unit tests for {@see \OCA\OpenRegister\Service\CollectiveLinkService}.
 *
 * Exercises the Tier-2 service contract (link / createAndLink / unlink /
 * list + available picker + collectives cascade) against a mocked
 * CollectiveLinkMapper. Tests that touch NC Collectives'
 * `CollectiveService` / `PageService` use the "Collectives unavailable"
 * path because those classes are resolved from the container and aren't
 * injectable into this unit test scope without the `collectives` app on
 * the classpath. Real round-trips are gated by
 * `@group requires-app-collectives`.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/integration-collectives/tasks.md
 */

declare(strict_types=1);

namespace Unit\Service;

use Exception;
use OCA\OpenRegister\Db\CollectiveLink;
use OCA\OpenRegister\Db\CollectiveLinkMapper;
use OCA\OpenRegister\Service\CollectiveLinkService;
use OCA\OpenRegister\Service\LinkTarget;
use OCA\OpenRegister\Service\LinkServiceRuntime;
use OCP\App\IAppManager;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

foreach (['CollectiveService', 'PageService'] as $collectivesClass) {
	if (class_exists('OCA\\Collectives\\Service\\' . $collectivesClass) === false) {
		eval('namespace OCA\\Collectives\\Service; class ' . $collectivesClass . ' {}');
	}
}

/**
 * CollectiveLinkServiceTest.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 *
 * @group requires-app-collectives
 */
class CollectiveLinkServiceTest extends TestCase {

	private CollectiveLinkMapper&MockObject $mapper;

	private ContainerInterface&MockObject $container;

	private IAppManager&MockObject $appManager;

	private IUserSession&MockObject $userSession;

	private IURLGenerator&MockObject $urlGenerator;

	private LoggerInterface&MockObject $logger;

	private CollectiveLinkService $service;

	protected function setUp(): void {
		$this->mapper = $this->getMockBuilder(CollectiveLinkMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(
				[
					'findByObjectUuid',
					'findByObjectAndPage',
					'deleteByObjectAndPage',
					'insert',
					'update',
				]
			)
			->getMock();

		$this->container = $this->createMock(ContainerInterface::class);
		$this->appManager = $this->createMock(IAppManager::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->urlGenerator->method('linkToRoute')->willReturn('/index.php/apps/collectives/');

		$this->service = new CollectiveLinkService(
			$this->mapper,
			$this->container,
			new LinkServiceRuntime($this->appManager, $this->userSession, $this->logger),
			$this->urlGenerator
		);
	}//end setUp()

	private function setupUser(string $uid = 'alice'): IUser&MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
		return $user;
	}//end setupUser()

	private function target(): LinkTarget {
		return (new LinkTarget(objectUuid: 'abc-123', registerId: 1))->inSchema(schemaId: 2);
	}//end target()

	public function testIsCollectivesAvailableTrue(): void {
		$this->appManager->method('isEnabledForUser')->with('collectives')->willReturn(true);
		$this->assertTrue($this->service->isCollectivesAvailable());
	}//end testIsCollectivesAvailableTrue()

	public function testIsCollectivesAvailableFalse(): void {
		$this->appManager->method('isEnabledForUser')->with('collectives')->willReturn(false);
		$this->assertFalse($this->service->isCollectivesAvailable());
	}//end testIsCollectivesAvailableFalse()

	public function testLinkPageThrowsWhenNoUser(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$this->expectException(Exception::class);
		$this->expectExceptionCode(401);

		$this->service->linkPage($this->target(), 99);
	}//end testLinkPageThrowsWhenNoUser()

	public function testLinkPageThrowsWhenCollectivesUnavailable(): void {
		$this->setupUser();
		$this->appManager->method('isEnabledForUser')->with('collectives')->willReturn(false);

		$this->expectException(Exception::class);
		$this->expectExceptionCode(503);

		$this->service->linkPage($this->target(), 99);
	}//end testLinkPageThrowsWhenCollectivesUnavailable()

	public function testLinkPageThrowsOnDuplicate(): void {
		$this->setupUser();
		$this->appManager->method('isEnabledForUser')->willReturn(true);
		$this->mapper->method('findByObjectAndPage')->with('abc-123', 99)->willReturn(new CollectiveLink());

		$this->expectException(Exception::class);
		$this->expectExceptionCode(409);
		$this->expectExceptionMessage('Page already linked to this object');

		$this->service->linkPage($this->target(), 99);
	}//end testLinkPageThrowsOnDuplicate()

	public function testCreateAndLinkPageThrowsWhenNoUser(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$this->expectException(Exception::class);
		$this->expectExceptionCode(401);

		$this->service->createAndLinkPage($this->target(), 5, 'Runbook');
	}//end testCreateAndLinkPageThrowsWhenNoUser()

	public function testCreateAndLinkPageThrowsOnEmptyTitle(): void {
		$this->setupUser();

		$this->expectException(Exception::class);
		$this->expectExceptionCode(400);

		$this->service->createAndLinkPage($this->target(), 5, '   ');
	}//end testCreateAndLinkPageThrowsOnEmptyTitle()

	public function testCreateAndLinkPageThrowsOnMissingCollective(): void {
		$this->setupUser();

		$this->expectException(Exception::class);
		$this->expectExceptionCode(400);

		$this->service->createAndLinkPage($this->target(), 0, 'Runbook');
	}//end testCreateAndLinkPageThrowsOnMissingCollective()

	public function testCreateAndLinkPageThrowsWhenCollectivesUnavailable(): void {
		$this->setupUser();
		$this->appManager->method('isEnabledForUser')->with('collectives')->willReturn(false);

		$this->expectException(Exception::class);
		$this->expectExceptionCode(503);

		$this->service->createAndLinkPage($this->target(), 5, 'Runbook');
	}//end testCreateAndLinkPageThrowsWhenCollectivesUnavailable()

	public function testUnlinkPageThrowsWhenNoUser(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$this->expectException(Exception::class);
		$this->expectExceptionCode(401);

		$this->service->unlinkPage('abc-123', 99);
	}//end testUnlinkPageThrowsWhenNoUser()

	public function testUnlinkPageThrowsWhenLinkMissing(): void {
		$this->setupUser();
		$this->mapper->method('deleteByObjectAndPage')->with('abc-123', 99)->willReturn(0);

		$this->expectException(Exception::class);
		$this->expectExceptionCode(404);

		$this->service->unlinkPage('abc-123', 99);
	}//end testUnlinkPageThrowsWhenLinkMissing()

	public function testUnlinkPageSucceeds(): void {
		$this->setupUser();
		$this->mapper->expects($this->once())
			->method('deleteByObjectAndPage')
			->with('abc-123', 99)
			->willReturn(1);

		$this->service->unlinkPage('abc-123', 99);
	}//end testUnlinkPageSucceeds()

	public function testGetLinkedPagesReturnsRows(): void {
		$this->setupUser();
		$this->appManager->method('isEnabledForUser')->willReturn(false);

		$link = new CollectiveLink();
		$link->setObjectUuid('abc-123');
		$link->setPageId(42);
		$link->setPageTitle('Runbook');
		$link->setCollectiveName('Ops');
		$link->setUrl('/index.php/apps/collectives/?fileId=42');

		$this->mapper->method('findByObjectUuid')->with('abc-123')->willReturn([$link]);

		$rows = $this->service->getLinkedPages('abc-123');

		$this->assertCount(1, $rows);
		$this->assertSame(42, $rows[0]['pageId']);
		$this->assertSame('Runbook', $rows[0]['pageTitle']);
		$this->assertSame('Ops', $rows[0]['collectiveName']);
	}//end testGetLinkedPagesReturnsRows()

	public function testGetLinkedPagesEmpty(): void {
		$this->setupUser();
		$this->appManager->method('isEnabledForUser')->willReturn(false);
		$this->mapper->method('findByObjectUuid')->with('abc-123')->willReturn([]);

		$this->assertSame([], $this->service->getLinkedPages('abc-123'));
	}//end testGetLinkedPagesEmpty()

	public function testGetAvailablePagesEmptyWhenCollectivesUnavailable(): void {
		$this->setupUser();
		$this->appManager->method('isEnabledForUser')->with('collectives')->willReturn(false);

		$this->assertSame([], $this->service->getAvailablePages());
	}//end testGetAvailablePagesEmptyWhenCollectivesUnavailable()

	public function testGetAvailablePagesEmptyWhenNoUser(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->appManager->method('isEnabledForUser')->with('collectives')->willReturn(true);

		$this->assertSame([], $this->service->getAvailablePages());
	}//end testGetAvailablePagesEmptyWhenNoUser()

	public function testGetAvailableCollectivesEmptyWhenCollectivesUnavailable(): void {
		$this->setupUser();
		$this->appManager->method('isEnabledForUser')->with('collectives')->willReturn(false);

		$this->assertSame([], $this->service->getAvailableCollectives());
	}//end testGetAvailableCollectivesEmptyWhenCollectivesUnavailable()

	public function testGetAvailableCollectivesEmptyWhenNoUser(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->appManager->method('isEnabledForUser')->with('collectives')->willReturn(true);

		$this->assertSame([], $this->service->getAvailableCollectives());
	}//end testGetAvailableCollectivesEmptyWhenNoUser()

	/**
	 * Hand out Collectives services: one collective (5, Team) holding page 99.
	 *
	 * @param object $pages The PageService stand-in.
	 *
	 * @return void
	 */
	private function collectives(object $pages): void {
		$collectiveService = new class {
			public function getCollectives(string $uid): array {
				return [new class {
					public function getId(): int {
						return 5;
					}

					public function getName(): string {
						return 'Team';
					}
				},
				];
			}
		};
		$this->appManager->method('isEnabledForUser')->with('collectives')->willReturn(true);
		$this->container->method('get')->willReturnCallback(
			fn (string $class): object => str_ends_with($class, 'PageService') ? $pages : $collectiveService
		);
		$this->mapper->method('insert')->willReturnArgument(0);
	}//end collectives()

	/**
	 * A page as Collectives' PageService returns it.
	 *
	 * @param int $pageId The page id.
	 *
	 * @return object The page.
	 */
	public static function pageFor(int $pageId): object {
		return new class($pageId) {
			public function __construct(private int $pageId) {
			}

			public function getId(): int {
				return $this->pageId;
			}

			public function getTitle(): string {
				return 'Runbook';
			}

			public function getTimestamp(): int {
				return 1700000000;
			}

			public function getEmoji(): string {
				return 'x';
			}

			public function getSlug(): string {
				return 'runbook';
			}
		};
	}//end pageFor()

	public function testLinkPageStampsTheObjectAndCachesThePage(): void {
		$this->setupUser('bob');
		$this->mapper->method('findByObjectAndPage')->with('abc-123', 99)->willReturn(null);
		$this->collectives(new class {
			public function find(int $collectiveId, int $pageId, string $uid): object {
				return CollectiveLinkServiceTest::pageFor($collectiveId === 5 && $uid === 'bob' ? $pageId : 0);
			}
		});

		$link = $this->service->linkPage($this->target(), 99);

		$this->assertSame('abc-123', $link->getObjectUuid());
		$this->assertSame(1, $link->getRegisterId());
		$this->assertSame(2, $link->getSchemaId());
		$this->assertSame('bob', $link->getLinkedBy());
		$this->assertNotNull($link->getLinkedAt());
		$this->assertSame(99, $link->getPageId());
		$this->assertSame(5, $link->getCollectiveId());
		$this->assertSame('Team', $link->getCollectiveName());
		$this->assertSame('Runbook', $link->getPageTitle());
		$this->assertSame('runbook', $link->getSlug());
		$this->assertSame('x', $link->getEmoji());
		$this->assertSame(1700000000, $link->getLastModified()->getTimestamp());
		$this->assertSame('/index.php/apps/collectives/?fileId=99', $link->getUrl());
	}//end testLinkPageStampsTheObjectAndCachesThePage()

	public function testLinkPageThrowsWhenThePageIsMissing(): void {
		$this->setupUser();
		$this->mapper->method('findByObjectAndPage')->willReturn(null);
		$this->collectives(new class {
			public function find(int $collectiveId, int $pageId, string $uid): object {
				throw new RuntimeException('not found');
			}
		});

		$this->expectException(Exception::class);
		$this->expectExceptionCode(404);

		$this->service->linkPage($this->target(), 99);
	}//end testLinkPageThrowsWhenThePageIsMissing()

	public function testCreateAndLinkPageStampsTheObjectAndTheNewPage(): void {
		$this->setupUser('bob');
		$pages = new class {
			public array $created = [];

			public function create(int $collectiveId, int $parentId, string $title, ?int $templateId, string $uid): object {
				$this->created = [$collectiveId, $parentId, $title, $templateId, $uid];
				return CollectiveLinkServiceTest::pageFor(77);
			}

			public function find(int $collectiveId, int $pageId, string $uid): object {
				return CollectiveLinkServiceTest::pageFor($pageId);
			}
		};
		$this->collectives($pages);

		$link = $this->service->createAndLinkPage($this->target(), 5, '  Runbook  ');

		$this->assertSame([5, 0, 'Runbook', null, 'bob'], $pages->created);
		$this->assertSame('abc-123', $link->getObjectUuid());
		$this->assertSame(1, $link->getRegisterId());
		$this->assertSame(2, $link->getSchemaId());
		$this->assertSame('bob', $link->getLinkedBy());
		$this->assertSame(77, $link->getPageId());
		$this->assertSame('Team', $link->getCollectiveName());
		$this->assertSame('runbook', $link->getSlug());
	}//end testCreateAndLinkPageStampsTheObjectAndTheNewPage()

	public function testCreateAndLinkPageFallsBackToTheTitleWhenTheReadFails(): void {
		$this->setupUser();
		$this->collectives(new class {
			public function create(int $collectiveId, int $parentId, string $title, ?int $templateId, string $uid): object {
				return CollectiveLinkServiceTest::pageFor(77);
			}

			public function find(int $collectiveId, int $pageId, string $uid): object {
				throw new RuntimeException('not found');
			}
		});

		$link = $this->service->createAndLinkPage($this->target(), 9, 'Runbook');

		$this->assertSame(77, $link->getPageId());
		$this->assertSame(9, $link->getCollectiveId());
		$this->assertSame('', $link->getCollectiveName());
		$this->assertSame('Runbook', $link->getPageTitle());
		$this->assertNull($link->getSlug());
		$this->assertNull($link->getEmoji());
		$this->assertNotNull($link->getLastModified());
		$this->assertSame('/index.php/apps/collectives/?fileId=77', $link->getUrl());
		$this->assertSame(2, $link->getSchemaId());
	}//end testCreateAndLinkPageFallsBackToTheTitleWhenTheReadFails()

	public function testCreateAndLinkPageThrowsWhenTheCreateFails(): void {
		$this->setupUser();
		$this->collectives(new class {
			public function create(int $collectiveId, int $parentId, string $title, ?int $templateId, string $uid): object {
				throw new RuntimeException('down');
			}
		});

		$this->expectException(Exception::class);
		$this->expectExceptionCode(500);

		$this->service->createAndLinkPage($this->target(), 5, 'Runbook');
	}//end testCreateAndLinkPageThrowsWhenTheCreateFails()
}//end class
