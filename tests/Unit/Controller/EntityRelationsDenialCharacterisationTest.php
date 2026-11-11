<?php

/**
 * EntityRelationsController denial characterisation tests.
 *
 * Pins, for the PATCH of one entity relation, what each subject lookup is
 * asked and what each denial logs (level, message and subject keys), so the
 * lookups can take the relation and the email link whole without the
 * outcome or the operator trail moving.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/reviewer-owns-their-decisions/specs/entity-relation-grondslagen/spec.md
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\EntityRelationsController;
use OCA\OpenRegister\Db\EmailLink;
use OCA\OpenRegister\Db\EmailLinkMapper;
use OCA\OpenRegister\Db\EntityRelation;
use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Decision\DecisionVisibility;
use OCA\OpenRegister\Service\Decision\RelationDecisionService;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Tests\Unit\Controller\Support\EntityRelationsControllerFactory;
use OCP\Files\IRootFolder;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Characterisation of EntityRelationsController::update() lookups and denial logs.
 */
class EntityRelationsDenialCharacterisationTest extends TestCase {

	/**
	 * The request double.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * The relation mapper double.
	 *
	 * @var EntityRelationMapper&MockObject
	 */
	private EntityRelationMapper&MockObject $relations;

	/**
	 * The permission double.
	 *
	 * @var PermissionHandler&MockObject
	 */
	private PermissionHandler&MockObject $permissions;

	/**
	 * The magic mapper double.
	 *
	 * @var MagicMapper&MockObject
	 */
	private MagicMapper&MockObject $magicMapper;

	/**
	 * The schema mapper double.
	 *
	 * @var SchemaMapper&MockObject
	 */
	private SchemaMapper&MockObject $schemaMapper;

	/**
	 * The register mapper double.
	 *
	 * @var RegisterMapper&MockObject
	 */
	private RegisterMapper&MockObject $registerMapper;

	/**
	 * The email link mapper double.
	 *
	 * @var EmailLinkMapper&MockObject
	 */
	private EmailLinkMapper&MockObject $emailLinks;

	/**
	 * The decision service double.
	 *
	 * @var RelationDecisionService&MockObject
	 */
	private RelationDecisionService&MockObject $decisions;

	/**
	 * Every log line, as [level, message, context without file/line].
	 *
	 * @var list<array{0: string, 1: string, 2: array}>
	 */
	private array $logged = [];

