<?php

/**
 * The delete pre-flight reads the object in the caller's own scope.
 *
 * An object outside what the caller may read (another organisation's, or
 * behind a read rule) is a 404 on `can-delete`, as on GET and DELETE: no
 * analysis of what refers to it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\ObjectsController;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Dto\DeletionAnalysis;
use OCA\OpenRegister\Service\ExportService;
use OCA\OpenRegister\Service\ImportService;
use OCA\OpenRegister\Service\Object\DeleteObject;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\WebhookService;
use OCP\App\IAppManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Controller\ObjectsController
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Dto\DeletionAnalysis
 */
class ObjectsControllerCanDeleteScopeTest extends TestCase {

	private ObjectService&MockObject $objectService;
	private IGroupManager&MockObject $groupManager;

	/**
	 * The scope flags of each read, as [rbac, multitenancy].
	 *
	 * @var array<int, array{0: bool, 1: bool}>
	 */
	private array $reads = [];

	/**
	 * How often the deletion analysis ran.
	 */
	private int $analyses = 0;

	private bool $inScope = false;

	protected function setUp(): void {
		parent::setUp();

		$this->objectService = $this->createMock(ObjectService::class);
		$this->groupManager = $this->createMock(IGroupManager::class);

		$stored = new ObjectEntity();
		$stored->setUuid('uuid-b');
		$stored->setOrganisation('org-b');

		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();
		$this->objectService->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, $register = null, $schema = null, bool $_rbac = true, bool $_multitenancy = true) use ($stored): ObjectEntity {
				$this->reads[] = [$_rbac, $_multitenancy];
				if ($this->inScope === true || ($_rbac === false && $_multitenancy === false)) {
					return $stored;
				}

				throw new DoesNotExistException('Object not found');
			}
		);

		$deleteHandler = $this->createMock(DeleteObject::class);
		$deleteHandler->method('canDelete')->willReturnCallback(
			function (): DeletionAnalysis {
				$this->analyses++;
				return new DeletionAnalysis(deletable: false, blockers: [['uuid' => 'referring-object', 'title' => 'Referring title']]);
			}
		);
		$this->objectService->method('getDeleteHandler')->willReturn($deleteHandler);
	}//end setUp()

	/**
	 * A controller for alice, an administrator or not.
	 *
	 * @param bool $isAdmin Whether alice is in the admin group.
	 *
	 * @return ObjectsController The controller.
	 */
	private function controller(bool $isAdmin = false): ObjectsController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$this->groupManager->method('getUserGroupIds')->willReturn($isAdmin === true ? ['admin'] : ['staff']);

		return new ObjectsController(
			'openregister',
			$this->createMock(IRequest::class),
			$this->createMock(IAppConfig::class),
			$this->createMock(IAppManager::class),
			$this->createMock(ContainerInterface::class),
			$this->createMock(RegisterMapper::class),
			$this->createMock(SchemaMapper::class),
			$this->createMock(AuditTrailMapper::class),
			$this->objectService,
			$session,
			$this->groupManager,
			$this->createMock(ExportService::class),
			$this->createMock(ImportService::class),
			$this->createMock(WebhookService::class),
			$this->createMock(LoggerInterface::class)
		);
	}//end controller()

	public function testAnObjectOutsideTheReadScopeIs404WithoutAnAnalysis(): void {
		$result = $this->controller()->canDelete('uuid-b', '1', '2', $this->objectService);

		$this->assertSame(404, $result->getStatus());
		$this->assertSame(0, $this->analyses, 'the references of an object the caller may not read were analysed');
		$this->assertStringNotContainsString('referring-object', (string)json_encode($result->getData()));
	}

	public function testANonAdminReadsWithRbacAndMultitenancy(): void {
		$this->controller()->canDelete('uuid-b', '1', '2', $this->objectService);

		$this->assertSame([[true, true]], $this->reads);
	}

	public function testAnAdministratorStillReadsUnscoped(): void {
		$result = $this->controller(isAdmin: true)->canDelete('uuid-b', '1', '2', $this->objectService);

		$this->assertSame([[false, false]], $this->reads);
		$this->assertSame(200, $result->getStatus());
	}

	public function testAnObjectInsideTheReadScopeIsAnalysed(): void {
		$this->inScope = true;

		$result = $this->controller()->canDelete('uuid-b', '1', '2', $this->objectService);

		$this->assertSame(200, $result->getStatus());
		$this->assertSame([['uuid' => 'referring-object', 'title' => 'Referring title']], $result->getData()['blockers']);
	}
}
