<?php

/**
 * The activate endpoint: what the caller gets back, status included.
 *
 * A refusal must reach the caller as the status it was given. Nextcloud's
 * OCSMiddleware turns a 403 out of an OCSController into an HTTP 200 with the
 * refusal in the body, so this controller must stay a plain `Controller`, and
 * the first test here pins that. The delivered status through the real
 * middleware stack is asserted from a caller's side in the Newman collection
 * `tests/integration/openregister-draft-activation.postman_collection.json`.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/draft-owner-activation-and-lifetime/specs/form-destination/spec.md#requirement-a-drafts-owner-must-be-able-to-make-it-definitive-through-the-object-api
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\ObjectDraftController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\DraftActivationRefusedException;
use OCA\OpenRegister\Service\Object\DraftActivation;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\OCSController;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Decision 216: the owner's action on the object API.
 *
 * @covers \OCA\OpenRegister\Controller\ObjectDraftController
 * @uses \OCA\OpenRegister\Exception\DraftActivationRefusedException
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Schema
 */
class ObjectDraftControllerTest extends TestCase {

	private IRequest&MockObject $request;

	private ObjectService&MockObject $objects;

	private DraftActivation&MockObject $activation;


	private IUserSession&MockObject $session;

	private LoggerInterface&MockObject $logger;

	private ObjectDraftController $controller;

	private Register $register;

	private Schema $schema;

	/**
	 * What the activation was called with.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $activations = [];

	/**
	 * What the RBAC read was called with.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $reads = [];

	/**
	 * The object the RBAC read answers with; null when the caller may not read it.
	 *
	 * @var ObjectEntity|null
	 */
	private ?ObjectEntity $readable = null;

	/**
	 * Whether the render boundary throws.
	 *
	 * @var bool
	 */
	private bool $renderFails = false;

	protected function setUp(): void {
		$this->register = new Register();
		$this->register->setId(10);
		$this->schema = new Schema();
		$this->schema->setId(1);

		$this->request = $this->createMock(IRequest::class);
		$this->objects = $this->createMock(ObjectService::class);
		// The render boundary: takes the `writeOnly` property `secret` out of the entity, as the real one does for the schema that declares it.
		$this->objects->method('renderEntity')->willReturnCallback(
			function (ObjectEntity $entity, ?array $_extend = [], ?int $depth = 0, ?array $filter = [], ?array $fields = [], ?array $unset = [], bool $_rbac = true): array {
				if ($this->renderFails === true) {
					throw new RuntimeException('render boundary down');
				}

				$object = ($entity->getObject() ?? []);
				unset($object['secret']);
				$entity->setObject($object);

				return $entity->jsonSerialize();
			}
		);
		$this->objects->method('find')->willReturnCallback(
			function (
				int|string $id,
				?array $_extend = [],
				bool $files = false,
				$register = null,
				$schema = null,
				bool $_rbac = true,
				bool $_multitenancy = true,
			): ?ObjectEntity {
				$this->reads[] = ['id' => $id, 'register' => $register, 'schema' => $schema, 'rbac' => $_rbac, 'multitenancy' => $_multitenancy];

				return $this->readable;
			}
		);

		$registers = $this->createMock(RegisterMapper::class);
		$registers->method('find')->willReturnCallback(
			function (string|int $id): Register {
				if ((string)$id !== 'service') {
					throw new DoesNotExistException('no register');
				}

				return $this->register;
			}
		);
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willReturnCallback(
			function (string|int $id): Schema {
				if ((string)$id !== 'ticket') {
					throw new DoesNotExistException('no schema');
				}

				return $this->schema;
			}
		);

		$this->activation = $this->createMock(DraftActivation::class);
		$this->session = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$this->controller = new ObjectDraftController(
			appName: 'openregister',
			request: $this->request,
			objects: $this->objects,
			registers: $registers,
			schemas: $schemas,
			activation: $this->activation,
			session: $this->session,
			l10n: $l10n,
			logger: $this->logger
		);
	}//end setUp()

	/**
	 * Sign a user in.
	 */
	private function signIn(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->session->method('getUser')->willReturn($user);

		return $user;
	}//end signIn()

	/**
	 * Make the RBAC read answer with a draft.
	 */
	private function readableDraft(string $owner = 'agent'): ObjectEntity {
		$draft = new ObjectEntity();
		$draft->setUuid('d1');
		$draft->setOwner($owner);
		$draft->setStatus('draft');
		$draft->setObject(['subject' => 'A']);
		$this->readable = $draft;

		return $draft;
	}//end readableDraft()

