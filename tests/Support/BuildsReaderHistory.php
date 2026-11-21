<?php

/**
 * Build the real reader path of one object's history for a unit test.
 *
 * A surface that shows an object's audit entries is safe only if what it
 * shows is what this path gives the caller. Doubling the path would make the
 * test unable to see a hidden property or a session leaking, so the suites
 * build the real ObjectHistoryForReaders over the real HistoryEntryRedactor
 * and PropertyRbacHandler. Only the object read (ObjectReadAccess), the trail
 * (AuditTrailMapper), the register lookup and the doelbinding guard are
 * stubbed, from the world the test describes.
 *
 * The schema of every object has `titel`, readable by everyone, and
 * `interneAantekening`, readable by the `redactie` group only.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Support;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Audit\HistoryEntryRedactor;
use OCA\OpenRegister\Service\Audit\ObjectHistoryForReaders;
use OCA\OpenRegister\Service\Audit\ObjectHistoryServices;
use OCA\OpenRegister\Service\Audit\PurposeGuard;
use OCA\OpenRegister\Service\Audit\PurposeRefusedException;
use OCA\OpenRegister\Service\Audit\ReadEventVisibility;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Object\ObjectReadAccess;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCA\OpenRegister\Tests\Support\AuditTrailListing;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * One builder for the reader path the audit surfaces answer through.
 */
trait BuildsReaderHistory {
	use BuildsStateFieldRuleResolver;

	/**
	 * The uuid of the object the caller may read in the described world.
	 *
	 * @var string
	 */
	protected static string $readableUuid = 'obj-readable';

	/**
	 * An entry on one object, with a hidden property and the instance fields set.
	 *
	 * @param string $objectUuid The object the entry belongs to.
	 * @param string $action     The action recorded.
	 *
	 * @return AuditTrail The entry.
	 */
	protected function readerEntry(string $objectUuid, string $action = 'update'): AuditTrail {
		$entry = new AuditTrail();
		$entry->setUuid('audit-' . $objectUuid . '-' . $action);
		$entry->setObjectUuid($objectUuid);
		$entry->setAction($action);
		$entry->setUser('alice');
		$entry->setUserName('Alice');
		$entry->setSchema(7);
		$entry->setCreated(new \DateTime('2026-11-03T10:00:00+00:00'));
		$entry->setChanged([
			'titel' => ['old' => 'Oud', 'new' => 'Nieuw'],
			'interneAantekening' => ['old' => 'klokkenluider', 'new' => 'geheim'],
		]);
		$entry->setSession('sess-other');
		$entry->setRequest('req-1');
		$entry->setIpAddress('203.0.113.9');

		return $entry;
	}//end readerEntry()

	/**
	 * The real reader path over a described world.
	 *
	 * @param array<AuditTrail> $rows  The entries the trail holds for the object.
	 * @param array             $world `readable` (bool), `groups` (string[]), `purposeRefused` (bool).
	 *
	 * @return ObjectHistoryForReaders The history reader.
	 */
	protected function readerHistory(array $rows, array $world = []): ObjectHistoryForReaders {
		$groups = ($world['groups'] ?? ['staff']);
		$object = new ObjectEntity();
		$object->setUuid(self::$readableUuid);
		$object->setRegister('5');
		$object->setSchema('7');
		$object->setObject(['titel' => 'Nieuw', 'interneAantekening' => 'geheim']);

		$access = $this->createMock(ObjectReadAccess::class);
		$verdict = null;
		if (($world['readable'] ?? true) === true) {
			$verdict = $object;
		}

		$access->method('readableOrAmbiguous')->willReturnCallback(
			static fn (string $register, string $schema, string $id): ?ObjectEntity => ($id === self::$readableUuid) ? $verdict : null
		);
		$access->method('readable')->willReturnCallback(
			static fn (string $register, string $schema, string $id): ?ObjectEntity => ($id === self::$readableUuid) ? $verdict : null
		);
		$access->method('schemaOf')->willReturn($this->readerSchema());

		$mapper = $this->createMock(AuditTrailMapper::class);
		$mapper->method('findTrail')->willReturnCallback(AuditTrailListing::positional(
			static function (...$args) use ($rows): array {
				$filters = ($args['filters'] ?? $args[2] ?? []);
				if (($filters['object_uuid'] ?? null) !== self::$readableUuid) {
					return [];
				}

				return $rows;
			}
		));

		$register = new Register();
		$register->setId(5);
		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturn($register);

		$guard = $this->createMock(PurposeGuard::class);
		if (($world['purposeRefused'] ?? false) === true) {
			$guard->method('enforce')->willThrowException(
				new PurposeRefusedException(rule: 'purpose-required', reason: 'Name a purpose.')
			);
		}

		$reads = $this->createMock(ReadEventVisibility::class);
		$reads->method('maySeeReadEvents')->willReturn(true);
		$reads->method('filter')->willReturnArgument(0);

		return new ObjectHistoryForReaders(
			$access,
			new ObjectHistoryServices(
				$mapper,
				new HistoryEntryRedactor($this->readerProperties(groups: $groups)),
				$registerMapper
			),
			$guard,
			$reads
		);
	}//end readerHistory()

	/**
	 * The schema of every object in the described world.
	 *
	 * @return Schema The schema.
	 */
	private function readerSchema(): Schema {
		$schema = new Schema();
		$schema->setId(7);
		$schema->setProperties([
			'titel' => ['type' => 'string'],
			'interneAantekening' => [
				'type' => 'string',
				'authorization' => ['read' => [['group' => 'redactie']]],
			],
		]);

		return $schema;
	}//end readerSchema()

	/**
	 * The real property rules for a caller in the given groups.
	 *
	 * @param array<int, string> $groups The caller's groups.
	 *
	 * @return PropertyRbacHandler The property rules.
	 */
	private function readerProperties(array $groups): PropertyRbacHandler {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(in_array('admin', $groups, true));
		$groupManager->method('getUserGroupIds')->willReturn($groups);
		$groupManager->method('isInGroup')->willReturnCallback(
			static fn (string $uid, string $group): bool => in_array($group, $groups, true)
		);

		return new PropertyRbacHandler(caller: new \OCA\OpenRegister\Service\PropertyRbacCaller($session, $groupManager), rules: new \OCA\OpenRegister\Service\PropertyRbacRules($this->createMock(ConditionMatcher::class), self::stateFieldRuleResolver($session, $groupManager)), logger: $this->createMock(LoggerInterface::class));
	}//end readerProperties()
}//end trait
