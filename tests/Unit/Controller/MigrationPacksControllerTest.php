<?php

/**
 * MigrationPacksControllerTest
 *
 * Unit tests for `MigrationPacksController`: anonymous callers are rejected
 * everywhere; reads (`index`/`show`) succeed for any authenticated user;
 * mutations (`create`/`update`/`destroy`) are admin-only and CSRF-protected;
 * update/destroy always act on the route id; every successful create, update,
 * import and delete leaves an audit log line; the
 * `preview` dry run maps sample rows through an unsaved definition; every
 * error answers `{message, error, details?}` without exception text.
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

use OCA\OpenRegister\Tests\Unit\Service\MigrationPack\PassThroughL10nTrait;
use DateTimeImmutable;
use InvalidArgumentException;
use OCA\OpenRegister\Controller\MigrationPacksController;
use OCA\OpenRegister\Db\MigrationPack;
use OCA\OpenRegister\Db\MigrationPackMapper;
use OCA\OpenRegister\Exception\BuiltinPackSlugLockedException;
use OCA\OpenRegister\Exception\InvalidPackDefinitionException;
use OCA\OpenRegister\Exception\PackChangedException;
use OCA\OpenRegister\Exception\PackSlugExistsException;
use OCA\OpenRegister\Service\MigrationPack\MappingEngine;
use OCA\OpenRegister\Service\MigrationPack\MigrationPackErrorResponder;
use OCA\OpenRegister\Service\MigrationPack\MigrationPackImportResult;
use OCA\OpenRegister\Service\MigrationPack\MigrationPackRequestReader;
use OCA\OpenRegister\Service\MigrationPack\PackDefinitionValidator;
use OCA\OpenRegister\Service\MigrationPackService;
use OCA\OpenRegister\Tests\Unit\Support\MergingRequestFake;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\IAppConfig;
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
use RuntimeException;

class MigrationPacksControllerTest extends TestCase {
	use PassThroughL10nTrait;

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

	/**
	 * Uploaded pack files written by a test, removed afterwards.
	 *
	 * @var string[]
	 */
	private array $tempFiles = [];

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

	protected function tearDown(): void {
		foreach ($this->tempFiles as $file) {
			unlink($file);
		}

		parent::tearDown();
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
			new MigrationPackRequestReader(new PackDefinitionValidator($this->passThroughL10n()), $this->l10n),
			new MigrationPackErrorResponder($this->l10n, $this->logger)
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

	public function testCreateAnswersARefusedDefinitionWithItsReasons(): void {
		$this->setupUser('admin', true);
		$this->service->method('create')->willThrowException(
			new InvalidPackDefinitionException(['"name" is required', 'Mapping row 2: target is required'])
		);

		$response = $this->controller->create();

		$this->assertSame(422, $response->getStatus());
		$this->assertSame(
			[
				'message' => 'The pack definition is invalid',
				'error' => 'invalid-pack',
				'details' => ['errors' => ['"name" is required', 'Mapping row 2: target is required']],
			],
			$response->getData()
		);
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function refusingWrites(): array {
		return [
			'create' => ['create'],
			'update' => ['update'],
			'import' => ['import'],
		];
	}

	#[DataProvider('refusingWrites')]
	public function testEveryWriteAnswersAnInvalidPackInTheSharedShape(string $method): void {
		$this->setupUser('admin', true);
		$refusal = new InvalidPackDefinitionException(['"version" must be a semver string']);
		$this->service->method('create')->willThrowException($refusal);
		$this->service->method('update')->willThrowException($refusal);
		$this->service->method('importDefinition')->willThrowException($refusal);

		$controller = $this->controller;
		if ($method === 'import') {
			$controller = $this->importController($this->validDefinition());
		}

		$response = match ($method) {
			'update' => $controller->update(1),
			default => $controller->$method(),
		};

		$this->assertSame(422, $response->getStatus());
		$data = $response->getData();
		$this->assertSame('invalid-pack', $data['error']);
		$this->assertSame('The pack definition is invalid', $data['message']);
		$this->assertSame(['"version" must be a semver string'], $data['details']['errors']);
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function failingWrites(): array {
		return [
			'create' => ['create'],
			'update' => ['update'],
			'destroy' => ['destroy'],
			'import' => ['import'],
		];
	}

	#[DataProvider('failingWrites')]
	public function testAnUnexpectedFailureIsLoggedAndAnsweredWithoutItsText(string $method): void {
		$this->setupUser('admin', true);
		$failure = new RuntimeException('SQLSTATE[HY000] secret-table-name');
		$this->service->method('create')->willThrowException($failure);
		$this->service->method('update')->willThrowException($failure);
		$this->service->method('delete')->willThrowException($failure);
		$this->service->method('importDefinition')->willThrowException($failure);
		$this->logger->expects($this->once())
			->method('error')
			->with($this->anything(), $this->callback(static fn (array $context): bool => $context['exception'] === $failure));

		$controller = $this->controller;
		if ($method === 'import') {
			$controller = $this->importController($this->validDefinition());
		}

		$response = match ($method) {
			'update', 'destroy' => $controller->$method(1),
			default => $controller->$method(),
		};

		$this->assertSame(500, $response->getStatus());
		$data = $response->getData();
		$this->assertSame('migration-pack-error', $data['error']);
		$this->assertStringNotContainsString('secret-table-name', json_encode($data));
		$this->assertNotEmpty($data['message']);
	}

	public function testAPlainInvalidArgumentIsNotEchoedBack(): void {
		// Only a pack refusal carries reasons meant for the caller; any other
		// InvalidArgumentException is an internal fault.
		$this->setupUser('admin', true);
		$this->service->method('create')->willThrowException(new InvalidArgumentException('internal detail'));

		$response = $this->controller->create();

		$this->assertSame(500, $response->getStatus());
		$this->assertStringNotContainsString('internal detail', json_encode($response->getData()));
	}

	/**
	 * @return array<string, array{string, bool, int, string}>
	 */
	public static function refusedCallers(): array {
		return [
			'anonymous index' => ['index', false, 401, 'not-authenticated'],
			'anonymous show' => ['show', false, 401, 'not-authenticated'],
			'anonymous export' => ['export', false, 401, 'not-authenticated'],
			'anonymous create' => ['create', false, 401, 'not-authenticated'],
			'anonymous preview' => ['preview', false, 401, 'not-authenticated'],
			'non-admin create' => ['create', true, 403, 'admin-required'],
			'non-admin update' => ['update', true, 403, 'admin-required'],
			'non-admin destroy' => ['destroy', true, 403, 'admin-required'],
			'non-admin import' => ['import', true, 403, 'admin-required'],
			'non-admin preview' => ['preview', true, 403, 'admin-required'],
		];
	}

	#[DataProvider('refusedCallers')]
	public function testARefusedCallerGetsAMessageAndACode(string $method, bool $signedIn, int $status, string $error): void {
		$this->setupUser($signedIn === true ? 'alice' : null, false);

		$response = match ($method) {
			'show', 'export', 'update', 'destroy' => $this->controller->$method(1),
			default => $this->controller->$method(),
		};

		$this->assertSame($status, $response->getStatus());
		$this->assertSame($error, $response->getData()['error']);
		$this->assertNotEmpty($response->getData()['message']);
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function lookupsByRowId(): array {
		return [
			'show' => ['show'],
			'export' => ['export'],
			'update' => ['update'],
			'destroy' => ['destroy'],
		];
	}

	#[DataProvider('lookupsByRowId')]
	public function testAnUnknownRowIdAnswersPackNotFound(string $method): void {
		$this->setupUser('admin', true);
		$missing = new DoesNotExistException('not found');
		$this->service->method('find')->willThrowException($missing);
		$this->service->method('update')->willThrowException($missing);
		$this->service->method('delete')->willThrowException($missing);

		$response = $this->controller->$method(999);

		$this->assertSame(404, $response->getStatus());
		$this->assertSame(
			['message' => 'Migration pack not found', 'error' => 'pack-not-found'],
			$response->getData()
		);
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

	/**
	 * @return array<string, array{array<string, mixed>}>
	 */
	public static function updateBodies(): array {
		return [
			'raw body' => [[]],
			'envelope' => [['envelope' => true]],
		];
	}

	/**
	 * The route-id fix against Nextcloud's own Request, not the fake.
	 *
	 * MergingRequestFake copies today's merge order; this test reads it from
	 * the real class, so a change there shows up here. It only runs when the
	 * bootstrap loaded Nextcloud's Request rather than the empty stub.
	 *
	 * @param array<string, mixed> $options `envelope` => true wraps the definition.
	 */
	#[DataProvider('updateBodies')]
	public function testUpdateReadsTheRouteIdFromARealNextcloudRequest(array $options): void {
		if (method_exists(\OC\AppFramework\Http\Request::class, 'setUrlParameters') === false) {
			$this->markTestSkipped('Needs Nextcloud\'s own Request class; this run loaded the stub.');
		}

		$this->setupUser('admin', true);
		$body = $this->validDefinition();
		if (($options['envelope'] ?? false) === true) {
			$body = ['definition' => $body];
		}

		$stream = tempnam(sys_get_temp_dir(), 'body');
		file_put_contents($stream, json_encode($body));
		$this->tempFiles[] = $stream;

		$request = new \OC\AppFramework\Http\Request(
			[
				'method' => 'PUT',
				'server' => ['CONTENT_TYPE' => 'application/json'],
			],
			$this->createMock(\OCP\IRequestId::class),
			$this->createMock(\OCP\IConfig::class),
			null,
			$stream
		);
		$request->setUrlParameters(['_route' => 'openregister.migrationPacks.update', 'id' => '5']);

		// What the Dispatcher hands to update(int $id).
		$dispatchedId = $request->getParam('id');
		settype($dispatchedId, 'integer');

		$this->service->expects($this->once())
			->method('update')
			->with(5, $this->validDefinition())
			->willReturn($this->packEntity('stackiq-topdesk-module'));

		$response = $this->controllerFor($request)->update($dispatchedId);
		$this->assertSame(200, $response->getStatus());
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

	public function testUpdatePassesTheReadUpdateTimeToTheService(): void {
		$this->setupUser('admin', true);
		$request = new MergingRequestFake(
			['_route' => 'openregister.migrationPacks.update', 'id' => '5'],
			['definition' => $this->validDefinition(), 'expectedUpdatedAt' => '2026-10-12T10:00:00+00:00']
		);

		$this->service->expects($this->once())
			->method('update')
			->with(
				5,
				$this->validDefinition(),
				(new DateTimeImmutable('2026-10-12T10:00:00+00:00'))->getTimestamp()
			)
			->willReturn($this->packEntity('stackiq-topdesk-module'));

		$response = $this->controllerFor($request)->update($request->dispatchedInt('id'));
		$this->assertSame(200, $response->getStatus());
	}

	public function testUpdateWithoutAReadUpdateTimeOverwrites(): void {
		$this->setupUser('admin', true);
		$request = new MergingRequestFake(
			['_route' => 'openregister.migrationPacks.update', 'id' => '5'],
			['definition' => $this->validDefinition()]
		);

		$this->service->expects($this->once())
			->method('update')
			->with(5, $this->validDefinition(), null)
			->willReturn($this->packEntity('stackiq-topdesk-module'));

		$this->assertSame(200, $this->controllerFor($request)->update(5)->getStatus());
	}

	public function testUpdateBasedOnAnOutdatedReadAnswersPackChanged(): void {
		$this->setupUser('admin', true);
		$request = new MergingRequestFake(
			['_route' => 'openregister.migrationPacks.update', 'id' => '5'],
			['definition' => $this->validDefinition(), 'expectedUpdatedAt' => '2026-10-12T10:00:00+00:00']
		);
		$this->service->method('update')
			->willThrowException(new PackChangedException('Migration pack 5 was saved after 2026-10-12T10:00:00+00:00'));
		$this->logger->expects($this->never())->method('info');

		$response = $this->controllerFor($request)->update(5);

		$this->assertSame(409, $response->getStatus());
		$this->assertSame(
			['message' => 'The migration pack was saved by someone else after it was read', 'error' => 'pack-changed'],
			$response->getData()
		);
	}

	/**
	 * @return array<string, array{mixed}>
	 */
	public static function malformedUpdateTimes(): array {
		return [
			'not a date' => ['yesterday'],
			'no offset' => ['2026-10-12 10:00:00'],
			'a number' => [1760263200],
		];
	}

	#[DataProvider('malformedUpdateTimes')]
	public function testAMalformedReadUpdateTimeIsABadRequest(mixed $expected): void {
		$this->setupUser('admin', true);
		$request = new MergingRequestFake(
			['_route' => 'openregister.migrationPacks.update', 'id' => '5'],
			['definition' => $this->validDefinition(), 'expectedUpdatedAt' => $expected]
		);
		$this->service->expects($this->never())->method('update');

		$response = $this->controllerFor($request)->update(5);

		$this->assertSame(400, $response->getStatus());
		$this->assertSame('invalid-request', $response->getData()['error']);
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

		$this->service->expects($this->never())->method('update');

		$response = $this->controllerFor($request)->update($request->dispatchedInt('id'));

		$this->assertSame(422, $response->getStatus());
		$data = $response->getData();
		$this->assertSame('invalid-pack', $data['error']);
		$this->assertStringContainsString('"id" is required', $data['details']['errors'][0]);
	}

	public function testUpdateRawBodyWhoseSlugIsTheRowIdIsRefusedWithAPointerToTheEnvelope(): void {
		// Slug "5" on row 5: after Nextcloud's merge the body id and the route
		// id are the same string, so the raw form cannot keep it.
		$this->setupUser('admin', true);
		$request = new MergingRequestFake(
			['_route' => 'openregister.migrationPacks.update', 'id' => '5'],
			$this->validDefinition(['id' => '5'])
		);

		$this->service->expects($this->never())->method('update');

		$response = $this->controllerFor($request)->update($request->dispatchedInt('id'));

		$this->assertSame(422, $response->getStatus());
		$errors = $response->getData()['details']['errors'];
		$this->assertStringContainsString('{"definition": {...}}', end($errors));
	}

	public function testUpdateEnvelopeKeepsASlugThatIsTheRowId(): void {
		$this->setupUser('admin', true);
		$request = new MergingRequestFake(
			['_route' => 'openregister.migrationPacks.update', 'id' => '5'],
			['definition' => $this->validDefinition(['id' => '5'])]
		);

		$this->service->expects($this->once())
			->method('update')
			->with(5, $this->validDefinition(['id' => '5']))
			->willReturn($this->packEntity('5'));

		$this->assertSame(200, $this->controllerFor($request)->update($request->dispatchedInt('id'))->getStatus());
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

	/**
	 * @return array<string, array{string}>
	 */
	public static function slugWrites(): array {
		return [
			'create' => ['create'],
			'update' => ['update'],
		];
	}

	#[DataProvider('slugWrites')]
	public function testATakenSlugAnswersItsOwnCodeWithoutEnglishDetails(string $method): void {
		$this->setupUser('admin', true);
		$this->service->method($method)
			->willThrowException(new PackSlugExistsException('stackiq-topdesk-module'));

		$response = $method === 'create' ? $this->controller->create() : $this->controller->update(1);

		$this->assertSame(422, $response->getStatus());
		$this->assertSame(
			['message' => 'A migration pack with this id already exists', 'error' => 'pack-slug-exists'],
			$response->getData()
		);
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

	/**
	 * A controller whose request carries an uploaded pack file.
	 *
	 * @param array<string, mixed> $definition The pack document in the file.
	 *
	 * @return MigrationPacksController
	 */
	private function importController(array $definition): MigrationPacksController {
		$file = tempnam(sys_get_temp_dir(), 'pack');
		file_put_contents($file, json_encode($definition));
		$this->tempFiles[] = $file;
		$request = $this->createMock(IRequest::class);
		$request->method('getUploadedFile')->with('file')->willReturn(['tmp_name' => $file]);

		return $this->controllerFor($request);
	}

	/**
	 * A stored pack entity with a row id.
	 *
	 * @param string $slug The pack slug.
	 * @param int $id The row id.
	 * @param bool $builtin Whether the pack is built-in.
	 *
	 * @return MigrationPack
	 */
	private function storedPack(string $slug, int $id, bool $builtin = false): MigrationPack {
		$pack = $this->packEntity($slug);
		$pack->setId($id);
		$pack->setBuiltin($builtin);

		return $pack;
	}

	public function testImportReplacingAStoredPackLogsTheActorSlugAndOutcome(): void {
		$this->setupUser('ronald', true);
		$stored = $this->storedPack('stackiq-topdesk-module', 5, true);
		// The outcome comes from the write, not from a lookup before it.
		$this->service->expects($this->never())->method('findByPackSlug');
		$this->service->expects($this->once())
			->method('importDefinition')
			->with($this->validDefinition(), 'ronald')
			->willReturn(new MigrationPackImportResult($stored, false));

		$this->logger->expects($this->once())
			->method('info')
			->with(
				$this->stringContains('imported by {userId}'),
				$this->callback(
					static fn (array $context): bool => $context['userId'] === 'ronald'
						&& $context['packSlug'] === 'stackiq-topdesk-module'
						&& $context['packId'] === 5
						&& $context['outcome'] === 'replaced'
						&& $context['builtin'] === true
				)
			);

		$response = $this->importController($this->validDefinition())->import();
		$this->assertSame(200, $response->getStatus(), 'a replaced pack is not answered as Created');
	}

	public function testImportOfANewSlugLogsThatItCreatedAPack(): void {
		$this->setupUser('ronald', true);
		$this->service->method('importDefinition')->willReturn(
			new MigrationPackImportResult($this->storedPack('stackiq-topdesk-module', 8), true)
		);

		$this->logger->expects($this->once())
			->method('info')
			->with(
				$this->stringContains('imported by {userId}'),
				$this->callback(
					static fn (array $context): bool => $context['outcome'] === 'created'
						&& $context['packId'] === 8
						&& $context['userId'] === 'ronald'
				)
			);

		$this->assertSame(201, $this->importController($this->validDefinition())->import()->getStatus());
	}

	public function testImportFailureWritesNoAuditLine(): void {
		$this->setupUser('ronald', true);
		$this->service->method('importDefinition')->willThrowException(new InvalidPackDefinitionException(['bad pack']));
		$this->logger->expects($this->never())->method('info');

		$this->assertSame(422, $this->importController($this->validDefinition())->import()->getStatus());
	}

	public function testImportWithoutAFileIsABadRequest(): void {
		$this->setupUser('ronald', true);
		$this->service->expects($this->never())->method('importDefinition');

		$response = $this->controller->import();

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['message' => 'No file uploaded', 'error' => 'invalid-request'], $response->getData());
	}

	public function testImportOfAFileThatIsNotAJsonObjectIsABadRequest(): void {
		$this->setupUser('ronald', true);
		$file = tempnam(sys_get_temp_dir(), 'pack');
		file_put_contents($file, '"just a string"');
		$this->tempFiles[] = $file;
		$request = $this->createMock(IRequest::class);
		$request->method('getUploadedFile')->with('file')->willReturn(['tmp_name' => $file]);

		$response = $this->controllerFor($request)->import();

		$this->assertSame(400, $response->getStatus());
		$this->assertSame('invalid-request', $response->getData()['error']);
	}

	public function testCreateLogsTheActorAndSlug(): void {
		$this->setupUser('ronald', true);
		$request = new MergingRequestFake(
			['_route' => 'openregister.migrationPacks.create'],
			['definition' => $this->validDefinition()],
			'POST'
		);
		$this->service->method('create')->willReturn($this->storedPack('stackiq-topdesk-module', 6, true));

		$this->logger->expects($this->once())
			->method('info')
			->with(
				$this->stringContains('created by {userId}'),
				$this->callback(
					static fn (array $context): bool => $context['userId'] === 'ronald'
						&& $context['packSlug'] === 'stackiq-topdesk-module'
						&& $context['packId'] === 6
						&& $context['builtin'] === true
				)
			);

		$this->assertSame(201, $this->controllerFor($request)->create()->getStatus());
	}

	public function testCreateFailureWritesNoAuditLine(): void {
		$this->setupUser('ronald', true);
		$this->service->method('create')->willThrowException(new InvalidPackDefinitionException(['bad pack']));
		$this->logger->expects($this->never())->method('info');

		$this->assertSame(422, $this->controller->create()->getStatus());
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

	public function testEveryTranslatedMessageHasADutchTranslation(): void {
		// PHP IL10N reads l10n/<lang>.json; a key missing there reaches a Dutch
		// administrator in English. The controller's messages live in its
		// collaborators, and the pack editor shows the validator's and the
		// mapping engine's findings too.
		$source = '';
		foreach ([
			'lib/Controller/MigrationPacksController.php',
			'lib/Service/MigrationPack/MigrationPackErrorResponder.php',
			'lib/Service/MigrationPack/MigrationPackRequestReader.php',
			'lib/Service/MigrationPack/PackDefinitionValidator.php',
			'lib/Service/MigrationPack/MappingEngine.php',
		] as $file) {
			$source .= file_get_contents(__DIR__ . '/../../../' . $file);
		}

		preg_match_all("/->t\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", $source, $matches);
		$this->assertNotEmpty($matches[1]);

		$catalogue = json_decode(
			file_get_contents(__DIR__ . '/../../../l10n/nl.json'),
			true
		)['translations'];
		foreach ($matches[1] as $message) {
			$message = stripslashes($message);
			$this->assertArrayHasKey($message, $catalogue, 'l10n/nl.json has no "' . $message . '"');
			$this->assertNotSame('', trim($catalogue[$message]));
		}
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
		// AuthorizedAdminSetting would also admit users an admin delegated the
		// OpenRegister section to. With no attribute, Nextcloud's middleware
		// admits administrators only.
		$this->assertSame(
			[],
			$reflection->getAttributes(AuthorizedAdminSetting::class),
			$method . ' must not open to delegated admins'
		);

		// Nextcloud still honours the docblock forms, so they must be gone too.
		$docComment = (string)$reflection->getDocComment();
		$this->assertStringNotContainsString('@NoCSRFRequired', $docComment);
		$this->assertStringNotContainsString('@NoAdminRequired', $docComment);
		$this->assertMatchesRegularExpression('/^\s*\* @auth admin-only \S/m', $docComment, $method . ' must declare its posture');
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
			new PackDefinitionValidator($this->passThroughL10n()),
			new MappingEngine($this->passThroughL10n()),
			$this->createMock(IAppConfig::class)
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

	public function testPreviewRejectsAnOversizedRequest(): void {
		$this->setupUser('admin', true);
		$wide = ['Naam' => str_repeat('x', (int)(MigrationPackRequestReader::MAX_PREVIEW_BYTES / 2))];
		$controller = $this->previewController(['definition' => $this->validDefinition(), 'rows' => [$wide, $wide]]);

		$response = $controller->preview();

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(
			['message' => 'The preview request is too large', 'error' => 'invalid-request'],
			$response->getData()
		);
	}

	public function testPreviewFailureAnswersTheSharedErrorCode(): void {
		$this->setupUser('admin', true);
		$this->service->method('previewRows')->willThrowException(new RuntimeException('engine exploded'));
		$request = new MergingRequestFake(
			['_route' => 'openregister.migrationPacks.preview'],
			['definition' => $this->validDefinition(), 'rows' => []],
			'POST'
		);

		$response = $this->controllerFor($request)->preview();

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(
			['message' => 'Failed to preview migration pack', 'error' => 'migration-pack-error'],
			$response->getData()
		);
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
