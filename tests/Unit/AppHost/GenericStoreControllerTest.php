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
use OCA\OpenRegister\AppHost\Store\FederatedStoreCatalog;
use OCA\OpenRegister\AppHost\Store\GenericStoreInstaller;
use OCA\OpenRegister\AppHost\Store\Source\GitHubStoreSource;
use OCA\OpenRegister\AppHost\Store\StoreActionAuthorizer;
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
 * @uses \OCA\OpenRegister\AppHost\Service\StoreDescriptor
 */
class GenericStoreControllerTest extends TestCase {
	/** @var ManifestLoader&MockObject */
	private $manifestLoader;

	/** @var GenericStoreService&MockObject */
	private $storeService;

	/** @var GenericStoreInstaller&MockObject */
	private $installer;

	/** @var FederatedStoreCatalog&MockObject */
	private $catalog;

	/** @var GitHubStoreSource&MockObject */
	private $gitHubSource;

	/** @var StoreActionAuthorizer&MockObject */
	private $actionAuthorizer;

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
		$this->catalog = $this->getMockBuilder(FederatedStoreCatalog::class)
			->disableOriginalConstructor()->onlyMethods(['search', 'resolve', 'install'])->getMock();
		$this->gitHubSource = $this->getMockBuilder(GitHubStoreSource::class)
			->disableOriginalConstructor()->onlyMethods(['search', 'sourceId'])->getMock();
		$this->actionAuthorizer = $this->getMockBuilder(StoreActionAuthorizer::class)
			->disableOriginalConstructor()->onlyMethods(['can'])->getMock();
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
			catalog: $this->catalog,
			gitHubSource: $this->gitHubSource,
			actionAuthorizer: $this->actionAuthorizer,
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
	 * A store manifest declaring shareable configuration types.
	 *
	 * @return StoreManifest
	 */
	private function federatedStore(): StoreManifest {
		return StoreManifest::fromManifest(
			appId: 'decidiq',
			manifest: ['store' => ['types' => ['openregister.configset', 'openregister.flows']]]
		);
	}//end federatedStore()

	/**
	 * A github store is searched against the GitHub source, not the registry.
	 *
	 * 🔴 The regression this guards: a github store has no registry URL, so
	 * falling through to GenericStoreService reports `not_configured` — true
	 * of a registry the app never declared, and useless to whoever has to act
	 * on it. The registry service is told to expect ZERO calls, so a fall
	 * through fails here rather than in a browser.
	 *
	 * @return void
	 */
	public function testAGithubStoreIsSearchedAgainstTheGithubSource(): void {
		$this->signIn();
		$this->manifestLoader->method('loadStore')->willReturn(
			StoreManifest::fromManifest('demo', [
				'store' => ['source' => 'github', 'topics' => ['demo-app']],
			])
		);

		$this->storeService->expects($this->never())->method('search');
		$this->catalog->expects($this->never())->method('search');
		$this->gitHubSource->expects($this->once())
			->method('search')
			->willReturn(['outcome' => 'ok', 'cards' => [['slug' => 'conduction/demo-app']]]);

		$response = $this->controller('demo')->search();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertSame('ok', $data['outcome']);
		$this->assertSame('conduction/demo-app', $data['cards'][0]['slug']);
	}

	/**
	 * An app declaring types is searched against the configuration catalogue.
	 *
	 * @return void
	 */
	public function testSearchUsesTheCatalogueWhenTypesAreDeclared(): void {
		$this->signIn();
		$this->manifestLoader->method('loadStore')->willReturn($this->federatedStore());
		$this->storeService->expects($this->never())->method('search');
		$this->catalog->expects($this->once())
			->method('search')
			->willReturn(['outcome' => 'ok', 'cards' => [['slug' => 'a-set']]]);

		$response = $this->controller(appId: 'decidiq')->search();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('a-set', $response->getData()['cards'][0]['slug']);
	}//end testSearchUsesTheCatalogueWhenTypesAreDeclared()

	/**
	 * With no kinds declared, a federated store offers its type ids as filters.
	 *
	 * Falling through to the shared kind vocabulary would offer chips that
	 * match nothing on the page.
	 *
	 * @return void
	 */
	public function testFederatedKindsFallBackToTheDeclaredTypes(): void {
		$this->signIn();
		$this->manifestLoader->method('loadStore')->willReturn($this->federatedStore());
		$this->catalog->method('search')->willReturn(['outcome' => 'ok', 'cards' => []]);

		$response = $this->controller(appId: 'decidiq')->search();

		$this->assertSame(
			['openregister.configset', 'openregister.flows'],
			$response->getData()['kinds']
		);
	}//end testFederatedKindsFallBackToTheDeclaredTypes()

