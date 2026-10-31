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
use OCA\OpenRegister\Db\AuditTrailMapper;
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

		foreach (['object' => 'the object it is on', 'action' => 'an action', 'user' => 'the person who acted', 'at' => 'a moment'] as $field => $what) {
			if (is_string($data[$field] ?? null) === false || trim($data[$field]) === '') {
				return 'an audit entry needs '.$what.' ('.$field.')';
			}
		}

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
	}//end refusal()

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

		$run    = 'example-set:'.$app.'/'.$key;
		$mapper = $this->mapper();
		try {
			foreach ($mapper->findForObjectByAction($objectUuid, [(string) $data['action']], 500) as $earlier) {
				if ($earlier->getCauseRun() === $run) {
					return ['error' => '', 'entry' => ['id' => (int) $earlier->getId(), 'object' => $objectUuid]];
				}
			}

			$user  = (string) $data['user'];
			$trail = new AuditTrail();
			$trail->setUuid((string) Uuid::v4());
			$trail->setObjectUuid($objectUuid);
			$trail->setObject((int) $object->getId());
			$trail->setRegister((int) $object->getRegister());
			$trail->setSchema((int) $object->getSchema());
			$trail->setAction((string) $data['action']);
			$trail->setChanged((array) ($data['changed'] ?? []));
			$trail->setUser($user);
			$trail->setUserName($this->container->get(IUserManager::class)->get($user)?->getDisplayName() ?? $user);
			$trail->setCreated(new DateTime((string) $data['at']));
			$trail->setCause(WriteCause::IMPORT);
			$trail->setCauseRun($run);
			$written = $mapper->insertAuditTrails([$trail])[0];
		} catch (Throwable $e) {
			return ['error' => $e->getMessage(), 'entry' => null];
		}

		return ['error' => '', 'entry' => ['id' => (int) $written->getId(), 'object' => $objectUuid]];
	}//end write()

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
	 * @return AuditTrailMapper
	 */
	private function mapper(): AuditTrailMapper {
		return $this->container->get(AuditTrailMapper::class);
	}//end mapper()
}//end class
