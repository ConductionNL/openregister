<?php

/**
 * Tests for the AppHost GenericStoreController.
 *
 * The controller owns the AUTH POSTURE for every adopting app, which is the
 * thing a leaf app must not be able to drift through its manifest: search is
 * login-required, install is admin-only. Both are asserted here with negative
 * controls, because an in-body guard that is never exercised is the same as no
 * guard at all.
 *
 * It also owns the failure shapes. A registry that errors must read as
 * `store_unreachable`, never as "the registry said there is nothing", and a
 * registry's internals must never reach the browser.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\AppHost
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppHost;

use OCA\OpenRegister\AppHost\Controller\GenericStoreController;
use OCA\OpenRegister\AppHost\Observability\ManifestLoader;
use OCA\OpenRegister\AppHost\Service\GenericStoreService;
use OCA\OpenRegister\AppHost\Store\GenericStoreInstaller;
use OCA\OpenRegister\AppHost\Store\StoreManifest;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\AppHost\Controller\GenericStoreController
 *
 * The controller reads the calling app's declared block, so every test here
 * executes StoreManifest as a collaborator. `beStrictAboutCoverageMetadata`
 * marks that RISKY unless the relationship is declared, and the suite's
 * coverage guard counts risky tests: three of these were risky on the first
 * CI run for exactly this reason. `@uses` rather than a second `@covers`,
 * because StoreManifest is not what these tests assert about — it has its own
 * cases in GenericStoreInstallerTest.
 *
 * @uses \OCA\OpenRegister\AppHost\Store\StoreManifest
 */
class GenericStoreControllerTest extends TestCase {
	/** @var ManifestLoader&MockObject */
	private $manifestLoader;

	/** @var GenericStoreService&MockObject */
	private $storeService;

	/** @var GenericStoreInstaller&MockObject */
	private $installer;

	/** @var IUserSession&MockObject */
	private $userSession;

	/** @var IGroupManager&MockObject */
	private $groupManager;

	/** @var IRequest&MockObject */
	private $request;

