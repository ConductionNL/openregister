<?php

/**
 * Golden outcomes of the entity-relation PATCH authorisation.
 *
 * Pins, on the controller as it was before its authorisation helpers were
 * split into small units, which callers the PATCH lets through and which it
 * refuses, for each subject a relation can hang off (file, object, email):
 * every lookup that fails denies, the permission verdict is what decides, and
 * the subject precedence (file, then object, then email) holds. The
 * authorisation runs before the content-type and body are looked at.
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
use OCA\OpenRegister\Service\Decision\DecisionOwnedException;
use OCA\OpenRegister\Service\Decision\DecisionVisibility;
use OCA\OpenRegister\Service\Decision\RelationDecisionService;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Tests\Unit\Controller\Support\EntityRelationsControllerFactory;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden allowed / refused outcomes of EntityRelationsController::update().
 */
class EntityRelationsAccessGoldenTest extends TestCase {

	private IRequest&MockObject $request;

	private EntityRelationMapper&MockObject $relations;

	private IUserSession&MockObject $userSession;

	private IRootFolder&MockObject $rootFolder;

	private PermissionHandler&MockObject $permissions;

	private MagicMapper&MockObject $magicMapper;

	private SchemaMapper&MockObject $schemaMapper;

	private RegisterMapper&MockObject $registerMapper;

	private EmailLinkMapper&MockObject $emailLinks;