	/**
	 * Build the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->relations = $this->createMock(EntityRelationMapper::class);
		$this->permissions = $this->createMock(PermissionHandler::class);
		$this->magicMapper = $this->createMock(MagicMapper::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->emailLinks = $this->createMock(EmailLinkMapper::class);
		$this->decisions = $this->createMock(RelationDecisionService::class);
		$this->request->method('getHeader')->willReturn('application/json');
	}

	/**
	 * Answer the PATCH of relation 42 as alice.
	 *
	 * @param EntityRelation $relation The relation the mapper returns.
	 *
	 * @return array{0: int, 1: mixed} Status and body.
	 */
	private function patch(EntityRelation $relation): array {
		$this->relations->method('find')->willReturn($relation);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function ($message, array $context = []) use ($level): void {
					unset($context['file'], $context['line']);
					$this->logged[] = [$level, $message, $context];
				}
			);
		}

		$visibility = $this->createMock(DecisionVisibility::class);
		$visibility->method('present')->willReturnCallback(
			static fn (EntityRelation $relation, string $userId, ?array $serialised = null): array => ($serialised ?? [])
		);

		$controller = EntityRelationsControllerFactory::create(
			'openregister',
			$this->request,
			$this->relations,
			$session,
			$this->createMock(IRootFolder::class),
			$this->permissions,
			$this->magicMapper,
			$this->schemaMapper,
			$this->registerMapper,
			$this->emailLinks,
			$logger,
			$this->decisions,
			$visibility,
			$this->createMock(IGroupManager::class)
		);
		$response = $controller->update(42);

		return [$response->getStatus(), $response->getData()];
	}

	/**
	 * A relation on object 99.
	 *
	 * @param string|null $registerId The register on the relation.
	 * @param string|null $schemaId   The schema on the relation.
	 *
	 * @return EntityRelation
	 */
	private function objectRelation(?string $registerId, ?string $schemaId): EntityRelation {
		$relation = new EntityRelation();
		$relation->setObjectId(99);
		if ($registerId !== null) {
			$relation->setRegisterId($registerId);
		}

		if ($schemaId !== null) {
			$relation->setSchemaId($schemaId);
		}

		return $relation;
	}

	/**
	 * A relation on email 7, whose link names parent-uuid in register 5.
	 *
	 * @return EntityRelation
	 */
	private function emailRelation(): EntityRelation {
		$link = new EmailLink();
		$link->setObjectUuid('parent-uuid');
		$link->setRegisterId(5);
		$this->emailLinks->method('find')->with(7)->willReturn($link);
		$relation = new EntityRelation();
		$relation->setEmailId(7);

		return $relation;
	}

	/**
	 * An object owned by bob in schema `schema-from-object`.
	 *
	 * @return ObjectEntity
	 */
	private function object(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setOwner('bob');
		$object->setSchema('schema-from-object');

		return $object;
	}

	/**
	 * The object lookup is disambiguated by both, and the verdict is taken on the relation's schema.
	 *
	 * @return void
	 */
	public function testAnObjectSubjectIsLookedUpInItsRegisterAndSchema(): void {
		$register = new Register();
		$schema = new Schema();
		$object = $this->object();
		$this->registerMapper->expects($this->once())->method('find')->with('reg-1')->willReturn($register);
		$this->schemaMapper->method('find')->with('sch-1')->willReturn($schema);
		$this->magicMapper->expects($this->once())->method('find')->with(99, $register, $schema)->willReturn($object);
		$this->permissions->expects($this->once())->method('hasPermission')
			->with($schema, 'update', 'alice', 'bob', true, $object)
			->willReturn(false);

		$this->assertSame(403, $this->patch($this->objectRelation('reg-1', 'sch-1'))[0]);
		$this->assertSame(
			[['info', '[EntityRelationsController] PATCH denied — no write-access to relation subject', ['relation_id' => 42, 'actor' => 'alice']]],
			$this->logged
		);
	}

	/**
	 * An unresolvable object is logged with its three ids.
	 *
	 * @return void
	 */
	public function testAnObjectThatDoesNotResolveLogsItsIds(): void {
		$this->registerMapper->method('find')->willThrowException(new RuntimeException('boom'));

		$this->assertSame(403, $this->patch($this->objectRelation('reg-1', 'sch-1'))[0]);
		$this->assertSame(
			[
				'warning',
				'[EntityRelationsController] Object-bound auth check could not resolve object, denying',
				['object_id' => 99, 'register_id' => 'reg-1', 'schema_id' => 'sch-1', 'error' => 'boom'],
			],
			$this->logged[0]
		);
		$this->assertCount(2, $this->logged);
	}

	/**
	 * A schema that does not load is logged with the object and the schema it fell back to.
	 *
	 * @return void
	 */
	public function testAnObjectsSchemaThatDoesNotLoadLogsTheFallbackSchema(): void {
		$this->magicMapper->method('find')->with(99, null, null)->willReturn($this->object());
		$this->schemaMapper->method('find')->willThrowException(new RuntimeException('gone'));

		$this->assertSame(403, $this->patch($this->objectRelation(null, null))[0]);
		$this->assertSame(
			[
				'warning',
				'[EntityRelationsController] Object-bound auth check could not load schema, denying',
				['object_id' => 99, 'schema_id' => 'schema-from-object', 'error' => 'gone'],
			],
			$this->logged[0]
		);
	}

	/**
	 * The email's parent is looked up in the link's register and judged on its own schema.
	 *
	 * @return void
	 */
	public function testAnEmailParentIsLookedUpInTheLinksRegister(): void {
		$register = new Register();
		$schema = new Schema();
		$object = $this->object();
		$this->registerMapper->expects($this->once())->method('find')->with('5')->willReturn($register);
		$this->magicMapper->expects($this->once())->method('find')->with('parent-uuid', $register)->willReturn($object);
		$this->schemaMapper->method('find')->with('schema-from-object')->willReturn($schema);
		$this->permissions->expects($this->once())->method('hasPermission')
			->with($schema, 'update', 'alice', 'bob', true, $object)
			->willReturn(true);
		$this->decisions->method('decide')->willReturnCallback(
			static fn (EntityRelation $relation): array => ['relation' => $relation, 'decision' => null]
		);
		$this->request->method('getParams')->willReturn(['bases' => ['b-1']]);

		$this->assertSame(200, $this->patch($this->emailRelation())[0]);
		$this->assertSame([], $this->logged);
	}

	/**
	 * An unresolvable parent is logged with the email and the parent uuid.
	 *
	 * @return void
	 */
	public function testAnEmailParentThatDoesNotResolveLogsTheUuid(): void {
		$this->registerMapper->method('find')->willThrowException(new RuntimeException('nope'));

		$this->assertSame(403, $this->patch($this->emailRelation())[0]);
		$this->assertSame(
			[
				'warning',
				'[EntityRelationsController] Email-bound auth check could not resolve parent object, denying',
				['email_id' => 7, 'object_uuid' => 'parent-uuid', 'error' => 'nope'],
			],
			$this->logged[0]
		);
	}

	/**
	 * A parent schema that does not load is logged with the email and the schema.
	 *
	 * @return void
	 */
	public function testAnEmailParentsSchemaThatDoesNotLoadLogsIt(): void {
		$this->registerMapper->method('find')->willReturn(new Register());
		$this->magicMapper->method('find')->willReturn($this->object());
		$this->schemaMapper->method('find')->willThrowException(new RuntimeException('lost'));

		$this->assertSame(403, $this->patch($this->emailRelation())[0]);
		$this->assertSame(
			[
				'warning',
				'[EntityRelationsController] Email-bound auth check could not load parent schema, denying',
				['email_id' => 7, 'schema_id' => 'schema-from-object', 'error' => 'lost'],
			],
			$this->logged[0]
		);
	}

	/**
	 * The decision gets the body without the framework keys; a failure is a logged 500.
	 *
	 * @return void
	 */
	public function testAFailingDecisionIsALogged500AndSeesOnlyTheBody(): void {
		$this->registerMapper->method('find')->willReturn(new Register());
		$this->magicMapper->method('find')->willReturn($this->object());
		$this->schemaMapper->method('find')->willReturn(new Schema());
		$this->permissions->method('hasPermission')->willReturn(true);
		$this->request->method('getParams')->willReturn(['id' => '42', '_route' => 'r', 'bases' => ['b-1']]);
		$this->decisions->expects($this->once())->method('decide')
			->with($this->anything(), ['bases' => ['b-1']], $this->anything())
			->willThrowException(new RuntimeException('db down'));

		$this->assertSame([500, ['error' => 'internal_error']], $this->patch($this->emailRelation()));
		$this->assertSame(
			[['error', '[EntityRelationsController] PATCH failed: db down', ['relation_id' => 42, 'error' => 'db down']]],
			$this->logged
		);
	}
}
