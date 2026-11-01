<?php

/**
 * DeleteObject::delete() resolves, soft- or hard-deletes, invalidates and audits exactly as recorded.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use DateTime;
use DateTimeImmutable;
use Exception;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Deletion\DeletionWindowService;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Object\DeleteObject;
use OCA\OpenRegister\Service\Object\ReferentialIntegrityService;
use OCA\OpenRegister\Service\SettingsService;
use OCP\Files\Folder;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden record of delete() over every branch: the lookup, the deleted metadata written for the
 * acting user, the mapper writes, the cache invalidations, the audit entries, the folder removal,
 * the log lines and the result or exception. The acting user always comes from the session; the
 * organisation lookup answers only for that user. Set OR_GOLDEN_RECORD=1 to rewrite the fixture.
 *
 * @covers \OCA\OpenRegister\Service\Object\DeleteObject
 * @uses   \OCA\OpenRegister\Db\AuditTrail
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Service\Object\DraftRemovalRecord
 * @uses   \OCA\OpenRegister\Service\Object\DeletionTarget
 * @uses   \OCA\OpenRegister\Service\WritePhaseProbe
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 */
class DeleteObjectDeleteGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/delete-object-delete-golden.json';

	private const ACTING_USER = 'alice';

	/**
	 * Every scenario matches the recorded golden.
	 *
	 * @return void
	 */
	public function testScenariosMatchTheGolden(): void {
		$actual = [
			'array id, soft'                  => $this->scenario(input: ['id' => 'obj-1']),
			'array @self.id, soft'            => $this->scenario(input: ['@self' => ['id' => 'obj-1']]),
			'array uuid, soft'                => $this->scenario(input: ['uuid' => 'obj-1']),
			'array without identifier'        => $this->scenario(input: ['name' => 'x']),
			'entity without context'          => $this->scenario(input: 'entity'),
			'entity with register only'       => $this->scenario(input: 'entity', passRegister: true),
			'entity with full context'        => $this->scenario(input: 'entity', passRegister: true, passSchema: true),
			'forged deleter in data'          => $this->scenario(input: ['id' => 'obj-1', 'deletedBy' => 'mallory', 'owner' => 'mallory', 'userId' => 'mallory', 'requestedBy' => 'mallory', '@self' => ['owner' => 'mallory']]),
			'forged deleter on looked-up data' => $this->scenario(input: ['id' => 'obj-1'], forged: true),
			'forged deleter on passed entity'  => $this->scenario(input: 'entity', passRegister: true, passSchema: true, forged: true),
			'id wins over uuid'                => $this->scenario(input: ['id' => 'obj-1', 'uuid' => 'obj-2']),
			'@self.id wins over uuid'          => $this->scenario(input: ['@self' => ['id' => 'obj-1'], 'uuid' => 'obj-2']),
			'session user without org'        => $this->scenario(input: ['id' => 'obj-1'], user: 'bob'),
			'no session user'                 => $this->scenario(input: ['id' => 'obj-1'], user: null),
			'org lookup throws error'         => $this->scenario(input: ['id' => 'obj-1'], orgFails: true),
			'with deletion window'            => $this->scenario(input: ['id' => 'obj-1'], window: true),
			'cache invalidation throws'       => $this->scenario(input: ['id' => 'obj-1'], cacheFails: true),
			'audit disabled'                  => $this->scenario(input: ['id' => 'obj-1'], audit: false),
			'audit setting throws'            => $this->scenario(input: ['id' => 'obj-1'], audit: null),
			'cascade with action type'        => $this->scenario(input: ['id' => 'obj-1'], cascade: ['action_type' => 'referential_integrity.set_null', 'triggerObject' => 't-1']),
			'cascade without action type'     => $this->scenario(input: ['id' => 'obj-1'], cascade: ['triggerObject' => 't-1']),
			'non-numeric ids, soft'           => $this->scenario(input: ['id' => 'obj-1'], ids: ['reg-a', 'sch-a']),
			'null ids, soft'                  => $this->scenario(input: ['id' => 'obj-1'], ids: [null, null]),
			'update throws'                   => $this->scenario(input: ['id' => 'obj-1'], updateFails: true),
			'permanent'                       => $this->scenario(input: ['id' => 'obj-1'], permanent: true),
			'permanent entity, full context'  => $this->scenario(input: 'entity', permanent: true, passRegister: true, passSchema: true),
			'permanent draft'                 => $this->scenario(input: ['id' => 'obj-1'], permanent: true, draft: true),
			'permanent non-numeric ids'       => $this->scenario(input: ['id' => 'obj-1'], permanent: true, ids: ['reg-a', null]),
			'permanent with folder'           => $this->scenario(input: ['id' => 'obj-1'], permanent: true, folder: 'ok'),
			'permanent folder unresolved'     => $this->scenario(input: ['id' => 'obj-1'], permanent: true, folder: 'none'),
			'permanent folder throws'         => $this->scenario(input: ['id' => 'obj-1'], permanent: true, folder: 'throws'),
			'permanent no folder bound'       => $this->scenario(input: ['id' => 'obj-1'], permanent: true, folder: 'unbound'),
			'permanent ignores session'       => $this->scenario(input: ['id' => 'obj-1'], permanent: true, user: 'bob', window: true, cascade: ['action_type' => 'x']),
		];

		if (getenv('OR_GOLDEN_RECORD') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		$this->assertSame(expected: $expected, actual: json_decode((string) json_encode($actual, JSON_UNESCAPED_SLASHES), true));
	}//end testScenariosMatchTheGolden()

	/**
	 * Run delete() once and record what it did.
	 *
	 * @param array<string, mixed>|string $input        The array input, or 'entity' to pass the stored entity.
	 * @param string|null                 $user         The session user, or null for no session.
	 * @param bool                        $permanent    Whether to delete permanently.
	 * @param bool                        $passRegister Whether to pass the register context.
	 * @param bool                        $passSchema   Whether to pass the schema context.
	 * @param bool                        $orgFails     Whether the organisation lookup throws.
	 * @param bool                        $window       Whether a deletion window service is wired.
	 * @param bool                        $cacheFails   Whether cache invalidation throws.
	 * @param bool|null                   $audit        Audit setting; null makes the settings call throw.
	 * @param array<string, mixed>|null   $cascade      The cascade context.
	 * @param array<int, string|null>     $ids          The stored register and schema ids.
	 * @param bool                        $updateFails  Whether the soft-delete update throws.
	 * @param bool                        $draft        Whether the stored object is a draft.
	 * @param string|null                 $folder       Folder behaviour: ok, none, throws, unbound; null wires no file service.
	 * @param bool                        $forged       Whether the stored object data and owner name another user.
	 *
	 * @return array<string, mixed>
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList)
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 */
	private function scenario(
		array|string $input,
		?string $user = self::ACTING_USER,
		bool $permanent = false,
		bool $passRegister = false,
		bool $passSchema = false,
		bool $orgFails = false,
		bool $window = false,
		bool $cacheFails = false,
		?bool $audit = true,
		?array $cascade = null,
		array $ids = ['5', '9'],
		bool $updateFails = false,
		bool $draft = false,
		?string $folder = null,
		bool $forged = false,
	): array {
		$calls = [];

		$stored = new ObjectEntity();
		$stored->setUuid('obj-1');
		$stored->setRegister($ids[0]);
		$stored->setSchema($ids[1]);
		$stored->setObject(['title' => 'kept']);
		if ($draft === true) {
			$stored->setStatus(ObjectEntity::STATUS_DRAFT);
		}

		if ($folder !== null && $folder !== 'unbound') {
			$stored->setFolder('folder-1');
		}

		$lookedUp = new ObjectEntity();
		$lookedUp->setUuid('obj-1');
		$lookedUp->setRegister($ids[0]);
		$lookedUp->setSchema($ids[1]);
		if ($draft === true) {
			$lookedUp->setStatus(ObjectEntity::STATUS_DRAFT);
		}

		if ($folder !== null && $folder !== 'unbound') {
			$lookedUp->setFolder('folder-1');
		}

		if ($forged === true) {
			$forgedData = ['title' => 'kept', 'deletedBy' => 'mallory', 'owner' => 'mallory', 'userId' => 'mallory', '@self' => ['owner' => 'mallory', 'deleted' => ['deletedBy' => 'mallory']]];
			$stored->setObject($forgedData);
			$stored->setOwner('mallory');
			$lookedUp->setObject($forgedData);
			$lookedUp->setOwner('mallory');
		}

		$foundRegister = new Register();
		$foundRegister->setId(5);
		$foundSchema = new Schema();
		$foundSchema->setId(9);
		$passedRegister = new Register();
		$passedRegister->setId(55);
		$passedSchema = new Schema();
		$passedSchema->setId(99);

		$mapper = $this->createMock(MagicMapper::class);
		$mapper->method('findAcrossAllSources')->willReturnCallback(
			function (string|int $identifier, bool $includeDeleted = false, bool $_rbac = true, bool $_multitenancy = true) use (&$calls, $lookedUp, $foundRegister, $foundSchema): array {
				$calls[] = ['find' => $identifier, 'includeDeleted' => $includeDeleted, 'rbac' => $_rbac, 'multitenancy' => $_multitenancy];
				return ['object' => $lookedUp, 'register' => $foundRegister, 'schema' => $foundSchema];
			}
		);
		$mapper->method('update')->willReturnCallback(
			function (ObjectEntity $entity, ?Register $register = null, ?Schema $schema = null, ?ObjectEntity $oldEntity = null) use (&$calls, $updateFails, $lookedUp, $stored): ObjectEntity {
				$calls[] = [
					'update'     => $this->entityFacts($entity, $lookedUp, $stored),
					'register'   => $register?->getId(),
					'schema'     => $schema?->getId(),
					'old'        => $oldEntity === null ? null : $this->entityFacts($oldEntity, $lookedUp, $stored),
					'oldIsEntity' => $oldEntity === $entity,
				];
				if ($updateFails === true) {
					throw new Exception('update failed');
				}

				return $entity;
			}
		);
		$mapper->method('deleteObjectEntity')->willReturnCallback(
			function (ObjectEntity $entity, Register $register, Schema $schema, bool $hardDelete = false) use (&$calls, $lookedUp, $stored): ObjectEntity {
				$calls[] = ['hardDelete' => $this->entityFacts($entity, $lookedUp, $stored), 'register' => $register->getId(), 'schema' => $schema->getId(), 'hard' => $hardDelete];
				return $entity;
			}
		);

		$cache = $this->createMock(CacheHandler::class);
		$cache->method('invalidateForObjectChange')->willReturnCallback(
			function (?ObjectEntity $object = null, string $operation = 'unknown', ?int $registerId = null, ?int $schemaId = null) use (&$calls, $cacheFails, $lookedUp, $stored): void {
				$calls[] = ['invalidate' => $operation, 'object' => $object === null ? null : $this->entityFacts($object, $lookedUp, $stored), 'registerId' => $registerId, 'schemaId' => $schemaId];
				if ($cacheFails === true) {
					throw new Exception('cache down');
				}
			}
		);

		$session = $this->createMock(IUserSession::class);
		$sessionUser = null;
		if ($user !== null) {
			$sessionUser = $this->createMock(IUser::class);
			$sessionUser->method('getUID')->willReturn($user);
		}

		$session->method('getUser')->willReturn($sessionUser);

		$auditMapper = $this->createMock(AuditTrailMapper::class);
		$auditMapper->method('createAuditTrail')->willReturnCallback(
			function (?ObjectEntity $old = null, ?ObjectEntity $new = null, ?string $action = null, ?array $cascadeContext = null) use (&$calls, $lookedUp, $stored): AuditTrail {
				$calls[] = ['audit' => $action, 'old' => $old === null ? null : $this->entityFacts($old, $lookedUp, $stored), 'new' => $new?->getUuid(), 'cascadeContext' => $cascadeContext];
				return new AuditTrail();
			}
		);
		$auditMapper->method('createAuditTrailEntry')->willReturnCallback(
			function (ObjectEntity $object, string $action, array $context = [], ?string $actorId = null, ?string $actorName = null) use (&$calls): AuditTrail {
				$calls[] = ['auditEntry' => $action, 'object' => $object->getUuid(), 'context' => $context, 'actorId' => $actorId];
				return new AuditTrail();
			}
		);

		$settings = $this->createMock(SettingsService::class);
		if ($audit === null) {
			$settings->method('getRetentionSettingsOnly')->willThrowException(new Exception('settings down'));
		} else {
			$settings->method('getRetentionSettingsOnly')->willReturn(['auditTrailsEnabled' => $audit]);
		}

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning', 'error', 'debug'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string|\Stringable $message, array $context = []) use (&$calls, $level): void {
					unset($context['file'], $context['line']);
					$calls[] = ['log' => $level, 'message' => (string) $message, 'context' => $context];
				}
			);
		}

		$organisations = $this->createMock(OrganisationMapper::class);
		$organisations->method('getActiveOrganisationWithFallback')->willReturnCallback(
			function (string $userId) use (&$calls, $orgFails): ?string {
				$calls[] = ['organisationFor' => $userId];
				if ($orgFails === true) {
					throw new \Error('no db');
				}

				if ($userId === self::ACTING_USER) {
					return 'org-of-alice';
				}

				return null;
			}
		);

		$fileService = null;
		if ($folder !== null) {
			$fileService = $this->createMock(FileService::class);
			$fileService->method('getObjectFolder')->willReturnCallback(
				function (ObjectEntity|string $objectEntity) use (&$calls, $folder): ?Folder {
					$calls[] = ['getObjectFolder' => $objectEntity instanceof ObjectEntity ? $objectEntity->getUuid() : $objectEntity];
					if ($folder === 'throws') {
						throw new Exception('folder gone');
					}

					if ($folder === 'none') {
						return null;
					}

					$node = $this->createMock(Folder::class);
					$node->method('delete')->willReturnCallback(
						function () use (&$calls): void {
							$calls[] = ['folderDeleted' => true];
						}
					);
					return $node;
				}
			);
		}

		$windowService = null;
		if ($window === true) {
			$windowService = $this->createMock(DeletionWindowService::class);
			$windowService->method('openWindow')->willReturnCallback(
				function (?Schema $schema, ?DateTimeImmutable $deletedAt = null) use (&$calls): array {
					$calls[] = ['openWindow' => $schema?->getId(), 'deletedAtGiven' => $deletedAt !== null];
					return ['retentionDays' => 30, 'deletedBy' => 'window-says'];
				}
			);
		}

		$handler = new DeleteObject(
			$mapper,
			$cache,
			$session,
			$auditMapper,
			$settings,
			$logger,
			$this->createMock(ReferentialIntegrityService::class),
			$this->createMock(IDBConnection::class),
			$organisations,
			$fileService,
			null,
			null,
			$windowService
		);

		$object = $input === 'entity' ? $stored : $input;
		$result = null;
		$exception = null;
		try {
			$result = $handler->delete(
				object: $object,
				cascadeContext: $cascade,
				permanent: $permanent,
				register: $passRegister === true ? $passedRegister : null,
				schema: $passSchema === true ? $passedSchema : null
			);
		} catch (Exception $e) {
			$exception = [get_class($e), $e->getMessage()];
		}

		return [
			'result'    => $result,
			'exception' => $exception,
			'calls'     => $calls,
			'stored'    => $this->entityFacts($stored, $lookedUp, $stored),
			'lookedUp'  => $this->entityFacts($lookedUp, $lookedUp, $stored),
		];
	}//end scenario()

	/**
	 * The facts of an entity that delete() may change, with the deletion moment normalised.
	 *
	 * @param ObjectEntity $entity   The entity.
	 * @param ObjectEntity $lookedUp The entity the lookup returns.
	 * @param ObjectEntity $stored   The entity the caller passed.
	 *
	 * @return array<string, mixed>
	 */
	private function entityFacts(ObjectEntity $entity, ObjectEntity $lookedUp, ObjectEntity $stored): array {
		$deleted = $entity->getDeleted();
		if (is_array($deleted) === true && isset($deleted['deletedAt']) === true) {
			$parsed = DateTime::createFromFormat(DateTime::ATOM, (string) $deleted['deletedAt']);
			$deleted['deletedAt'] = $parsed === false ? 'NOT ATOM: ' . $deleted['deletedAt'] : 'ATOM';
		}

		$which = 'other';
		if ($entity === $lookedUp) {
			$which = 'lookedUp';
		} else if ($entity === $stored) {
			$which = 'stored';
		}

		return ['which' => $which, 'uuid' => $entity->getUuid(), 'deleted' => $deleted, 'object' => $entity->getObject()];
	}//end entityFacts()
}//end class
