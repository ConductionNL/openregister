<?php

/**
 * The "it's in the trash" message is scoped like the read it stands in for.
 *
 * `show()` reaches `deletedRefusal()` precisely when its RBAC- and tenant-scoped
 * find() answered null — the case show()'s own docblock says must be a bare 404,
 * "so an unauthorized caller cannot distinguish 'exists but forbidden' from
 * 'does not exist'".
 *
 * That fallback then looked up a BARE uuid with `_rbac: false`,
 * `_multitenancy: false` and no register constraint, and answered with the
 * object's existence, its trashed state and its restore deadline. A caller in
 * one organisation could name any uuid from any other and be told all three.
 * The register and schema in the URL were not even used to constrain it.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.
// phpcs:disable Squiz.Commenting.VariableComment.Missing -- typed mock fixtures; the declaration IS the description.

use OCA\OpenRegister\Controller\ObjectsController;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Deletion\DeletionWindowService;
use OCA\OpenRegister\Service\ExportService;
use OCA\OpenRegister\Service\ImportService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\WebhookService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class ObjectsControllerDeletedRefusalScopeTest extends TestCase {

	private MagicMapper&MockObject $magicMapper;

	private ObjectService&MockObject $objectService;

	private ObjectsController $controller;

	protected function setUp(): void {
		parent::setUp();

		$this->magicMapper = $this->createMock(MagicMapper::class);
		$this->objectService = $this->createMock(ObjectService::class);

		// The scoped read answers null — the only way show() reaches the fallback.
		$this->objectService->method('find')->willReturn(null);

		$register = new Register();
		$register->setId(7);
		$register->setSlug('zaken');
		$schema = new Schema();
		$schema->setId(4);
		$schema->setSlug('persoon');

		$this->objectService->method('getRegister')->willReturn(7);
		$this->objectService->method('getSchema')->willReturn(4);
		$this->objectService->method('getCurrentRegisterEntity')->willReturn($register);
		$this->objectService->method('getCurrentSchemaEntity')->willReturn($schema);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($this->magicMapper);

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($this->createMock(IUser::class));
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn(['users']);
		$groupManager->method('isAdmin')->willReturn(false);

		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn([]);

		$this->controller = new ObjectsController(
			'openregister',
			$request,
			$this->createMock(IAppConfig::class),
			$this->createMock(IAppManager::class),
			$container,
			$this->createMock(RegisterMapper::class),
			$this->createMock(SchemaMapper::class),
			$this->createMock(AuditTrailMapper::class),
			$this->objectService,
			$userSession,
			$groupManager,
			$this->createMock(ExportService::class),
			$this->createMock(ImportService::class),
			$this->createMock(WebhookService::class),
			$this->createMock(LoggerInterface::class),
			windowService: $this->createMock(DeletionWindowService::class)
		);
	}

	public function testTheTrashLookupRunsUnderTheCallersOwnRulesAndRegister(): void {
		// THE REGRESSION TEST. These four arguments were `false`, `false` and no
		// register scope, which is how any uuid from any tenant answered here.
		$seen = [];
		$this->magicMapper->expects($this->once())
			->method('findAcrossAllSources')
			->willReturnCallback(
				function ($identifier, $includeDeleted, $rbac, $multitenancy, $registerIdScope) use (&$seen) {
					$seen = [
						'includeDeleted' => $includeDeleted,
						'rbac' => $rbac,
						'multitenancy' => $multitenancy,
						'registerIdScope' => $registerIdScope,
					];

					return [];
				}
			);

		$this->controller->show('some-uuid', 'zaken', 'persoon', $this->objectService);

		$this->assertTrue($seen['includeDeleted'], 'the fallback still has to see deleted rows');
		$this->assertTrue($seen['rbac'], 'RBAC must apply, as it does on the read this stands in for');
		$this->assertTrue($seen['multitenancy'], 'tenancy must apply, so another org cannot be probed');
		$this->assertSame(7, $seen['registerIdScope'], 'the URL register must bound the lookup');
	}

	public function testAnObjectThatDoesNotResolveForThisCallerGetsThePlain404(): void {
		// Nothing came back under the caller's own rules, so the answer is the
		// bare 404 — no existence, no trashed state, no restore deadline.
		$this->magicMapper->method('findAcrossAllSources')->willReturn([]);

		$response = $this->controller->show('some-uuid', 'zaken', 'persoon', $this->objectService);

		$this->assertSame(404, $response->getStatus());
		$this->assertArrayNotHasKey('deleted', $response->getData());
	}
}//end class