	/**
	 * Build the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		// onlyMethods throughout: addMethods would INVENT a method the class
		// does not have, so a rename on the engine side would leave this suite
		// green over an endpoint that fatals at dispatch.
		$this->manifestLoader = $this->getMockBuilder(ManifestLoader::class)
			->disableOriginalConstructor()->onlyMethods(['loadStore'])->getMock();
		$this->storeService = $this->getMockBuilder(GenericStoreService::class)
			->disableOriginalConstructor()->onlyMethods(['search', 'resolve'])->getMock();
		$this->installer = $this->getMockBuilder(GenericStoreInstaller::class)
			->disableOriginalConstructor()->onlyMethods(['install'])->getMock();
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->request = $this->createMock(IRequest::class);
	}//end setUp()

	/**
	 * Build the controller for a given calling app.
	 *
	 * @param string $appId The calling leaf app id.
	 *
	 * @return GenericStoreController
	 */
	private function controller(string $appId = 'dossiq'): GenericStoreController {
		return new GenericStoreController(
			appName: $appId,
			request: $this->request,
			manifestLoader: $this->manifestLoader,
			storeService: $this->storeService,
			installer: $this->installer,
			userSession: $this->userSession,
			groupManager: $this->groupManager,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end controller()

	/**
	 * Sign a user in, optionally as an administrator.
	 *
	 * @param bool $isAdmin Whether the user is an instance admin.
	 *
	 * @return void
	 */
	private function signIn(bool $isAdmin = false): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->willReturn($isAdmin);
	}//end signIn()

	/**
	 * A store manifest for the calling app.
	 *
	 * @return StoreManifest
	 */
	private function enabledStore(): StoreManifest {
		return StoreManifest::fromManifest(
			appId: 'dossiq',
			manifest: ['store' => ['schema' => 'case-type-template', 'installable' => ['caseType']]]
		);
	}//end enabledStore()

	/**
	 * An anonymous caller gets an explicit 401, not a login redirect.
	 *
	 * @return void
	 */
	public function testSearchRefusesAnAnonymousCaller(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->storeService->expects($this->never())->method('search');

		$response = $this->controller()->search();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame('unauthenticated', $response->getData()['outcome']);
	}//end testSearchRefusesAnAnonymousCaller()

	/**
	 * An app that aliased the route but declares no store block reports
	 * not_configured rather than 404 — the page then renders its built-in list
	 * instead of reading as a broken endpoint.
	 *
	 * @return void
	 */
	public function testSearchReportsNotConfiguredWhenTheAppDeclaresNoStore(): void {
		$this->signIn();
		$this->manifestLoader->method('loadStore')
			->willReturn(new StoreManifest(appId: 'keepiq', enabled: false));
		$this->storeService->expects($this->never())->method('search');

		$response = $this->controller(appId: 'keepiq')->search();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(GenericStoreService::OUTCOME_NOT_CONFIGURED, $response->getData()['outcome']);
	}//end testSearchReportsNotConfiguredWhenTheAppDeclaresNoStore()

	/**
	 * The declared block reaches the engine as the descriptor, and the cards
	 * come back unchanged.
	 *
	 * @return void
	 */
	public function testSearchPassesTheDeclaredBlockToTheEngine(): void {
		$this->signIn();
		$this->manifestLoader->method('loadStore')->willReturn($this->enabledStore());
		$this->storeService->expects($this->once())
			->method('search')
			->willReturnCallback(function ($descriptor, $query, $kind) {
				$this->assertSame('dossiq', $descriptor->appId);
				$this->assertSame('case-type-template', $descriptor->schema);
				return ['outcome' => 'ok', 'cards' => [['slug' => 'vth']]];
			});

		$response = $this->controller()->search();

		$this->assertSame('ok', $response->getData()['outcome']);
		$this->assertSame([['slug' => 'vth']], $response->getData()['cards']);
	}//end testSearchPassesTheDeclaredBlockToTheEngine()

	/**
	 * A registry that throws reads as unreachable, and its internals never
	 * reach the browser.
	 *
	 * @return void
	 */
	public function testSearchTranslatesAThrownRegistryIntoAGenericOutcome(): void {
		$this->signIn();
		$this->manifestLoader->method('loadStore')->willReturn($this->enabledStore());
		$this->storeService->method('search')
			->willThrowException(new RuntimeException('connect to 10.0.0.5 refused'));

		$response = $this->controller()->search();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(GenericStoreService::OUTCOME_UNREACHABLE, $response->getData()['outcome']);
		$this->assertStringNotContainsString('10.0.0.5', json_encode($response->getData()));
	}//end testSearchTranslatesAThrownRegistryIntoAGenericOutcome()

	/**
	 * 🔴 INSTALL IS ADMIN-ONLY. A signed-in non-admin is refused, and nothing
	 * is resolved or written.
	 *
	 * @return void
	 */
	public function testInstallRefusesANonAdministrator(): void {
		$this->signIn(isAdmin: false);
		$this->storeService->expects($this->never())->method('resolve');
		$this->installer->expects($this->never())->method('install');

		$response = $this->controller()->install(slug: 'vth');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testInstallRefusesANonAdministrator()

	/**
	 * And an anonymous caller, on the same guard.
	 *
	 * @return void
	 */
	public function testInstallRefusesAnAnonymousCaller(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->installer->expects($this->never())->method('install');

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller()->install(slug: 'vth')->getStatus());
	}//end testInstallRefusesAnAnonymousCaller()

	/**
	 * A malformed slug is rejected before it reaches the registry URL.
	 *
	 * @return void
	 */
	public function testInstallRejectsAMalformedSlug(): void {
		$this->signIn(isAdmin: true);
		$this->storeService->expects($this->never())->method('resolve');

		$response = $this->controller()->install(slug: '../../etc/passwd');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testInstallRejectsAMalformedSlug()

	/**
	 * An unresolvable item is a 404, not a partial install.
	 *
	 * @return void
	 */
	public function testInstallReportsAnUnresolvedItemAsNotFound(): void {
		$this->signIn(isAdmin: true);
		$this->manifestLoader->method('loadStore')->willReturn($this->enabledStore());
		$this->storeService->method('resolve')->willReturn(null);
		$this->installer->expects($this->never())->method('install');

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller()->install(slug: 'vth')->getStatus());
	}//end testInstallReportsAnUnresolvedItemAsNotFound()

	/**
	 * An administrator's install reaches the installer with the DECLARED
	 * store, which is what carries the allowlist.
	 *
	 * @return void
	 */
	public function testInstallHandsTheDeclaredStoreToTheInstaller(): void {
		$this->signIn(isAdmin: true);
		$store = $this->enabledStore();
		$this->manifestLoader->method('loadStore')->willReturn($store);
		$this->storeService->method('resolve')->willReturn(['components' => []]);
		$this->installer->expects($this->once())
			->method('install')
			->with($this->identicalTo($store), $this->equalTo(['components' => []]))
			->willReturn(['success' => true, 'components' => []]);

		$response = $this->controller()->install(slug: 'vth');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['success']);
	}//end testInstallHandsTheDeclaredStoreToTheInstaller()
}//end class
