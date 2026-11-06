<?php

/**
 * MagicRowMetadata: the `_`-prefixed metadata columns written for one object.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\MagicMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/magic-table-reads/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Rbac\RuleColumns;
use Psr\Container\ContainerInterface;

/**
 * Make an object's metadata authoritative and turn it into metadata columns.
 *
 * @spec openspec/specs/magic-table-reads/spec.md
 */
final class MagicRowMetadata {
	/**
	 * The prefix of a metadata column.
	 *
	 * @var string
	 */
	private const PREFIX = '_';

	/**
	 * The metadata fields every object write names, in column order.
	 *
	 * @var string[]
	 */
	private const WRITE_FIELDS = [
		'uuid',
		'slug',
		'uri',
		'version',
		'register',
		'schema',
		'owner',
		'organisation',
		'application',
		'folder',
		'name',
		'description',
		'summary',
		'image',
		'size',
		'schema_version',
		'files',
		'relations',
		'locked',
		// `authorization` is DELIBERATELY absent from this list.
		//
		// authoritative() strips it because per-object RBAC must not be
		// writable by ordinary create/update calls. Listing it here would resolve
		// the stripped key to an explicit NULL, and the UPDATE would write it,
		// destroying the stored rules on the next save of that object: a private
		// object would silently become visible again, and the per-object action
		// overrides would be wiped the same way.
		//
		// Omitting the field means the column is never named in the INSERT or
		// UPDATE at all (`updateObjectInRegisterSchemaTable()` only sets keys that
		// are PRESENT), so the stored value is carried forward untouched. Writes go
		// through the dedicated authorization-management path.
		'validation',
		'quality',
		'deleted',
		// Listed, and therefore carried forward on every ordinary update, for the
		// same reason `locked` is: the value comes from the ENTITY, and
		// `setSelfMetadata()` never accepts either key from a client `@self`
		// payload, so no write path can archive or freeze an object without the
		// endpoint. Omitting them would make `updateObjectEntity()` stop naming the
		// column, and the dedicated archive write would be the only write that
		// could ever clear it.
		'archived',
		'frozen',
		// Carried forward like `frozen`: setSelfMetadata() lets a client set
		// `draft` on create only, and only the submit leaves it.
		'status',
		'geo',
		'retention',
		'groups',
		'tmlo',
		'created',
		'updated',
		'last_user_activity',
		'expires',
	];

	/**
	 * The metadata fields stored as JSON; the schema's active linked types are added per write.
	 *
	 * @var string[]
	 */
	private const JSON_FIELDS = [
		'files',
		'relations',
		'locked',
		'authorization',
		'validation',
		'quality',
		'deleted',
		'archived',
		'frozen',
		'geo',
		'retention',
		'groups',
		'tmlo',
	];

	/**
	 * Fields stored as a UTC datetime.
	 *
	 * @var string[]
	 */
	private const DATE_FIELDS = ['created', 'updated', 'last_user_activity', 'expires'];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves the date normalizer.
	 *
	 * @spec openspec/specs/magic-table-reads/spec.md
	 */
	public function __construct(private readonly ContainerInterface $container) {
	}//end __construct()

