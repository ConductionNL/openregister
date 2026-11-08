<?php

/**
 * OpenRegister Example Set Audit Entries
 *
 * The history an example set gives its objects: a fragment's `auditEntries`
 * part names the object, the action (`create`, `update`, `file.downloaded`
 * and the like), the account that acted, when, and optionally what changed.
 * Each row becomes one audit trail entry by that person, sealed into the hash
 * chain like any other, and marked with the cause `import` and the run
 * `example-set:<app>/<key>`, so a seeded entry can always be told from a real
 * one. An audit trail is history, so remove leaves the entries; a second
 * install finds them by that run and writes nothing.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-give-its-objects-a-history-by-named-people
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

use DateTime;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Party\PartyService;
use OCA\OpenRegister\Service\WriteCause;
use OCP\IUserManager;
use Psr\Container\ContainerInterface;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * Checks, writes and proves the audit entries of an example set.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) Uuid::v4 gives a pre-built audit row the uuid the hash chain requires.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-give-its-objects-a-history-by-named-people
 */
class ExampleSetAuditEntries extends ExampleSetEngineRows {

	/**
	 * The entry fields a fragment may set.
	 */
	public const FIELDS = ['object', 'action', 'user', 'at', 'changed'];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Hands out the audit trail mapper, the object finder and the user manager.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * The fragment and record part entries live in.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-give-its-objects-a-history-by-named-people
	 */
	public function part(): string {
		return 'auditEntries';
	}//end part()

	/**
	 * What one row is called in messages.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-give-its-objects-a-history-by-named-people
	 */
	public function noun(): string {
		return 'audit entry';
	}//end noun()

	/**
	 * Why a row cannot be written, '' when it can.
	 *
	 * @param array<string, mixed> $data  The row's fields, placeholders filled.
	 * @param array<int, string>   $users The accounts the set creates or adopts.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-give-its-objects-a-history-by-named-people
	 */
	public function refusal(array $data, array $users): string {
		$unknown = array_diff(array_keys($data), self::FIELDS);
		if ($unknown !== []) {
			return 'field "'.implode('", "', $unknown).'" is not an audit entry field a set may write';
		}

		$missing = $this->missingRefusal(data: $data);
		if ($missing !== '') {
			return $missing;
		}

		return $this->detailRefusal(data: $data, users: $users);
	}//end refusal()

	/**
	 * Why an entry lacks the object, action, person or moment it needs, or ''.
	 *
	 * @param array<string, mixed> $data The row's fields.
	 *
	 * @return string
	 */
	private function missingRefusal(array $data): string {
		foreach (['object' => 'the object it is on', 'action' => 'an action', 'user' => 'the person who acted', 'at' => 'a moment'] as $field => $what) {
			if (is_string($data[$field] ?? null) === false || trim($data[$field]) === '') {
				return 'an audit entry needs '.$what.' ('.$field.')';
			}
		}

		return '';
	}//end missingRefusal()

	/**
	 * Why an entry's person is no account the set creates or adopts, its changes are no object or its moment no
	 * date and time, or ''.
	 *
	 * @param array<string, mixed> $data  The row's fields, with the fields missingRefusal() asks for.
	 * @param array<int, string>   $users The account ids the set creates or adopts.
	 *
	 * @return string
	 */
	private function detailRefusal(array $data, array $users): string {
		if (in_array((string) $data['user'], $users, true) === false) {
			return '"'.(string) $data['user'].'" is not an account the set creates or adopts';
		}

		if (isset($data['changed']) === true && is_array($data['changed']) === false) {
			return 'changed is an object';
		}

		try {
			new DateTime((string) $data['at']);
		} catch (Throwable) {
			return 'at "'.(string) $data['at'].'" is not a date and time';
		}

		return '';
	}//end detailRefusal()

	/**
	 * Write one audit entry by the named person, or find the one an earlier install wrote.
	 *
	 * @param string               $app  The fragment's app.
	 * @param string               $key  The row key.
	 * @param array<string, mixed> $data The row's fields, placeholders filled.
	 *
	 * @return array{error: string, entry: array{id: int, object: string}|null} The record entry, or why the write failed.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-give-its-objects-a-history-by-named-people
	 */
	public function write(string $app, string $key, array $data): array {
		$objectUuid = (string) $data['object'];
		$object     = $this->container->get(PartyService::class)->findUnscoped(uuid: $objectUuid);
		if ($object === null) {
			return ['error' => 'no object "'.$objectUuid.'" to give the entry', 'entry' => null];
		}

		$mapper = $this->mapper();
		try {
			$id = $this->writeOnce(mapper: $mapper, object: $object, data: $data, run: 'example-set:'.$app.'/'.$key);
		} catch (Throwable $e) {
			return ['error' => $e->getMessage(), 'entry' => null];
		}

		return ['error' => '', 'entry' => ['id' => $id, 'object' => $objectUuid]];
	}//end write()

