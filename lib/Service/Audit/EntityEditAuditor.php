<?php

/**
 * EntityEditAuditor: one audit row per schema or register create, update and delete.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Audit
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Audit;

use DateTime;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Rbac\AuditRowWriter;
use OCA\OpenRegister\Service\WriteCause;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Builds and writes the audit row of a schema or register edit.
 *
 * An update records each changed path with its old and new value: properties
 * per property and keyword, configuration and authorization per key. Values
 * the server derives (`updated`, `created`, `facets`) are skipped, so a save
 * that changed nothing writes nothing. A value longer than 2 KB is stored as
 * its sha256 and length, and a credential-named key is masked on both sides,
 * because the trail is append-only. A failed write is logged at ERROR and
 * never fails the edit, which has already been stored.
 *
 * @spec openspec/changes/history-schema-and-settings-edits-audited/design.md
 */
final class EntityEditAuditor {

	/**
	 * The longest JSON value kept as is; a longer one is kept as hash and length.
	 */
	public const MAX_VALUE_BYTES = 2048;

	/**
	 * What a masked credential reads as.
	 */
	public const MASK = '********';

	/**
	 * Key names whose value is never written to the trail, compared without case.
	 */
	private const CREDENTIAL_KEYS = ['password', 'secret', 'token', 'apikey', 'clientsecret', 'privatekey'];

	/**
	 * Top-level fields the server derives on every save, and the version,
	 * which the row already carries as versionBefore and versionAfter.
	 */
	private const DERIVED = ['updated', 'created', 'facets', 'version'];

	/**
	 * Top-level fields diffed one level deeper.
	 */
	private const KEYED = ['configuration', 'authorization'];