	/**
	 * The `@self` metadata of an object with the server-controlled fields made authoritative.
	 *
	 * SECURITY (wave-7 CRITICAL C2, @self allowlist enforcement): clients must not be able
	 * to overwrite server-controlled fields via the @self block. The primary defence lives
	 * in SaveObject::setSelfMetadata (which strips owner/authorization from raw client
	 * $selfData). This is defence in depth at the DB write boundary, for fields that any
	 * code path can reach, not just the REST request path.
	 *
	 * - owner: the server-stamped owner (a scalar UID string set by
	 *   SaveObject::applyOwnerAttribution before jsonSerialize()) MUST be persisted,
	 *   otherwise rows land with an empty `_owner` column: the creator can never equal the
	 *   owner, so the update lock-guard 403s the owner's own update and ownership-based
	 *   RBAC and owner notifications are neutered. A non-string owner, the shape a
	 *   forged `@self` payload would take, is dropped.
	 * - authorization: per-object RBAC rules are not writable by ordinary create/update
	 *   calls; they are managed through the dedicated authorization endpoint.
	 * - register / schema: always the authoritative method parameters, never a client value.
	 *
	 * Not stripped here, because the server sets them before this point: version, created,
	 * updated, deleted, locked, retention, uuid, slug, uri. Client injection of any of
	 * those must be fixed at the SaveObject/controller level.
	 *
	 * @param array                                 $objectData The object data, with its optional `@self` block
	 * @param array{register: mixed, schema: mixed} $ids        The authoritative register and schema ids
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/magic-table-reads/spec.md
	 */
	public function authoritative(array $objectData, array $ids): array {
		$metadata = $this->withoutForgedFields(metadata: $objectData['@self'] ?? []);
		$metadata['register'] = $ids['register'];
		$metadata['schema']   = $ids['schema'];

		// The entity serialises `schemaVersion` and `lastUserActivity`; the columns are snake_case.
		$metadata['schema_version']     = $metadata['schemaVersion'] ?? $metadata['schema_version'] ?? null;
		$metadata['last_user_activity'] = $metadata['lastUserActivity'] ?? null;

		return $metadata;
	}//end authoritative()

	/**
	 * The `_`-prefixed metadata columns written for one object, in column order.
	 *
	 * @param array<string, mixed> $metadata The authoritative metadata, `created` and `updated` filled in.
	 * @param Schema               $schema   The schema context.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/magic-table-reads/spec.md
	 */
	public function columns(array $metadata, Schema $schema): array {
		$linked = $this->linkedFields(schema: $schema);
		$jsonFields = array_merge(self::JSON_FIELDS, $linked);

		$columns = [];
		foreach (array_merge(self::WRITE_FIELDS, $linked) as $field) {
			$value = $this->storedValue(field: $field, value: ($metadata[$field] ?? null));
			if (in_array($field, $jsonFields) === true) {
				$value = $this->jsonColumnValue(value: $value);
			}

			$columns[self::PREFIX.$field] = $value;
		}

		return $columns;
	}//end columns()

	/**
	 * Metadata without the authorization rules and a non-string owner.
	 *
	 * @param array $metadata The `@self` block.
	 *
	 * @return array
	 */
	private function withoutForgedFields(array $metadata): array {
		unset($metadata['authorization']);

		if (isset($metadata['owner']) === true && is_string($metadata['owner']) === false) {
			unset($metadata['owner']);
		}

		return $metadata;
	}//end withoutForgedFields()

	/**
	 * The schema's active linked types. RuleColumns::LINKED_TYPES values carry the column
	 * prefix and columns() adds its own, so the linked type key itself is the field.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return array<string>
	 */
	private function linkedFields(Schema $schema): array {
		$fields = [];
		foreach ($schema->getLinkedTypes() as $linkedType) {
			if (isset(RuleColumns::LINKED_TYPES[$linkedType]) === true) {
				$fields[] = $linkedType;
			}
		}

		return $fields;
	}//end linkedFields()

	/**
	 * A metadata value as its column stores it; datetime fields become UTC 'Y-m-d H:i:s'.
	 *
	 * Empty text becomes null rather than now, and a non-UTC offset is converted
	 * rather than dropped (WOO-567): a metadata date is an instant.
	 *
	 * @param string $field The metadata field
	 * @param mixed  $value The value from `@self`
	 *
	 * @return mixed
	 */
	private function storedValue(string $field, mixed $value): mixed {
		if (in_array($field, self::DATE_FIELDS) === false) {
			return $value;
		}

		return (new MagicPropertyDate(container: $this->container))->stored(value: $value, format: 'date-time');
	}//end storedValue()

	/**
	 * A value for a JSON metadata column: an empty array is NULL so IS NULL checks hold,
	 * a string is kept, anything else is JSON-encoded.
	 *
	 * An empty string becomes NULL at the final insert/update stage, not here.
	 *
	 * @param mixed $value The value
	 *
	 * @return mixed
	 */
	private function jsonColumnValue(mixed $value): mixed {
		if ($value === null || $value === []) {
			return null;
		}

		if (is_string($value) === false) {
			return json_encode($value);
		}

		return $value;
	}//end jsonColumnValue()
}//end class