	/**
	 * A federated install runs through the catalogue, not the object installer.
	 *
	 * @return void
	 */
	public function testInstallUsesTheCatalogueWhenTypesAreDeclared(): void {
		$this->signIn(isAdmin: true);
		$this->manifestLoader->method('loadStore')->willReturn($this->federatedStore());
		$this->installer->expects($this->never())->method('install');
		$this->catalog->method('resolve')->willReturn(
			['typeId' => 'openregister.configset', 'repo' => 'a/b', 'source' => 's', 'bundle' => []]
		);
		$this->catalog->expects($this->once())
			->method('install')
			->willReturn(['success' => true, 'components' => []]);

		$response = $this->controller(appId: 'decidiq')->install(slug: 'a-set');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['success']);
	}//end testInstallUsesTheCatalogueWhenTypesAreDeclared()

	/**
	 * A federated slug that resolves to nothing is a 404, not a blank install.
	 *
	 * @return void
	 */
	public function testFederatedInstallOfAnUnresolvedSlugIs404(): void {
		$this->signIn(isAdmin: true);
		$this->manifestLoader->method('loadStore')->willReturn($this->federatedStore());
		$this->catalog->method('resolve')->willReturn(null);
		$this->catalog->expects($this->never())->method('install');

		$response = $this->controller(appId: 'decidiq')->install(slug: 'a-set');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testFederatedInstallOfAnUnresolvedSlugIs404()

	/**
	 * A catalogue failure reports unreachable rather than raising.
	 *
	 * @return void
	 */
	public function testFederatedSearchFailureIsContained(): void {
		$this->signIn();
		$this->manifestLoader->method('loadStore')->willReturn($this->federatedStore());
		$this->catalog->method('search')->willThrowException(new \RuntimeException('down'));

		$response = $this->controller(appId: 'decidiq')->search();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('store_unreachable', $response->getData()['outcome']);
	}//end testFederatedSearchFailureIsContained()

	/**
	 * The app's own items ride back with the cards.
	 *
	 * They are declared ONCE, in the `store` block. StoreManifest parsed them
	 * and nothing ever sent them anywhere, because CnStorePage's `builtIn`
	 * prop is fed from the PAGE CONFIG — a second place to write the same
	 * list. An app that declared them only in the `store` block rendered an
	 * empty store while its manifest said otherwise. Measured on decidiq,
	 * which declares four example sets and showed none.
	 *
	 * @return void
	 */
	public function testSearchServesTheBuiltInItemsTheManifestDeclares(): void {
		$this->signIn();
		$this->manifestLoader->method('loadStore')->willReturn(
			StoreManifest::fromManifest(
				appId: 'decidiq',
				manifest: [
					'store' => [
						'types' => ['openregister.configset'],
						'builtIn' => [
							['slug' => 'municipality', 'title' => 'Municipality'],
							['slug' => 'association', 'title' => 'Association or VvE'],
						],
					],
				]
			)
		);
		$this->catalog->method('search')->willReturn(['outcome' => 'ok', 'cards' => []]);

		$data = $this->controller(appId: 'decidiq')->search()->getData();

		$this->assertArrayHasKey('builtIn', $data);
		$this->assertCount(2, $data['builtIn']);
		$this->assertSame('municipality', $data['builtIn'][0]['slug']);
	}//end testSearchServesTheBuiltInItemsTheManifestDeclares()

	/**
	 * With no store block at all there is nothing to ship, and the key is
	 * still present so the page never has to null-check it.
	 *
	 * @return void
	 */
	public function testBuiltInIsAnEmptyListWhenNoStoreIsDeclared(): void {
		$this->signIn();
		$this->manifestLoader->method('loadStore')
			->willReturn(new StoreManifest(appId: 'keepiq', enabled: false));

		$data = $this->controller(appId: 'keepiq')->search()->getData();

		$this->assertArrayHasKey('builtIn', $data);
		$this->assertSame([], $data['builtIn']);
	}//end testBuiltInIsAnEmptyListWhenNoStoreIsDeclared()

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
	 * The kinds the APP declared ride back with the cards.
	 *
	 * Without this the `kinds` key in the store block is declared and read by
	 * nobody: the page keeps its own copy in its page config and the block key
	 * is a silent no-op. Asserted on the not_configured arm too, so an
	 * unconfigured store still offers its filters over the built-in list.
	 *
	 * @return void
	 */
	public function testSearchReturnsTheKindsTheAppDeclared(): void {
		$this->signIn();
		$this->manifestLoader->method('loadStore')->willReturn(
			StoreManifest::fromManifest(
				appId: 'dossiq',
				manifest: ['store' => ['schema' => 't', 'kinds' => ['case-type', 'flow-template']]]
			)
		);
		$this->storeService->method('search')->willReturn(['outcome' => 'ok', 'cards' => []]);

		$response = $this->controller()->search();

		$this->assertSame(['case-type', 'flow-template'], $response->getData()['kinds']);
	}//end testSearchReturnsTheKindsTheAppDeclared()

	/**
	 * An app that declares no kinds gets an empty list, not a missing key: the
	 * page then falls back to the shared vocabulary rather than null-checking.
	 *
	 * @return void
	 */
	public function testTheKindsKeyIsAlwaysPresent(): void {
		$this->signIn();
		$this->manifestLoader->method('loadStore')
			->willReturn(new StoreManifest(appId: 'keepiq', enabled: false));

		$data = $this->controller(appId: 'keepiq')->search()->getData();

		$this->assertArrayHasKey('kinds', $data);
		$this->assertSame([], $data['kinds']);
	}//end testTheKindsKeyIsAlwaysPresent()

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
		// The store is loaded BEFORE the gate now, because the gate is the
		// app's declaration rather than the engine's assumption. A default
		// block declares no installAuth, so the posture is `admin`.
		$this->manifestLoader->method('loadStore')->willReturn($this->enabledStore());
		$this->storeService->expects($this->never())->method('resolve');
		$this->installer->expects($this->never())->method('install');

		$response = $this->controller()->install(slug: 'vth');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testInstallRefusesANonAdministrator()

	/**
	 * An `authenticated` store admits a signed-in non-administrator.
	 *
	 * This is the capability hermiq and buildiq would LOSE by migrating onto
	 * an admin-only install, so it is asserted rather than assumed.
	 *
	 * @return void
	 */
	public function testAnAuthenticatedStoreAdmitsANonAdministrator(): void {
		$this->signIn(isAdmin: false);
		$this->manifestLoader->method('loadStore')->willReturn(
			StoreManifest::fromManifest('demo', [
				'store' => [
					'schema' => 'template',
					'installAuth' => 'authenticated',
					'installable' => ['caseType'],
				],
			])
		);
		$this->storeService->method('resolve')->willReturn(['slug' => 'vth', 'components' => []]);
		$this->installer->expects($this->once())
			->method('install')
			->willReturn(['success' => true, 'components' => []]);

		$response = $this->controller('demo')->install(slug: 'vth');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	/**
	 * An anonymous caller is refused even by an `authenticated` store.
	 *
	 * 🔴 `authenticated` is the weakest posture the vocabulary offers, and it
	 * still means signed in. If this ever passes, the weakest gate has become
	 * no gate.
	 *
	 * @return void
	 */
	public function testAnAuthenticatedStoreStillRefusesAnonymous(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->manifestLoader->method('loadStore')->willReturn(
			StoreManifest::fromManifest('demo', [
				'store' => ['schema' => 'template', 'installAuth' => 'authenticated'],
			])
		);
		$this->installer->expects($this->never())->method('install');

		$this->assertSame(
			Http::STATUS_FORBIDDEN,
			$this->controller('demo')->install(slug: 'vth')->getStatus()
		);
	}

	/**
	 * An action posture asks the LEAF app, and installs when it says yes.
	 *
	 * @return void
	 */
	public function testAnActionPostureInstallsWhenTheLeafMatrixAllowsIt(): void {
		$this->signIn(isAdmin: false);
		$this->manifestLoader->method('loadStore')->willReturn($this->actionStore());
		$this->actionAuthorizer->expects($this->once())
			->method('can')
			->with('demo', 'catalog.instantiate', $this->anything())
			->willReturn(true);
		$this->storeService->method('resolve')->willReturn(['slug' => 'vth', 'components' => []]);
		$this->installer->expects($this->once())
			->method('install')
			->willReturn(['success' => true, 'components' => []]);

		$this->assertSame(
			Http::STATUS_OK,
			$this->controller('demo')->install(slug: 'vth')->getStatus()
		);
	}

	/**
	 * And refuses when it says no.
	 *
	 * @return void
	 */
	public function testAnActionPostureRefusesWhenTheLeafMatrixDeclines(): void {
		$this->signIn(isAdmin: false);
		$this->manifestLoader->method('loadStore')->willReturn($this->actionStore());
		$this->actionAuthorizer->method('can')->willReturn(false);
		$this->installer->expects($this->never())->method('install');

		$this->assertSame(
			Http::STATUS_FORBIDDEN,
			$this->controller('demo')->install(slug: 'vth')->getStatus()
		);
	}

	/**
	 * 🔴 An administrator does NOT bypass the action matrix.
	 *
	 * The point of the posture is that the leaf app decides. An engine that
	 * let admins through anyway would make the declaration decorative, and the
	 * app could never gate an install MORE tightly than instance admin.
	 *
	 * @return void
	 */
	public function testAnAdministratorStillGoesThroughTheActionMatrix(): void {
		$this->signIn(isAdmin: true);
		$this->manifestLoader->method('loadStore')->willReturn($this->actionStore());
		$this->actionAuthorizer->expects($this->once())->method('can')->willReturn(false);
		$this->installer->expects($this->never())->method('install');

		$this->assertSame(
			Http::STATUS_FORBIDDEN,
			$this->controller('demo')->install(slug: 'vth')->getStatus(),
			'Being an administrator must not skip the action the app declared.'
		);
	}

	/**
	 * An anonymous caller never reaches the matrix at all.
	 *
	 * @return void
	 */
	public function testAnActionPostureRefusesAnonymousWithoutAsking(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->manifestLoader->method('loadStore')->willReturn($this->actionStore());
		$this->actionAuthorizer->expects($this->never())->method('can');
		$this->installer->expects($this->never())->method('install');

		$this->assertSame(
			Http::STATUS_FORBIDDEN,
			$this->controller('demo')->install(slug: 'vth')->getStatus()
		);
	}

	/**
	 * A store gating install on an ADR-023 action.
	 *
	 * @return StoreManifest
	 */
	private function actionStore(): StoreManifest {
		return StoreManifest::fromManifest('demo', [
			'store' => [
				'schema' => 'template',
				'installAuth' => 'action:catalog.instantiate',
				'installable' => ['caseType'],
			],
		]);
	}

	/**
	 * And an anonymous caller, on the same guard.
	 *
	 * @return void
	 */
	public function testInstallRefusesAnAnonymousCaller(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->manifestLoader->method('loadStore')->willReturn($this->enabledStore());
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
	 * An app that aliased the install route but declares no store block gets a
	 * 404, not a silent success. The search path answers not_configured so the
	 * page can render built-ins; there is nothing equivalent to install.
	 *
	 * @return void
	 */
	public function testInstallIs404WhenTheAppDeclaresNoStore(): void {
		$this->signIn(isAdmin: true);
		$this->manifestLoader->method('loadStore')
			->willReturn(new StoreManifest(appId: 'keepiq', enabled: false));
		$this->storeService->expects($this->never())->method('resolve');
		$this->installer->expects($this->never())->method('install');

		$this->assertSame(
			Http::STATUS_NOT_FOUND,
			$this->controller(appId: 'keepiq')->install(slug: 'vth')->getStatus()
		);
	}//end testInstallIs404WhenTheAppDeclaresNoStore()

	/**
	 * A registry that THROWS during resolve is a 404, not a 500, and nothing is
	 * written. The registry's message never reaches the browser.
	 *
	 * @return void
	 */
	public function testInstallTreatsAThrownResolveAsUnresolved(): void {
		$this->signIn(isAdmin: true);
		$this->manifestLoader->method('loadStore')->willReturn($this->enabledStore());
		$this->storeService->method('resolve')
			->willThrowException(new RuntimeException('connect to 10.0.0.5 refused'));
		$this->installer->expects($this->never())->method('install');

		$response = $this->controller()->install(slug: 'vth');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertStringNotContainsString('10.0.0.5', json_encode($response->getData()));
	}//end testInstallTreatsAThrownResolveAsUnresolved()

	/**
	 * The search term and kind filter reach the engine when the request carries
	 * them, and become null when it does not.
	 *
	 * @return void
	 */
	public function testSearchForwardsTheQueryAndKindWhenPresent(): void {
		$this->signIn();
		$this->manifestLoader->method('loadStore')->willReturn($this->enabledStore());
		$this->request->method('getParam')->willReturnMap([
			['q', null, 'enforcement'],
			['kind', null, 'adapter'],
		]);
		$this->storeService->expects($this->once())
			->method('search')
			->willReturnCallback(function ($descriptor, $query, $kind) {
				$this->assertSame('enforcement', $query);
				$this->assertSame('adapter', $kind);
				return ['outcome' => 'ok', 'cards' => []];
			});

		$this->controller()->search();
	}//end testSearchForwardsTheQueryAndKindWhenPresent()

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