	/**
	 * The id of the entry this row wrote: the one an earlier install wrote, or one written now.
	 *
	 * @param AuditTrailMapperInterface $mapper The audit trail mapper.
	 * @param ObjectEntity              $object The object.
	 * @param array<string, mixed>      $data   The row's fields.
	 * @param string                    $run    The row's cause run, `example-set:<app>/<key>`.
	 *
	 * @return int
	 */
	private function writeOnce(AuditTrailMapperInterface $mapper, ObjectEntity $object, array $data, string $run): int {
		$id = $this->earlierId(mapper: $mapper, objectUuid: (string) $data['object'], action: (string) $data['action'], run: $run);
		if ($id !== null) {
			return $id;
		}

		return (int) $mapper->insertAuditTrails([$this->trail(object: $object, data: $data, run: $run)])[0]->getId();
	}//end writeOnce()

	/**
	 * The id of the entry an earlier install wrote for this row (the same cause run), or null.
	 *
	 * @param AuditTrailMapperInterface $mapper     The audit trail mapper.
	 * @param string                    $objectUuid The object's uuid.
	 * @param string                    $action     The entry's action.
	 * @param string                    $run        The row's cause run, `example-set:<app>/<key>`.
	 *
	 * @return int|null
	 */
	private function earlierId(AuditTrailMapperInterface $mapper, string $objectUuid, string $action, string $run): ?int {
		foreach ($mapper->findForObjectByAction($objectUuid, [$action], 500) as $earlier) {
			if ($earlier->getCauseRun() === $run) {
				return (int) $earlier->getId();
			}
		}

		return null;
	}//end earlierId()

	/**
	 * The audit entry a row declares, by the named person, as an import of this row.
	 *
	 * @param ObjectEntity         $object The object.
	 * @param array<string, mixed> $data   The row's fields.
	 * @param string               $run    The row's cause run.
	 *
	 * @return AuditTrail
	 */
	private function trail(ObjectEntity $object, array $data, string $run): AuditTrail {
		$user  = (string) $data['user'];
		$trail = $this->trailOn(object: $object, uuid: (string) $data['object']);
		$trail->setAction((string) $data['action']);
		$trail->setChanged((array) ($data['changed'] ?? []));
		$trail->setUser($user);
		$trail->setUserName($this->container->get(IUserManager::class)->get($user)?->getDisplayName() ?? $user);
		$trail->setCreated(new DateTime((string) $data['at']));
		$trail->setCause(WriteCause::IMPORT);
		$trail->setCauseRun($run);

		return $trail;
	}//end trail()

	/**
	 * A new audit entry on the object, with its own uuid.
	 *
	 * @param ObjectEntity $object The object.
	 * @param string       $uuid   The object's uuid.
	 *
	 * @return AuditTrail
	 */
	private function trailOn(ObjectEntity $object, string $uuid): AuditTrail {
		$trail = new AuditTrail();
		$trail->setUuid((string) Uuid::v4());
		$trail->setObjectUuid($uuid);
		$trail->setObject((int) $object->getId());
		$trail->setRegister((int) $object->getRegister());
		$trail->setSchema((int) $object->getSchema());

		return $trail;
	}//end trailOn()

	/**
	 * Whether a written entry is gone.
	 *
	 * @param array<string, mixed> $entry The record entry.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-give-its-objects-a-history-by-named-people
	 */
	public function lost(array $entry): bool {
		try {
			$this->mapper()->find((int) $entry['id']);
		} catch (Throwable) {
			return true;
		}

		return false;
	}//end lost()

	/**
	 * An audit trail is history: remove leaves the entry, sealed in its chain.
	 *
	 * @param array<string, mixed> $entry The record entry.
	 *
	 * @return string `removed`: the record lets go of it.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-give-its-objects-a-history-by-named-people
	 */
	public function revert(array $entry): string {
		return 'removed';
	}//end revert()

	/**
	 * The audit trail mapper.
	 *
	 * @return AuditTrailMapperInterface
	 */
	private function mapper(): AuditTrailMapperInterface {
		return $this->container->get(AuditTrailMapperInterface::class);
	}//end mapper()
}//end class