	private RelationDecisionService&MockObject $decisions;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->relations = $this->createMock(EntityRelationMapper::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->permissions = $this->createMock(PermissionHandler::class);
		$this->magicMapper = $this->createMock(MagicMapper::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->emailLinks = $this->createMock(EmailLinkMapper::class);
		$this->decisions = $this->createMock(RelationDecisionService::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);
		$this->request->method('getParams')->willReturn(['bases' => ['b-1']]);
		$this->decisions->method('decide')->willReturnCallback(
			static fn (EntityRelation $relation): array => ['relation' => $relation, 'decision' => null]
		);
	}

	/**
	 * The controller over this test's doubles.
	 *
	 * @return EntityRelationsController
	 */
	private function controller(): EntityRelationsController {
		$visibility = $this->createMock(DecisionVisibility::class);
		$visibility->method('present')->willReturnCallback(
			static fn (EntityRelation $relation, string $userId, ?array $serialised = null): array => ($serialised ?? [])
		);

		return EntityRelationsControllerFactory::create(
			'openregister',
			$this->request,
			$this->relations,
			$this->userSession,
			$this->rootFolder,
			$this->permissions,
			$this->magicMapper,
			$this->schemaMapper,
			$this->registerMapper,
			$this->emailLinks,
			$this->createMock(LoggerInterface::class),
			$this->decisions,
			$visibility
		);
	}

	/**
	 * Answer the PATCH for this relation and return the status.
	 *
	 * @param EntityRelation $relation The relation the mapper returns.
	 *
	 * @return int
	 */
	private function patch(EntityRelation $relation): int {
		$this->relations->method('find')->willReturn($relation);

		return $this->controller()->update(42)->getStatus();
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
	 * A relation on an object.
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
	 * A relation on an email.
	 *
	 * @return EntityRelation
	 */
	private function emailRelation(): EntityRelation {
		$relation = new EntityRelation();
		$relation->setEmailId(7);

		return $relation;
	}

	/**
	 * An email link with a parent object.
	 *
	 * @param int $registerId The parent's register id (0 for none).
	 *
	 * @return EmailLink
	 */
	private function emailLink(int $registerId): EmailLink {
		$link = new EmailLink();
		$link->setObjectUuid('parent-uuid');
		$link->setRegisterId($registerId);

		return $link;
	}

	// --- object-bound ---------------------------------------------------

	public function testObjectBoundIsAllowedWhenThePermissionGrantsUpdate(): void {
		$register = new Register();
		$schema = new Schema();
		$object = $this->object();
		$this->registerMapper->expects($this->once())->method('find')->with('reg-1')->willReturn($register);
		$this->schemaMapper->expects($this->exactly(2))->method('find')->with('sch-1')->willReturn($schema);
		$this->magicMapper->expects($this->once())->method('find')
			->with(99, $register, $schema)->willReturn($object);
		$this->permissions->expects($this->once())->method('hasPermission')
			->with($schema, 'update', 'alice', 'bob', true, $object)->willReturn(true);

		$this->assertSame(200, $this->patch($this->objectRelation('reg-1', 'sch-1')));
	}

	public function testObjectBoundIsRefusedWhenThePermissionDeniesUpdate(): void {
		$this->registerMapper->method('find')->willReturn(new Register());
		$this->schemaMapper->method('find')->willReturn(new Schema());
		$this->magicMapper->method('find')->willReturn($this->object());
		$this->permissions->method('hasPermission')->willReturn(false);
		$this->decisions->expects($this->never())->method('decide');

		$this->assertSame(403, $this->patch($this->objectRelation('reg-1', 'sch-1')));
	}

	public function testObjectBoundWithoutRegisterOrSchemaChecksTheObjectsOwnSchema(): void {
		$schema = new Schema();
		$this->registerMapper->expects($this->never())->method('find');
		$this->magicMapper->expects($this->once())->method('find')->with(99, null, null)->willReturn($this->object());
		$this->schemaMapper->expects($this->once())->method('find')->with('schema-from-object')->willReturn($schema);
		$this->permissions->expects($this->once())->method('hasPermission')
			->with($schema, 'update', 'alice', 'bob')->willReturn(true);

		$this->assertSame(200, $this->patch($this->objectRelation(null, null)));
	}

	public function testObjectBoundIsRefusedWhenTheRegisterDoesNotResolve(): void {
		$this->registerMapper->method('find')->willThrowException(new DoesNotExistException('x'));
		$this->permissions->expects($this->never())->method('hasPermission');

		$this->assertSame(403, $this->patch($this->objectRelation('reg-1', 'sch-1')));
	}

	public function testObjectBoundIsRefusedWhenTheObjectDoesNotResolve(): void {
		$this->schemaMapper->method('find')->willReturn(new Schema());
		$this->magicMapper->method('find')->willThrowException(new DoesNotExistException('x'));
		$this->permissions->expects($this->never())->method('hasPermission');

		$this->assertSame(403, $this->patch($this->objectRelation(null, 'sch-1')));
	}

	public function testObjectBoundIsRefusedWhenTheSchemaDoesNotLoad(): void {
		$this->magicMapper->method('find')->willReturn($this->object());
		$this->schemaMapper->method('find')->willThrowException(new \RuntimeException('gone'));
		$this->permissions->expects($this->never())->method('hasPermission');

		$this->assertSame(403, $this->patch($this->objectRelation(null, null)));
	}

	// --- email-bound ----------------------------------------------------

	public function testEmailBoundIsAllowedWhenThePermissionGrantsUpdateOnTheParent(): void {
		$register = new Register();
		$schema = new Schema();
		$object = $this->object();
		$this->emailLinks->expects($this->once())->method('find')->with(7)->willReturn($this->emailLink(5));
		$this->registerMapper->expects($this->once())->method('find')->with('5')->willReturn($register);
		$this->magicMapper->expects($this->once())->method('find')->with('parent-uuid', $register)->willReturn($object);
		$this->schemaMapper->expects($this->once())->method('find')->with('schema-from-object')->willReturn($schema);
		$this->permissions->expects($this->once())->method('hasPermission')
			->with($schema, 'update', 'alice', 'bob', true, $object)->willReturn(true);

		$this->assertSame(200, $this->patch($this->emailRelation()));
	}

	public function testEmailBoundIsRefusedWhenThePermissionDeniesUpdateOnTheParent(): void {
		$this->emailLinks->method('find')->willReturn($this->emailLink(5));
		$this->registerMapper->method('find')->willReturn(new Register());
		$this->magicMapper->method('find')->willReturn($this->object());
		$this->schemaMapper->method('find')->willReturn(new Schema());
		$this->permissions->method('hasPermission')->willReturn(false);
		$this->decisions->expects($this->never())->method('decide');

		$this->assertSame(403, $this->patch($this->emailRelation()));
	}

	public function testEmailBoundWithoutAParentRegisterLooksUpTheParentAlone(): void {
		$this->emailLinks->method('find')->willReturn($this->emailLink(0));
		$this->registerMapper->expects($this->never())->method('find');
		$this->magicMapper->expects($this->once())->method('find')->with('parent-uuid', null)->willReturn($this->object());
		$this->schemaMapper->method('find')->willReturn(new Schema());
		$this->permissions->method('hasPermission')->willReturn(true);

		$this->assertSame(200, $this->patch($this->emailRelation()));
	}

	public function testEmailBoundIsRefusedWhenTheEmailLinkDoesNotLoad(): void {
		$this->emailLinks->method('find')->willThrowException(new DoesNotExistException('x'));
		$this->permissions->expects($this->never())->method('hasPermission');

		$this->assertSame(403, $this->patch($this->emailRelation()));
	}

	public function testEmailBoundIsRefusedWhenTheParentDoesNotResolve(): void {
		$this->emailLinks->method('find')->willReturn($this->emailLink(5));
		$this->registerMapper->method('find')->willReturn(new Register());
		$this->magicMapper->method('find')->willThrowException(new DoesNotExistException('x'));
		$this->permissions->expects($this->never())->method('hasPermission');

		$this->assertSame(403, $this->patch($this->emailRelation()));
	}

	public function testEmailBoundIsRefusedWhenTheParentSchemaDoesNotLoad(): void {
		$this->emailLinks->method('find')->willReturn($this->emailLink(0));
		$this->magicMapper->method('find')->willReturn($this->object());
		$this->schemaMapper->method('find')->willThrowException(new \RuntimeException('gone'));
		$this->permissions->expects($this->never())->method('hasPermission');

		$this->assertSame(403, $this->patch($this->emailRelation()));
	}

	// --- file-bound and precedence --------------------------------------

	public function testFileBoundIsAllowedWhenTheFileIsUpdateable(): void {
		$file = $this->createMock(File::class);
		$file->method('isUpdateable')->willReturn(true);
		$folder = $this->createMock(Folder::class);
		$folder->method('getById')->with(3)->willReturn([$file]);
		$this->rootFolder->method('getUserFolder')->with('alice')->willReturn($folder);

		$relation = new EntityRelation();
		$relation->setFileId(3);

		$this->assertSame(200, $this->patch($relation));
	}

	public function testFileBoundIsRefusedWhenTheNodeIsNoFile(): void {
		$folderNode = $this->createMock(Folder::class);
		$folderNode->method('isUpdateable')->willReturn(true);
		$folder = $this->createMock(Folder::class);
		$folder->method('getById')->willReturn([$folderNode]);
		$this->rootFolder->method('getUserFolder')->willReturn($folder);

		$relation = new EntityRelation();
		$relation->setFileId(3);

		$this->assertSame(403, $this->patch($relation));
	}

	public function testFileBoundIsRefusedWhenTheUserFolderThrows(): void {
		$this->rootFolder->method('getUserFolder')->willThrowException(new \RuntimeException('no folder'));

		$relation = new EntityRelation();
		$relation->setFileId(3);

		$this->assertSame(403, $this->patch($relation));
	}

	public function testAFileSubjectWinsOverAnObjectSubject(): void {
		$file = $this->createMock(File::class);
		$file->method('isUpdateable')->willReturn(false);
		$folder = $this->createMock(Folder::class);
		$folder->method('getById')->willReturn([$file]);
		$this->rootFolder->method('getUserFolder')->willReturn($folder);
		$this->permissions->method('hasPermission')->willReturn(true);
		$this->magicMapper->expects($this->never())->method('find');

		$relation = $this->objectRelation(null, null);
		$relation->setFileId(3);

		$this->assertSame(403, $this->patch($relation));
	}

	public function testAZeroObjectIdFallsThroughToTheEmailSubject(): void {
		$this->emailLinks->expects($this->once())->method('find')->willThrowException(new DoesNotExistException('x'));
		$this->magicMapper->expects($this->never())->method('find');

		$relation = $this->emailRelation();
		$relation->setObjectId(0);
		$relation->setFileId(0);

		$this->assertSame(403, $this->patch($relation));
	}

	// --- order of checks and decision outcomes --------------------------

	public function testARefusedCallerIsRefusedBeforeTheContentTypeIsLookedAt(): void {
		$this->request->expects($this->never())->method('getHeader');

		$this->assertSame(403, $this->patch(new EntityRelation()));
	}

	public function testAnAllowedCallerSendingNoJsonGets415(): void {
		$this->request->method('getHeader')->willReturn('text/plain');
		$this->emailLinks->method('find')->willReturn($this->emailLink(0));
		$this->magicMapper->method('find')->willReturn($this->object());
		$this->schemaMapper->method('find')->willReturn(new Schema());
		$this->permissions->method('hasPermission')->willReturn(true);
		$this->decisions->expects($this->never())->method('decide');

		$this->assertSame(415, $this->patch($this->emailRelation()));
	}

	public function testADecisionOwnedBySomeoneElseIs403WithTheRefusal(): void {
		$file = $this->createMock(File::class);
		$file->method('isUpdateable')->willReturn(true);
		$folder = $this->createMock(Folder::class);
		$folder->method('getById')->willReturn([$file]);
		$this->rootFolder->method('getUserFolder')->willReturn($folder);
		$relation = new EntityRelation();
		$relation->setFileId(3);
		$this->relations->method('find')->willReturn($relation);
		$decisions = $this->createMock(RelationDecisionService::class);
		$decisions->method('decide')->willThrowException(
			new DecisionOwnedException(['error' => 'decision-owned', 'decidedBy' => 'bob'])
		);
		$this->decisions = $decisions;

		$response = $this->controller()->update(42);

		$this->assertSame(403, $response->getStatus());
		$this->assertSame(['error' => 'decision-owned', 'decidedBy' => 'bob'], $response->getData());
	}
}
