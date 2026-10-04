<?php

/**
 * Create or update one record by a key the schema declares unique.
 *
 * `POST /api/objects/{register}/{schema}?_upsertOn=<constraint>` names one of
 * the schema's `refuse` uniqueness constraints (the legacy `unique` key
 * included, named by its properties joined with `+`). The handler reads the
 * key's values from the body, looks the record up with the import's
 * MatchResolver under the caller's own RBAC and organisation, and decides:
 * nothing found, create; one found, update that one; more, refuse with the
 * matches.
 *
 * The lookup and the save are two operations, so one exclusive lock per key
 * (a hash of the constraint and its values) is held around both: a second
 * call with the same key finds the record the first one created.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/api-upsert-on-a-declared-key/specs/objects-crud/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\HookStoppedException;
use OCA\OpenRegister\Listener\UniqueConstraintListener;
use OCA\OpenRegister\Service\Import\MatchLookupFailedException;
use OCA\OpenRegister\Service\Import\MatchResolver;
use OCA\OpenRegister\Service\Schemas\UniqueConstraintEvaluator;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

/**
 * Decides create, update or refuse for an upsert on a declared key.
 */
class UpsertOnKeyHandler {

	/**
	 * How often to try for a key lock another call holds.
	 */
	private const LOCK_ATTEMPTS = 50;

	/**
	 * Wait between two lock attempts, in microseconds (50 x 0.1 s = 5 s).
	 */
	private const LOCK_WAIT_US = 100000;

	/**
	 * Constructor.
	 *
	 * @param UniqueConstraintEvaluator $evaluator       Reads the schema's uniqueness constraints.
	 * @param MatchResolver             $matchResolver   The import's capped, RBAC-scoped key lookup.
	 * @param ILockingProvider          $lockingProvider Nextcloud's lock provider (memcache or database).
	 *
	 * @spec openspec/changes/api-upsert-on-a-declared-key/specs/objects-crud/spec.md
	 */
	public function __construct(
		private readonly UniqueConstraintEvaluator $evaluator,
		private readonly MatchResolver $matchResolver,
		private readonly ILockingProvider $lockingProvider,
	) {
	}//end __construct()

	/**
	 * Create or update the record the key names, or refuse.
	 *
	 * @param string                      $constraintName The `_upsertOn` value: a refuse constraint's name.
	 * @param array<string, mixed>        $object         The request body, control keys stripped.
	 * @param Register                    $register       The register.
	 * @param Schema                      $schema         The schema.
	 * @param callable(?string): ObjectEntity $save       Saves the body; null creates, a uuid updates that record.
	 *
	 * @return array{created: bool, object: ObjectEntity} Whether a record was created, and the saved record.
	 *
	 * @throws UpsertOnKeyException When the upsert is refused; the exception carries the answer.
	 *
	 * @spec openspec/changes/api-upsert-on-a-declared-key/specs/objects-crud/spec.md
	 */
	public function upsert(string $constraintName, array $object, Register $register, Schema $schema, callable $save): array {
		$constraint = $this->resolveConstraint(constraintName: $constraintName, schema: $schema);
		$values     = $this->keyValues(constraint: $constraint, object: $object);
		$lockPath   = $this->lockPath(register: $register, schema: $schema, constraint: $constraint, values: $values);

		$this->acquire(lockPath: $lockPath);
		try {
			try {
				$uuids = $this->matchResolver->resolve(
					object: $object,
					matchKey: $constraint['properties'],
					register: $register,
					schema: $schema
				);
			} catch (MatchLookupFailedException $exception) {
				// A lookup that could not run is not "no match": creating now
				// would duplicate the record the key should have found.
				throw new UpsertOnKeyException(
					statusCode: 503,
					body: ['error' => 'The key could not be looked up, so nothing was written. Try again.'],
					headers: ['Retry-After' => '5'],
					previous: $exception
				);
			}

			if (count($uuids) > 1) {
				throw new UpsertOnKeyException(
					statusCode: 409,
					body: [
						'error' => 'More than one record holds this key, so none was changed.',
						'constraint' => $constraint['name'],
						'matches' => $uuids,
					]
				);
			}

			$uuid = ($uuids[0] ?? null);

			return [
				'created' => ($uuid === null),
				'object' => $this->save(save: $save, uuid: $uuid, constraint: $constraint),
			];
		} finally {
			$this->lockingProvider->releaseLock($lockPath, ILockingProvider::LOCK_EXCLUSIVE);
		}//end try
	}//end upsert()