	/**
	 * Record the activation's arguments and answer with an object, or refuse.
	 */
	private function activationAnswers(ObjectEntity|\Throwable $answer): void {
		$this->activation->method('activateAsUser')->willReturnCallback(
			function (Register $register, Schema $schema, string $draftId, IUser $user, array $changes = []) use ($answer): ObjectEntity {
				$this->activations[] = ['register' => $register, 'schema' => $schema, 'draftId' => $draftId, 'user' => $user, 'changes' => $changes];
				if ($answer instanceof \Throwable) {
					throw $answer;
				}

				return $answer;
			}
		);
	}//end activationAnswers()

	/**
	 * A plain Controller for signed-in users: an OCSController would deliver a 403 as HTTP 200.
	 */
	public function testARefusalCannotBeRewrittenIntoA200(): void {
		$this->assertNotInstanceOf(
			OCSController::class,
			$this->controller,
			'OCSMiddleware turns a 403 from an OCSController into HTTP 200; this controller must stay a plain Controller.'
		);

		$method = new ReflectionMethod(ObjectDraftController::class, 'activate');
		$this->assertCount(1, $method->getAttributes(NoAdminRequired::class));
		$this->assertCount(0, $method->getAttributes(PublicPage::class), 'Making a draft definitive is never a public action.');
	}//end testARefusalCannotBeRewrittenIntoA200()

	/**
	 * The route exists and points at this action.
	 */
	public function testTheRouteIsRegistered(): void {
		$routes = include dirname(__DIR__, 3) . '/appinfo/routes.php';
		$matches = array_values(array_filter($routes['routes'], static fn (array $route): bool => $route['name'] === 'objectDraft#activate'));

		$this->assertCount(1, $matches);
		$this->assertSame('/api/objects/{register}/{schema}/{id}/activate', $matches[0]['url']);
		$this->assertSame('POST', $matches[0]['verb']);
		$this->assertTrue(method_exists(ObjectDraftController::class, 'activate'));
	}//end testTheRouteIsRegistered()

