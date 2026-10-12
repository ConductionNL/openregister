<?php

/**
 * MigrationPacksControllerTest
 *
 * Unit tests for `MigrationPacksController`: anonymous callers are rejected
 * everywhere; reads (`index`/`show`) succeed for any authenticated user;
 * mutations (`create`/`update`/`destroy`) are admin-gated and CSRF-protected;
 * update/destroy always act on the route id and leave an audit log line; the
 * `preview` dry run maps sample rows through an unsaved definition.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Controller
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 *
 * @spec openspec/specs/migration-mapping-packs/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\OpenRegister\Controller\MigrationPacksController;
use OCA\OpenRegister\Db\MigrationPack;
use OCA\OpenRegister\Db\MigrationPackMapper;
use OCA\OpenRegister\Exception\BuiltinPackSlugLockedException;
use OCA\OpenRegister\Service\MigrationPack\MappingEngine;
use OCA\OpenRegister\Service\MigrationPack\PackDefinitionValidator;
use OCA\OpenRegister\Service\MigrationPackService;
use OCA\OpenRegister\Tests\Unit\Support\MergingRequestFake;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

class MigrationPacksControllerTest extends TestCase {
	private MigrationPacksController $controller;
	private MigrationPackService&MockObject $service;
	private IUserSession&MockObject $userSession;
	private IGroupManager&MockObject $groupManager;
	private IRequest&MockObject $request;
	private LoggerInterface&MockObject $logger;
	private IL10N&MockObject $l10n;

	/** @var IUser|null */
	private ?IUser $currentUser = null;

	/** @var bool */
	private bool $currentUserIsAdmin = false;

	protected function setUp(): void {
		parent::setUp();

		$this->service = $this->createMock(MigrationPackService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getParams')->willReturn([]);

		// A single stubbed indirection instead of re-stubbing method()
		// twice on the same mock (PHPUnit 10 quirk: the second stub does
		// not override the first) — see ScheduledReportsControllerTest for
		// the established precedent.
		$this->userSession->method('getUser')->willReturnCallback(fn () => $this->currentUser);
		$this->groupManager->method('isAdmin')->willReturnCallback(fn () => $this->currentUserIsAdmin);

		$this->logger = $this->createMock(LoggerInterface::class);
		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []) => vsprintf($text, $parameters)
		);

		$this->controller = $this->controllerFor($this->request);
	}

	/**
	 * Build a controller around the given request and service.
	 *
	 * @param IRequest $request The request the controller reads.
	 * @param MigrationPackService|null $service Defaults to the mocked service.
	 *
	 * @return MigrationPacksController
	 */
	private function controllerFor(IRequest $request, ?MigrationPackService $service = null): MigrationPacksController {
		return new MigrationPacksController(
			'openregister',
			$request,
			$service ?? $this->service,
			$this->userSession,
			$this->groupManager,
			$this->logger,
			$this->l10n
		);
	}

	/**
	 * A minimal valid pack definition.
	 *
	 * @param array<string, mixed> $overrides Keys to replace.
	 *
	 * @return array<string, mixed>
	 */
	private function validDefinition(array $overrides = []): array {
		return array_merge(
			[
				'id' => 'stackiq-topdesk-module',
				'name' => 'TOPdesk modules',
				'sourceFormat' => 'excel',
				'version' => '2.0.0',
				'idStrategy' => ['type' => 'generate'],
				'fieldMappings' => [
					['source' => 'Naam', 'target' => 'name', 'required' => true, 'transform' => ['type' => 'trim']],
					[
						'source' => 'Status',
						'target' => 'status',
						'transform' => ['type' => 'lookup', 'map' => ['Actief' => 'active']],
					],
				],
			],
			$overrides
		);
	}

	/**
	 * A pack entity as the service returns it.
	 *
	 * @param string $slug The pack slug.
	 *
	 * @return MigrationPack
	 */
	private function packEntity(string $slug): MigrationPack {
		$pack = new MigrationPack();
		$pack->setPackSlug($slug);

		return $pack;
	}

	private function setupUser(?string $uid, bool $isAdmin = false): void {
		if ($uid === null) {
			$this->currentUser = null;
			$this->currentUserIsAdmin = false;
			return;
		}

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->currentUser = $user;
		$this->currentUserIsAdmin = $isAdmin;
	}

	public function testIndexRequiresAuthentication(): void {
		$this->setupUser(null);
		$response = $this->controller->index();
		$this->assertSame(401, $response->getStatus());
	}

	public function testIndexSucceedsForAnyAuthenticatedUser(): void {
		$this->setupUser('alice', false);
		$this->service->method('findAll')->willReturn([]);

		$response = $this->controller->index();
		$this->assertSame(200, $response->getStatus());
	}

	public function testShowRequiresAuthentication(): void {
		$this->setupUser(null);
		$response = $this->controller->show(1);
		$this->assertSame(401, $response->getStatus());
	}

	public function testShowSucceedsForAnyAuthenticatedUser(): void {
		$this->setupUser('alice', false);
		$pack = new MigrationPack();
		$pack->setPackSlug('a-pack');
		$this->service->method('find')->willReturn($pack);

		$response = $this->controller->show(1);
		$this->assertSame(200, $response->getStatus());
	}

	public function testShowReturns404WhenMissing(): void {
		$this->setupUser('alice', false);
		$this->service->method('find')->willThrowException(new DoesNotExistException('not found'));

		$response = $this->controller->show(999);
		$this->assertSame(404, $response->getStatus());
	}

	public function testCreateRequiresAuthentication(): void {
		$this->setupUser(null);
		$response = $this->controller->create();
		$this->assertSame(401, $response->getStatus());
	}

	public function testCreateRejectsNonAdmin(): void {
		$this->setupUser('alice', false);
		$response = $this->controller->create();
		$this->assertSame(403, $response->getStatus());
	}

	public function testCreateSucceedsForAdmin(): void {
		$this->setupUser('admin', true);
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn(['id' => 'a-pack', 'name' => 'A Pack']);

		$controller = $this->controllerFor($request);

		$pack = new MigrationPack();
		$pack->setPackSlug('a-pack');
		$this->service->expects($this->once())->method('create')->willReturn($pack);

		$response = $controller->create();
		$this->assertSame(201, $response->getStatus());
	}

	public function testCreateReturns422OnInvalidArgument(): void {
		$this->setupUser('admin', true);
		$this->service->method('create')->willThrowException(new InvalidArgumentException('bad pack'));

		$response = $this->controller->create();
		$this->assertSame(422, $response->getStatus());
	}

	public function testUpdateRejectsNonAdmin(): void {
		$this->setupUser('alice', false);
		$response = $this->controller->update(1);
		$this->assertSame(403, $response->getStatus());
	}

	public function testUpdateSucceedsForAdmin(): void {
		$this->setupUser('admin', true);
		$pack = new MigrationPack();
		$pack->setPackSlug('a-pack');
		$this->service->expects($this->once())->method('update')->willReturn($pack);

		$response = $this->controller->update(1);
		$this->assertSame(200, $response->getStatus());
	}

	public function testDestroyRejectsNonAdmin(): void {
		$this->setupUser('alice', false);
		$response = $this->controller->destroy(1);
		$this->assertSame(403, $response->getStatus());
	}

	public function testDestroySucceedsForAdmin(): void {
		$this->setupUser('admin', true);
		$this->service->expects($this->once())->method('delete');

		$response = $this->controller->destroy(1);
		$this->assertSame(204, $response->getStatus());
	}

	public function testImportRejectsNonAdmin(): void {
		$this->setupUser('alice', false);
		$response = $this->controller->import();
		$this->assertSame(403, $response->getStatus());
	}
	public function testMergingRequestReproducesTheBodyOverRouteBug(): void {
		// Guards the premise of the route-id tests below: with Nextcloud's merge
		// order a body `id` slug replaces the route id, and the dispatcher's int
		// cast turns it into 0.
		$request = new MergingRequestFake(
			['_route' => 'openregister.migrationPacks.update', 'id' => '5'],
			$this->validDefinition()
		);

		$this->assertSame('stackiq-topdesk-module', $request->getParam('id'));
		$this->assertSame(0, $request->dispatchedInt('id'));
	}

	public function testUpdateEnvelopeKeepsRouteIdAndBodySlug(): void {
		$this->setupUser('admin', true);
		$request = new MergingRequestFake(
			['_route' => 'openregister.migrationPacks.update', 'id' => '5'],
			['definition' => $this->validDefinition()]
		);

		$this->service->expects($this->once())
			->method('update')
			->with(5, $this->validDefinition())
			->willReturn($this->packEntity('stackiq-topdesk-module'));

		$response = $this->controllerFor($request)->update($request->dispatchedInt('id'));
		$this->assertSame(200, $response->getStatus());
	}

	public function testUpdateRawBodyWithSlugStillTargetsTheRouteRow(): void {
		$this->setupUser('admin', true);
		$request = new MergingRequestFake(
			['_route' => 'openregister.migrationPacks.update', 'id' => '5'],
			$this->validDefinition()
		);

		$this->service->expects($this->once())
			->method('update')
			->with(5, $this->validDefinition())
			->willReturn($this->packEntity('stackiq-topdesk-module'));

		// The dispatcher hands in 0 (the cast slug); the controller must still
		// update row 5.
		$response = $this->controllerFor($request)->update($request->dispatchedInt('id'));
		$this->assertSame(200, $response->getStatus());
	}

	public function testUpdateRawBodyWithoutIdDoesNotTurnTheRouteIdIntoASlug(): void {
		$this->setupUser('admin', true);
		$body = $this->validDefinition();
		unset($body['id']);
		$request = new MergingRequestFake(['_route' => 'openregister.migrationPacks.update', 'id' => '5'], $body);

		$this->service->expects($this->once())
			->method('update')
			->with(
				5,
				$this->callback(static fn (array $definition): bool => array_key_exists('id', $definition) === false)
			)
			->willThrowException(new InvalidArgumentException('Invalid migration pack definition: id is required'));

		$response = $this->controllerFor($request)->update($request->dispatchedInt('id'));
		$this->assertSame(422, $response->getStatus());
	}

	public function testCreateAcceptsTheEnvelope(): void {
		$this->setupUser('admin', true);
		$request = new MergingRequestFake(
			['_route' => 'openregister.migrationPacks.create'],
			['definition' => $this->validDefinition()],
			'POST'
		);

		$this->service->expects($this->once())
			->method('create')
			->with($this->validDefinition(), 'admin')
			->willReturn($this->packEntity('stackiq-topdesk-module'));

		$response = $this->controllerFor($request)->create();
		$this->assertSame(201, $response->getStatus());
	}

	public function testCreateRawBodyDropsRouterKeysAndKeepsTheSlug(): void {
		$this->setupUser('admin', true);
		$request = new MergingRequestFake(
			['_route' => 'openregister.migrationPacks.create'],
			$this->validDefinition(),
			'POST'
		);

		$this->service->expects($this->once())
			->method('create')
			->with($this->validDefinition(), 'admin')
			->willReturn($this->packEntity('stackiq-topdesk-module'));

		$response = $this->controllerFor($request)->create();
		$this->assertSame(201, $response->getStatus());
	}

	public function testUpdateRenamingABuiltinPackReturns422WithErrorCode(): void {
		$this->setupUser('admin', true);
		$this->service->method('update')
			->willThrowException(new BuiltinPackSlugLockedException('locked'));

		$response = $this->controller->update(1);

		$this->assertSame(422, $response->getStatus());
		$data = $response->getData();
		$this->assertSame('builtin-slug-locked', $data['error']);
		$this->assertNotEmpty($data['message']);
	}

	public function testUpdateLogsTheActorAndSlug(): void {
		$this->setupUser('admin', true);
		$this->service->method('update')->willReturn($this->packEntity('stackiq-topdesk-module'));

		$this->logger->expects($this->once())
			->method('info')
			->with(
				$this->stringContains('updated by {userId}'),
				$this->callback(
					static fn (array $context): bool => $context['userId'] === 'admin'
						&& $context['packSlug'] === 'stackiq-topdesk-module'
						&& $context['packId'] === 1
				)
			);

		$this->assertSame(200, $this->controller->update(1)->getStatus());
	}

	public function testUpdateFailureWritesNoAuditLine(): void {
		$this->setupUser('admin', true);
		$this->service->method('update')->willThrowException(new DoesNotExistException('not found'));
		$this->logger->expects($this->never())->method('info');

		$this->assertSame(404, $this->controller->update(1)->getStatus());
	}

	public function testDestroyActsOnTheRouteIdAndLogsTheActorAndSlug(): void {
		$this->setupUser('admin', true);
		$request = new MergingRequestFake(
			['_route' => 'openregister.migrationPacks.destroy', 'id' => '5'],
			['id' => 7],
			'DELETE'
		);

		$this->service->expects($this->once())
			->method('delete')
			->with(5)
			->willReturn($this->packEntity('stackiq-topdesk-module'));
		$this->logger->expects($this->once())
			->method('info')
			->with(
				$this->stringContains('deleted by {userId}'),
				$this->callback(
					static fn (array $context): bool => $context['userId'] === 'admin'
						&& $context['packSlug'] === 'stackiq-topdesk-module'
						&& $context['packId'] === 5
				)
			);

		$response = $this->controllerFor($request)->destroy($request->dispatchedInt('id'));
		$this->assertSame(204, $response->getStatus());
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function writeMethods(): array {
		return [
			'create' => ['create'],
			'update' => ['update'],
			'destroy' => ['destroy'],
			'import' => ['import'],
			'preview' => ['preview'],
		];
	}

	#[DataProvider('writeMethods')]
	public function testWriteMethodsRequireCsrfAndAdmin(string $method): void {
		$reflection = new ReflectionMethod(MigrationPacksController::class, $method);

		$this->assertSame([], $reflection->getAttributes(NoCSRFRequired::class), $method . ' must require a CSRF token');
		$this->assertSame([], $reflection->getAttributes(NoAdminRequired::class), $method . ' must not open to non-admins');
		$this->assertCount(1, $reflection->getAttributes(AuthorizedAdminSetting::class), $method . ' must be admin-gated');

		// Nextcloud still honours the docblock forms, so they must be gone too.
		$docComment = (string)$reflection->getDocComment();
		$this->assertStringNotContainsString('@NoCSRFRequired', $docComment);
		$this->assertStringNotContainsString('@NoAdminRequired', $docComment);
	}

	/**
	 * A controller wired to a real service, validator and mapping engine.
	 *
	 * @param array<string, mixed> $body The preview request body.
	 *
	 * @return MigrationPacksController
	 */
	private function previewController(array $body): MigrationPacksController {
		$service = new MigrationPackService(
			$this->createMock(MigrationPackMapper::class),
			new PackDefinitionValidator(),
			new MappingEngine()
		);
		$request = new MergingRequestFake(['_route' => 'openregister.migrationPacks.preview'], $body, 'POST');

		return $this->controllerFor($request, $service);
	}

	public function testPreviewMapsEachSampleRow(): void {
		$this->setupUser('admin', true);
		$controller = $this->previewController(
			[
				'definition' => $this->validDefinition(),
				'rows' => [
					['Naam' => '  Zaaksysteem ', 'Status' => 'Actief'],
					['Naam' => 'Archief', 'Status' => 'Onbekend'],
				],
			]
		);

		$response = $controller->preview();

		$this->assertSame(200, $response->getStatus());
		$rows = $response->getData()['rows'];
		$this->assertCount(2, $rows);

		$this->assertSame(1, $rows[0]['rowNumber']);
		$this->assertSame(['name' => 'Zaaksysteem', 'status' => 'active'], $rows[0]['data']);
		$this->assertSame([], $rows[0]['errors']);

		// An unmapped lookup value errors the row instead of leaking the literal.
		$this->assertSame(2, $rows[1]['rowNumber']);
		$this->assertSame(['name' => 'Archief'], $rows[1]['data']);
		$this->assertCount(1, $rows[1]['errors']);
		$this->assertSame('Status', $rows[1]['errors'][0]['source']);
		$this->assertSame(2, $rows[1]['errors'][0]['row']);
	}

	public function testPreviewRejectsAnInvalidPackWithDetails(): void {
		$this->setupUser('admin', true);
		$controller = $this->previewController(
			[
				'definition' => $this->validDefinition(['fieldMappings' => [['source' => 'Naam', 'transform' => ['type' => 'nope']]]]),
				'rows' => [['Naam' => 'x']],
			]
		);

		$response = $controller->preview();

		$this->assertSame(422, $response->getStatus());
		$data = $response->getData();
		$this->assertSame('invalid-pack', $data['error']);
		$this->assertNotEmpty($data['message']);
		$this->assertNotEmpty($data['details']['errors']);
	}

	public function testPreviewRejectsMoreThanTheMaximumRows(): void {
		$this->setupUser('admin', true);
		$rows = array_fill(0, (MigrationPacksController::MAX_PREVIEW_ROWS + 1), ['Naam' => 'x']);
		$controller = $this->previewController(['definition' => $this->validDefinition(), 'rows' => $rows]);

		$response = $controller->preview();

		$this->assertSame(400, $response->getStatus());
		$this->assertSame('too-many-rows', $response->getData()['error']);
	}

	public function testPreviewAcceptsExactlyTheMaximumRows(): void {
		$this->setupUser('admin', true);
		$rows = array_fill(0, MigrationPacksController::MAX_PREVIEW_ROWS, ['Naam' => 'x']);
		$controller = $this->previewController(['definition' => $this->validDefinition(), 'rows' => $rows]);

		$response = $controller->preview();

		$this->assertSame(200, $response->getStatus());
		$this->assertCount(MigrationPacksController::MAX_PREVIEW_ROWS, $response->getData()['rows']);
	}

	/**
	 * @return array<string, array{array<string, mixed>}>
	 */
	public static function malformedPreviewBodies(): array {
		return [
			'no definition' => [['rows' => [['Naam' => 'x']]]],
			'definition is a string' => [['definition' => 'pack', 'rows' => []]],
			'rows is an object' => [['definition' => ['id' => 'x'], 'rows' => ['first' => ['Naam' => 'x']]]],
			'row is a scalar' => [['definition' => ['id' => 'x'], 'rows' => ['Naam']]],
		];
	}

	/**
	 * @param array<string, mixed> $body The preview request body.
	 */
	#[DataProvider('malformedPreviewBodies')]
	public function testPreviewRejectsAMalformedRequest(array $body): void {
		$this->setupUser('admin', true);

		$response = $this->previewController($body)->preview();

		$this->assertSame(400, $response->getStatus());
		$this->assertSame('invalid-request', $response->getData()['error']);
	}

	public function testPreviewRejectsNonAdmin(): void {
		$this->setupUser('alice', false);
		$response = $this->previewController(['definition' => $this->validDefinition(), 'rows' => []])->preview();
		$this->assertSame(403, $response->getStatus());
	}

	public function testPreviewRequiresAuthentication(): void {
		$this->setupUser(null);
		$response = $this->previewController(['definition' => $this->validDefinition(), 'rows' => []])->preview();
		$this->assertSame(401, $response->getStatus());
	}
}