	/**
	 * Constructor.
	 *
	 * @param AuditTrailMapperInterface $mapper      The audit trail, which seals the rows.
	 * @param IUserSession              $userSession Who made the edit.
	 * @param LoggerInterface           $logger      Receives a failed write.
	 */
	public function __construct(
		private readonly AuditTrailMapperInterface $mapper,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Record a created schema or register.
	 *
	 * @param Schema|Register $entity The entity as stored.
	 *
	 * @return int The rows written: 1, or 0 when the write failed.
	 *
	 * @spec openspec/changes/history-schema-and-settings-edits-audited/design.md
	 */
	public function recordCreated(Schema|Register $entity): int {
		$changed = $this->identity(entity: $entity) + ['properties' => $this->propertyNames(entity: $entity)];

		return $this->write(row: $this->row(entity: $entity, verb: 'created', changed: $changed));
	}//end recordCreated()

	/**
	 * Record an updated schema or register; a save that changed nothing writes nothing.
	 *
	 * @param Schema|Register $old The entity before the save.
	 * @param Schema|Register $new The entity as stored.
	 *
	 * @return int The rows written: 1, 0 for no change or a failed write.
	 *
	 * @spec openspec/changes/history-schema-and-settings-edits-audited/design.md
	 */
	public function recordUpdated(Schema|Register $old, Schema|Register $new): int {
		$fields = $this->diff(old: $old->jsonSerialize(), new: $new->jsonSerialize());
		if ($fields === []) {
			return 0;
		}

		$changed = [
			'slug' => $new->getSlug(),
			'versionBefore' => $old->getVersion(),
			'versionAfter' => $new->getVersion(),
			'fields' => $fields,
		];

		return $this->write(row: $this->row(entity: $new, verb: 'updated', changed: $changed));
	}//end recordUpdated()

	/**
	 * Record a deleted schema or register, its definition kept only as a hash.
	 *
	 * @param Schema|Register $entity The entity as it was.
	 *
	 * @return int The rows written: 1, or 0 when the write failed.
	 *
	 * @spec openspec/changes/history-schema-and-settings-edits-audited/design.md
	 */
	public function recordDeleted(Schema|Register $entity): int {
		$changed = $this->identity(entity: $entity) + [
			'propertyCount' => count($this->propertyNames(entity: $entity)),
			'sha256' => hash('sha256', (string)json_encode($entity->jsonSerialize())),
		];

		return $this->write(row: $this->row(entity: $entity, verb: 'deleted', changed: $changed));
	}//end recordDeleted()

	/**
	 * The changed paths between two serialised entities.
	 *
	 * Pure and public, so the rule can be tested against a table.
	 *
	 * @param array<string, mixed> $old The entity before, serialised.
	 * @param array<string, mixed> $new The entity after, serialised.
	 *
	 * @return array<int, array{path: string, old: mixed, new: mixed}>
	 *
	 * @spec openspec/changes/history-schema-and-settings-edits-audited/design.md
	 */
	public function diff(array $old, array $new): array {
		$fields = [];
		foreach ($this->keysOf(old: $old, new: $new) as $key) {
			if (in_array($key, self::DERIVED, true) === true) {
				continue;
			}

			$before = ($old[$key] ?? null);
			$after  = ($new[$key] ?? null);
			if ($key === 'properties') {
				$fields = array_merge($fields, $this->propertyChanges(old: $before, new: $after));
				continue;
			}

			if (in_array($key, self::KEYED, true) === true && $this->isMap(value: $before) === true && $this->isMap(value: $after) === true) {
				$fields = array_merge($fields, $this->keyedChanges(prefix: $key, old: (array)$before, new: (array)$after));
				continue;
			}

			$fields = array_merge($fields, $this->change(path: (string)$key, old: $before, new: $after));
		}//end foreach

		return $fields;
	}//end diff()

	/**
	 * Per property and per keyword changes; a new or removed property is one entry.
	 *
	 * @param mixed $old The properties before.
	 * @param mixed $new The properties after.
	 *
	 * @return array<int, array{path: string, old: mixed, new: mixed}>
	 */
	private function propertyChanges(mixed $old, mixed $new): array {
		$old     = (array)($old ?? []);
		$new     = (array)($new ?? []);
		$changes = [];
		foreach ($this->keysOf(old: $old, new: $new) as $name) {
			$path = 'properties.' . $name;
			if (array_key_exists($name, $old) === false || array_key_exists($name, $new) === false
				|| $this->isMap(value: $old[$name]) === false || $this->isMap(value: $new[$name]) === false
			) {
				$changes = array_merge($changes, $this->change(path: $path, old: ($old[$name] ?? null), new: ($new[$name] ?? null)));
				continue;
			}

			$changes = array_merge($changes, $this->keyedChanges(prefix: $path, old: $old[$name], new: $new[$name]));
		}

		return $changes;
	}//end propertyChanges()

	/**
	 * Per key changes under a prefix.
	 *
	 * @param string               $prefix The path so far.
	 * @param array<string, mixed> $old    The map before.
	 * @param array<string, mixed> $new    The map after.
	 *
	 * @return array<int, array{path: string, old: mixed, new: mixed}>
	 */
	private function keyedChanges(string $prefix, array $old, array $new): array {
		$changes = [];
		foreach ($this->keysOf(old: $old, new: $new) as $key) {
			$changes = array_merge($changes, $this->change(path: $prefix . '.' . $key, old: ($old[$key] ?? null), new: ($new[$key] ?? null)));
		}

		return $changes;
	}//end keyedChanges()

	/**
	 * One entry when the values differ, none when they are the same.
	 *
	 * @param string $path The path.
	 * @param mixed  $old  The value before.
	 * @param mixed  $new  The value after.
	 *
	 * @return array<int, array{path: string, old: mixed, new: mixed}>
	 */
	private function change(string $path, mixed $old, mixed $new): array {
		if ($this->same(a: $old, b: $new) === true) {
			return [];
		}

		if ($this->namesCredential(path: $path) === true) {
			return [['path' => $path, 'old' => $this->maskIfPresent(value: $old), 'new' => $this->maskIfPresent(value: $new)]];
		}

		return [['path' => $path, 'old' => $this->stored(value: $old), 'new' => $this->stored(value: $new)]];
	}//end change()

	/**
	 * The value as written to the trail: credentials masked at any depth, long values hashed.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed
	 */
	private function stored(mixed $value): mixed {
		$value = $this->masked(value: $value);
		$json  = (string)json_encode($value);
		if (strlen($json) > self::MAX_VALUE_BYTES) {
			return ['sha256' => hash('sha256', $json), 'length' => strlen($json)];
		}

		return $value;
	}//end stored()

	/**
	 * The value with every credential-named key masked, at any depth.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed
	 */
	private function masked(mixed $value): mixed {
		if (is_array($value) === false) {
			return $value;
		}

		foreach ($value as $key => $inner) {
			if (is_string($key) === true && $this->isCredentialKey(key: $key) === true) {
				$value[$key] = $this->maskIfPresent(value: $inner);
				continue;
			}

			$value[$key] = $this->masked(value: $inner);
		}

		return $value;
	}//end masked()

	/**
	 * Whether any segment of a path is a credential name.
	 *
	 * @param string $path The dotted path.
	 *
	 * @return bool
	 */
	private function namesCredential(string $path): bool {
		foreach (explode('.', $path) as $segment) {
			if ($this->isCredentialKey(key: $segment) === true) {
				return true;
			}
		}

		return false;
	}//end namesCredential()

	/**
	 * Whether a key names a credential.
	 *
	 * @param string $key The key.
	 *
	 * @return bool
	 */
	private function isCredentialKey(string $key): bool {
		return in_array(strtolower($key), self::CREDENTIAL_KEYS, true);
	}//end isCredentialKey()

	/**
	 * The mask for a value that was there, null for one that was not.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null
	 */
	private function maskIfPresent(mixed $value): ?string {
		if ($value === null) {
			return null;
		}

		return self::MASK;
	}//end maskIfPresent()

	/**
	 * Loose for scalars ("1" equals 1), strict otherwise, key order ignored in maps.
	 *
	 * @param mixed $a One value.
	 * @param mixed $b The other.
	 *
	 * @return bool
	 */
	private function same(mixed $a, mixed $b): bool {
		if (is_scalar($a) === true && is_scalar($b) === true) {
			return ((string)$a === (string)$b);
		}

		return $this->canonical(value: $a) === $this->canonical(value: $b);
	}//end same()

	/**
	 * The value with every map's keys sorted.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed
	 */
	private function canonical(mixed $value): mixed {
		if (is_array($value) === false) {
			return $value;
		}

		if (array_is_list($value) === false) {
			ksort($value);
		}

		return array_map(fn (mixed $inner): mixed => $this->canonical(value: $inner), $value);
	}//end canonical()

	/**
	 * Whether a value is a map (an array with string keys, or empty).
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool
	 */
	private function isMap(mixed $value): bool {
		return is_array($value) === true && ($value === [] || array_is_list($value) === false);
	}//end isMap()

	/**
	 * The keys of both maps, old order first.
	 *
	 * @param array<string, mixed> $old One map.
	 * @param array<string, mixed> $new The other.
	 *
	 * @return array<int, string|int>
	 */
	private function keysOf(array $old, array $new): array {
		return array_values(array_unique(array_merge(array_keys($old), array_keys($new)), SORT_REGULAR));
	}//end keysOf()

	/**
	 * Slug, title and version.
	 *
	 * @param Schema|Register $entity The entity.
	 *
	 * @return array{slug: string|null, title: string|null, version: string|null}
	 */
	private function identity(Schema|Register $entity): array {
		return ['slug' => $entity->getSlug(), 'title' => $entity->getTitle(), 'version' => $entity->getVersion()];
	}//end identity()

	/**
	 * A schema's property names; a register has none.
	 *
	 * @param Schema|Register $entity The entity.
	 *
	 * @return array<int, string>
	 */
	private function propertyNames(Schema|Register $entity): array {
		if ($entity instanceof Schema === false) {
			return [];
		}

		return array_map('strval', array_keys(($entity->getProperties() ?? [])));
	}//end propertyNames()

	/**
	 * The row: action, entity, organisation, actor and cause.
	 *
	 * @param Schema|Register      $entity  The entity.
	 * @param string               $verb    created, updated or deleted.
	 * @param array<string, mixed> $changed The changed block.
	 *
	 * @return AuditTrail
	 */
	private function row(Schema|Register $entity, string $verb, array $changed): AuditTrail {
		$user  = $this->userSession->getUser();
		$cause = WriteCause::current();

		$row = new AuditTrail();
		$row->setUuid(Uuid::v4()->toRfc4122());
		$row->setUser($user?->getUID() ?? 'system');
		$row->setUserName($user?->getDisplayName() ?? 'System');
		$row->setOrganisationId($entity->getOrganisation());
		$row->setChanged($changed);
		$row->setCause($cause['cause']);
		$row->setCauseRun($cause['run']);
		$row->setCreated(new DateTime());
		if ($entity instanceof Schema) {
			$row->setAction('schema.' . $verb);
			$row->setSchema($entity->getId());
			$row->setSchemaUuid($entity->getUuid());
			return $row;
		}

		$row->setAction('register.' . $verb);
		$row->setRegister($entity->getId());
		$row->setRegisterUuid($entity->getUuid());

		return $row;
	}//end row()

	/**
	 * Write one row; a failure is logged at ERROR and returns 0.
	 *
	 * @param AuditTrail $row The row.
	 *
	 * @return int
	 */
	private function write(AuditTrail $row): int {
		return (new AuditRowWriter(mapper: $this->mapper, logger: $this->logger))->write(
			rows: [$row],
			failure: '[EntityEditAuditor] Could not record a ' . $row->getAction() . ' edit; the edit itself was stored'
		);
	}//end write()
}//end class