	/**
	 * Spec scenario: the owner gets the activated object back with 200.
	 */
	public function testTheOwnerGetsTheActivatedObject(): void {
		$user = $this->signIn(uid: 'agent');
		$this->readableDraft();
		$this->request->method('getParam')->willReturnCallback(static fn (string $key) => ['object' => ['channel' => 'phone']][$key] ?? null);

		$active = new ObjectEntity();
		$active->setUuid('d1');
		$active->setStatus('active');
		$active->setObject(['subject' => 'A', 'channel' => 'phone']);
		$this->activationAnswers(answer: $active);

		$response = $this->controller->activate(register: 'service', schema: 'ticket', id: 'd1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame($active->jsonSerialize(), $response->getData());

		// The acting user is the asserted owner; the body's `object` is the last changes.
		$this->assertCount(1, $this->activations);
		$this->assertSame($this->register, $this->activations[0]['register']);
		$this->assertSame($this->schema, $this->activations[0]['schema']);
		$this->assertSame('d1', $this->activations[0]['draftId']);
		$this->assertSame($user, $this->activations[0]['user']);
		$this->assertSame(['channel' => 'phone'], $this->activations[0]['changes']);

		// The record was resolved through the RBAC boundary, in its own register and schema.
		$this->assertSame(['id' => 'd1', 'register' => $this->register, 'schema' => $this->schema, 'rbac' => true, 'multitenancy' => true], $this->reads[0]);
	}//end testTheOwnerGetsTheActivatedObject()

	/**
	 * The answer goes through the render boundary: a `writeOnly` property is never in the 200 body.
	 *
	 * Goes red without the redaction: the entity the save handed back carries the secret.
	 */
	public function testAWriteOnlyPropertyIsNotInTheActivatedObject(): void {
		$this->signIn(uid: 'agent');
		$this->readableDraft();

		$active = new ObjectEntity();
		$active->setUuid('d1');
		$active->setStatus('active');
		$active->setObject(['subject' => 'A', 'secret' => 'hunter2']);
		$this->activationAnswers(answer: $active);

		$response = $this->controller->activate(register: 'service', schema: 'ticket', id: 'd1');

		$this->assertSame(200, $response->getStatus());
		$this->assertStringNotContainsString('hunter2', (string)json_encode($response->getData()));
		$this->assertSame('A', $response->getData()['subject'] ?? null);
	}//end testAWriteOnlyPropertyIsNotInTheActivatedObject()

	/**
	 * A render that fails after the activation was committed answers 200 with the id and status, never 500.
	 *
	 * Goes red without the try around renderEntity(): the exception escapes.
	 */
	public function testARenderFailureAfterTheCommitIsStillA200(): void {
		$this->signIn(uid: 'agent');
		$this->readableDraft();
		$active = new ObjectEntity();
		$active->setUuid('d1');
		$active->setStatus('active');
		$active->setObject(['subject' => 'A']);
		$this->activationAnswers(answer: $active);
		$this->renderFails = true;
		$this->logger->expects($this->once())->method('error')->with($this->stringContains('could not be rendered'));

		$response = $this->controller->activate(register: 'service', schema: 'ticket', id: 'd1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('d1', $response->getData()['id']);
		$this->assertSame('active', $response->getData()['@self']['status']);
		$this->assertArrayNotHasKey('subject', $response->getData(), 'Nothing the render boundary would have redacted is sent without it.');
	}//end testARenderFailureAfterTheCommitIsStillA200()

	/**
	 * A body without `object`, or with something that is no object, activates the draft as stored.
	 */
	public function testWithoutABodyTheStoredDraftIsJudged(): void {
		$user = $this->signIn(uid: 'agent');
		$active = $this->readableDraft();
		$this->request->method('getParam')->willReturnCallback(static fn (string $key) => ['object' => 'not an object', 'owner' => 'somebody-else'][$key] ?? null);
		$this->activationAnswers(answer: $active);

		$response = $this->controller->activate(register: 'service', schema: 'ticket', id: 'd1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame([], $this->activations[0]['changes']);
		// A request cannot name the owner: it is the signed-in user, whatever the body says.
		$this->assertSame($user, $this->activations[0]['user']);
	}//end testWithoutABodyTheStoredDraftIsJudged()

	/**
	 * Spec scenario: every refusal keeps its error status and its body.
	 *
	 * @dataProvider refusals
	 *
	 * @param array<int, array<string, string>> $findings The findings.
	 */
	public function testARefusalKeepsItsStatus(string $reason, int $status, array $findings): void {
		$this->signIn(uid: 'colleague');
		$this->readableDraft();
		$this->activationAnswers(answer: new DraftActivationRefusedException(message: 'No.', reason: $reason, findings: $findings));

		$response = $this->controller->activate(register: 'service', schema: 'ticket', id: 'd1');

		$this->assertSame($status, $response->getStatus());
		$this->assertGreaterThanOrEqual(400, $response->getStatus());
		$this->assertSame(['message' => 'No.', 'reason' => $reason, 'findings' => $findings], $response->getData());
	}//end testARefusalKeepsItsStatus()

	/**
	 * The refusals the activation gives.
	 *
	 * @return array<string, array{0: string, 1: int, 2: array<int, array<string, string>>}>
	 */
	public static function refusals(): array {
		return [
			'somebody else\'s draft' => ['not-the-owner', 403, []],
			'not a draft' => ['not-a-draft', 409, []],
			'gone between the read and the action' => ['not-found', 404, []],
			'not valid in full' => ['invalid', 422, [['property' => 'subject', 'code' => 'required', 'message' => 'The required properties (subject) are missing']]],
		];
	}//end refusals()

	/**
	 * A record the caller may not read, an unknown register and an unknown schema all answer 404, and nothing is tried.
	 */
	public function testWhatTheCallerMayNotReadAnswers404(): void {
		$this->signIn(uid: 'colleague');
		$this->activation->expects($this->never())->method('activateAsUser');

		// Not readable: the RBAC read answers null.
		$unreadable = $this->controller->activate(register: 'service', schema: 'ticket', id: 'd1');
		$this->assertSame(404, $unreadable->getStatus());
		$this->assertSame('not-found', $unreadable->getData()['reason']);

		$this->readableDraft();
		$this->assertSame(404, $this->controller->activate(register: 'nope', schema: 'ticket', id: 'd1')->getStatus());
		$this->assertSame(404, $this->controller->activate(register: 'service', schema: 'nope', id: 'd1')->getStatus());
	}//end testWhatTheCallerMayNotReadAnswers404()

	/**
	 * The register and the schema are found whatever organisation they were made in; the draft's own read decides.
	 *
	 * Found on an instance with multitenancy on (14 October): an administrator
	 * made the register and the schema in their organisation, a user in
	 * another organisation made a draft in it and could read it, and their
	 * activation answered 404. The controller looked the register up through
	 * the organisation filter, which the object API's own lookup does not.
	 */
	public function testTheRegisterOfAnotherOrganisationIsFound(): void {
		$owner = $this->signIn(uid: 'agent');
		$this->readableDraft();
		$activated = new ObjectEntity();
		$activated->setUuid('d1');
		$activated->setStatus('active');
		$this->activationAnswers(answer: $activated);

		// Mappers that answer as the real ones do for a register and a schema of another organisation.
		$registers = $this->createMock(RegisterMapper::class);
		$registers->method('find')->willReturnCallback(
			function (string|int $id, bool $_rbac = true, bool $_multitenancy = true): Register {
				if ($_multitenancy === true) {
					throw new DoesNotExistException('another organisation');
				}

				return $this->register;
			}
		);
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willReturnCallback(
			function (string|int $id, ?array $_extend = [], bool $_rbac = true, bool $_multitenancy = true): Schema {
				if ($_multitenancy === true) {
					throw new DoesNotExistException('another organisation');
				}

				return $this->schema;
			}
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$controller = new ObjectDraftController(
			appName: 'openregister',
			request: $this->request,
			objects: $this->objects,
			registers: $registers,
			schemas: $schemas,
			activation: $this->activation,
			session: $this->session,
			l10n: $l10n,
			logger: $this->logger
		);

		$response = $controller->activate(register: '27', schema: '43', id: 'd1');

		$this->assertSame(200, $response->getStatus(), (string)json_encode($response->getData()));
		$this->assertSame($owner, $this->activations[0]['user']);
		// Control: the draft itself is still read under RBAC and the organisation filter.
		$this->assertTrue($this->reads[0]['rbac']);
		$this->assertTrue($this->reads[0]['multitenancy']);
	}//end testTheRegisterOfAnotherOrganisationIsFound()

	/**
	 * A read that throws (RBAC refusing) answers the same 404.
	 */
	public function testAReadThatThrowsAnswers404(): void {
		$this->signIn(uid: 'colleague');
		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willThrowException(new RuntimeException('not allowed'));
		$registers = $this->createMock(RegisterMapper::class);
		$registers->method('find')->willReturn($this->register);
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willReturn($this->schema);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$this->activation->expects($this->never())->method('activateAsUser');

		$controller = new ObjectDraftController(
			appName: 'openregister',
			request: $this->request,
			objects: $objects,
			registers: $registers,
			schemas: $schemas,
			activation: $this->activation,
			session: $this->session,
			l10n: $l10n,
			logger: $this->logger
		);

		$this->assertSame(404, $controller->activate(register: 'service', schema: 'ticket', id: 'd1')->getStatus());
	}//end testAReadThatThrowsAnswers404()

	/**
	 * Nobody signed in: 401, and nothing is read.
	 */
	public function testNobodySignedInAnswers401(): void {
		$this->session->method('getUser')->willReturn(null);
		$this->activation->expects($this->never())->method('activateAsUser');

		$response = $this->controller->activate(register: 'service', schema: 'ticket', id: 'd1');

		$this->assertSame(401, $response->getStatus());
		$this->assertSame('not-signed-in', $response->getData()['reason']);
		$this->assertSame([], $this->reads);
	}//end testNobodySignedInAnswers401()

	/**
	 * An outage answers 500 with a sentence, is logged, and never echoes the storage message.
	 */
	public function testAnOutageAnswers500WithoutTheStorageMessage(): void {
		$this->signIn(uid: 'agent');
		$this->readableDraft();
		$this->activationAnswers(answer: new RuntimeException('SQLSTATE[HY000] secret table name'));
		$this->logger->expects($this->once())->method('error');

		$response = $this->controller->activate(register: 'service', schema: 'ticket', id: 'd1');

		$this->assertSame(500, $response->getStatus());
		$this->assertSame('unavailable', $response->getData()['reason']);
		$this->assertStringNotContainsString('SQLSTATE', (string)json_encode($response->getData()));
	}//end testAnOutageAnswers500WithoutTheStorageMessage()
}//end class