	/**
	 * The refuse constraint the call names, or a 400 listing the ones it may name.
	 *
	 * @param string $constraintName The requested name.
	 * @param Schema $schema         The schema.
	 *
	 * @return array{name:string,properties:array<int,string>,action:string} The constraint.
	 *
	 * @throws UpsertOnKeyException When the name is not a refuse constraint of this schema.
	 */
	private function resolveConstraint(string $constraintName, Schema $schema): array {
		$refuse = [];
		foreach ($this->evaluator->constraints(configuration: $schema->getConfiguration(), includeLegacy: true) as $constraint) {
			if ($constraint['action'] !== UniqueConstraintEvaluator::ACTION_REFUSE) {
				continue;
			}

			if ($constraint['name'] === $constraintName) {
				return $constraint;
			}

			$refuse[] = $constraint['name'];
		}

		throw new UpsertOnKeyException(
			statusCode: 400,
			body: [
				'error' => "'".$constraintName."' is not a uniqueness constraint of this schema that refuses duplicates, so it cannot be used as an upsert key.",
				'refuseConstraints' => $refuse,
			]
		);
	}//end resolveConstraint()

	/**
	 * The key's values from the body, or a 400 naming the first one missing.
	 *
	 * @param array{name:string,properties:array<int,string>,action:string} $constraint The constraint.
	 * @param array<string, mixed>                                           $object     The body.
	 *
	 * @return array<string, mixed> The values, keyed by property.
	 *
	 * @throws UpsertOnKeyException When a key property has no value.
	 */
	private function keyValues(array $constraint, array $object): array {
		$values = [];
		foreach ($constraint['properties'] as $property) {
			$value = ($object[$property] ?? null);
			if ($value === null || $value === '') {
				throw new UpsertOnKeyException(
					statusCode: 400,
					body: [
						'error' => "The upsert key '".$constraint['name']."' needs a value for '".$property."'.",
						'constraint' => $constraint['name'],
						'property' => $property,
					]
				);
			}

			$values[$property] = $value;
		}

		return $values;
	}//end keyValues()

	/**
	 * The lock path for one key: a hash, so key values never reach a lock table or a log.
	 *
	 * The register and schema ids go INTO the hash, not beside it: Nextcloud's
	 * database locking provider stores the path in `oc_file_locks.key`, a
	 * varchar(64), and a longer path made pgsql refuse every lock. `sha1` keeps
	 * the path at 60 characters; this is a lock name, not a secret.
	 *
	 * @param Register                                                       $register   The register.
	 * @param Schema                                                         $schema     The schema.
	 * @param array{name:string,properties:array<int,string>,action:string} $constraint The constraint.
	 * @param array<string, mixed>                                           $values     The key's values.
	 *
	 * @return string The lock path.
	 */
	private function lockPath(Register $register, Schema $schema, array $constraint, array $values): string {
		$digest = hash('sha1', (string)json_encode([$register->getId(), $schema->getId(), $constraint['name'], $values]));

		return 'openregister/upsert/'.$digest;
	}//end lockPath()

	/**
	 * Take the key's exclusive lock, waiting a few seconds for another call to finish.
	 *
	 * Nextcloud's providers refuse a held lock at once rather than wait, so the
	 * wait is here. A lock still held after it is a 503: nothing was written.
	 *
	 * @param string $lockPath The lock path.
	 *
	 * @return void
	 *
	 * @throws UpsertOnKeyException When the lock stays held.
	 */
	private function acquire(string $lockPath): void {
		for ($attempt = 1; $attempt <= self::LOCK_ATTEMPTS; $attempt++) {
			try {
				$this->lockingProvider->acquireLock($lockPath, ILockingProvider::LOCK_EXCLUSIVE, 'an upsert key');
				return;
			} catch (LockedException $exception) {
				if ($attempt === self::LOCK_ATTEMPTS) {
					throw new UpsertOnKeyException(
						statusCode: 503,
						body: ['error' => 'Another call is writing the record with this key. Try again.'],
						headers: ['Retry-After' => '5'],
						previous: $exception
					);
				}

				usleep(self::LOCK_WAIT_US);
			}
		}//end for
	}//end acquire()

	/**
	 * Save, turning a refusal by a holder the caller cannot see into a 409 that names no uuid.
	 *
	 * @param callable(?string): ObjectEntity                                $save       The save.
	 * @param string|null                                                    $uuid       The record to update, or null to create.
	 * @param array{name:string,properties:array<int,string>,action:string} $constraint The constraint.
	 *
	 * @return ObjectEntity The saved record.
	 *
	 * @throws UpsertOnKeyException When a record the caller cannot see holds the key.
	 */
	private function save(callable $save, ?string $uuid, array $constraint): ObjectEntity {
		try {
			return $save($uuid);
		} catch (HookStoppedException $exception) {
			$errors = $exception->getErrors();
			if (($errors['code'] ?? null) !== UniqueConstraintListener::ERROR_CODE) {
				throw $exception;
			}

			// The lookup ran under the caller's rights and found nothing, yet a
			// record holds the key: one the caller cannot read. Its uuid stays out.
			throw new UpsertOnKeyException(
				statusCode: 409,
				body: [
					'error' => 'A record with this key exists that you cannot change.',
					'constraint' => ($errors['constraint'] ?? $constraint['name']),
				],
				previous: $exception
			);
		}//end try
	}//end save()
}//end class
