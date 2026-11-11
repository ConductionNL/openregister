<?php

/**
 * Conditions on record metadata that no record carries.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/row-field-level-security/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCA\OpenRegister\Db\ObjectEntity;
use Psr\Log\LoggerInterface;

/**
 * Finds `match` keys that name record metadata a record does not carry.
 *
 * A `match` key with an underscore or `@self.` prefix names record metadata and
 * is answered from the record's `@self` ({@see ObjectEntity::getMatchEnvelope()}).
 * A key whose field is not in that envelope, such as `_published` or
 * `@self.published`, can never match a record: an allow on it grants nothing and
 * a deny on it never fires. The known fields are read from the envelope itself,
 * so they follow the entity.
 *
 * @spec openspec/specs/row-field-level-security/spec.md
 */
class RecordMetadataConditions {

	/**
	 * The fields of a record's `@self`, read once per process.
	 *
	 * @var array<int, string>|null
	 */
	private static ?array $fields = null;

	/**
	 * Match keys that name an envelope field under another name.
	 *
	 * The envelope carries the record's uuid as `id`; the magic table column,
	 * and so the list query, names it `_uuid`.
	 *
	 * @var array<string, string>
	 */
	private const ALIASES = ['uuid' => 'id'];

	/**
	 * The prefix of a `match` key spelled as a path into `@self`.
	 *
	 * @var string
	 */
	private const SELF_PREFIX = '@self.';

	/**
	 * The schema and key pairs already logged by {@see refuses()}.
	 *
	 * @var array<string, true>
	 */
	private array $logged = [];

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface|null $logger Where {@see refuses()} logs; nothing is logged without one.
	 */
	public function __construct(private readonly ?LoggerInterface $logger = null) {
	}//end __construct()

	/**
	 * The metadata fields a record carries, without the underscore.
	 *
	 * @return array<int, string> The field names.
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md
	 */
	public function fields(): array {
		if (self::$fields === null) {
			self::$fields = array_merge(
				array_map('strval', array_keys((new ObjectEntity())->getMatchEnvelope()['@self'])),
				array_keys(self::ALIASES)
			);
		}

		return self::$fields;
	}//end fields()

	/**
	 * The value a condition on this property reads.
	 *
	 * A metadata key reads the object's `@self`, which is the record's metadata
	 * and the only answer: a body key of the same name is written by whoever wrote
	 * the body. A field the `@self` holds as empty answers null; so does a field
	 * this `@self` lacks, and a key that names no record metadata at all, which
	 * callers refuse before asking ({@see unknownKeys()}). Only an underscore key
	 * on an object without any `@self` falls back to its body.
	 *
	 * @param array<int|string, mixed> $object   The object.
	 * @param string                   $property The condition's key.
	 *
	 * @return mixed The value, or null.
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md
	 */
	public function valueIn(array $object, string $property): mixed {
		$field = $this->metadataField(key: $property);
		if ($field === null) {
			return ($object[$property] ?? null);
		}

		if (is_array(($object['@self'] ?? null)) === false) {
			if (str_starts_with($property, '_') === true) {
				return ($object[$property] ?? null);
			}

			return null;
		}

		return ($object['@self'][(self::ALIASES[$field] ?? $field)] ?? null);
	}//end valueIn()

	/**
	 * The field a `@self.` key names, as the underscore key of its list-query column.
	 *
	 * @param string $key The `match` key.
	 *
	 * @return string The underscore key; any other key as given.
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md
	 */
	public function columnKey(string $key): string {
		if (str_starts_with($key, self::SELF_PREFIX) === false) {
			return $key;
		}

		$field = substr($key, strlen(self::SELF_PREFIX));
		if ($field === 'id') {
			$field = 'uuid';
		}

		return '_'.$field;
	}//end columnKey()

	/**
	 * The metadata field a `match` key names, or null when it names a body property.
	 *
	 * @param string $key The `match` key.
	 *
	 * @return string|null The field.
	 */
	private function metadataField(string $key): ?string {
		if (str_starts_with($key, self::SELF_PREFIX) === true) {
			return substr($key, strlen(self::SELF_PREFIX));
		}

		if (str_starts_with($key, '_') === true) {
			return substr($key, 1);
		}

		return null;
	}//end metadataField()

	/**
	 * The metadata keys of a `match` clause that name no record metadata.
	 *
	 * @param array<int|string, mixed> $match The clause.
	 *
	 * @return array<int, string> The keys, in clause order.
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md
	 */
	public function unknownKeys(array $match): array {
		$unknown = [];
		foreach (array_keys($match) as $key) {
			$key = (string)$key;
			$field = $this->metadataField(key: $key);
			if ($field !== null && in_array($field, $this->fields(), true) === false) {
				$unknown[] = $key;
			}
		}

		return $unknown;
	}//end unknownKeys()

	/**
	 * Whether a stored clause names unknown metadata, logging each schema and key once.
	 *
	 * The caller fails closed on true: an allow grants nothing, a deny denies.
	 *
	 * @param array<int|string, mixed> $match  The clause.
	 * @param array<int|string, mixed> $object The object being evaluated, for the schema its `@self` names.
	 *
	 * @return bool True when the clause names unknown metadata.
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md
	 */
	public function refuses(array $match, array $object = []): bool {
		$unknown = $this->unknownKeys(match: $match);
		if ($unknown === []) {
			return false;
		}

		$schema = $this->schemaOf(object: $object);
		foreach ($unknown as $key) {
			$this->logOnce(schema: $schema, key: (string)$key);
		}

		return true;
	}//end refuses()

	/**
	 * Log a condition on unknown metadata, once per schema and key.
	 *
	 * @param string $schema The schema id the object names, or ''.
	 * @param string $key    The unknown key.
	 *
	 * @return void
	 */
	private function logOnce(string $schema, string $key): void {
		if (isset($this->logged[$schema.'|'.$key]) === true) {
			return;
		}

		$this->logged[$schema.'|'.$key] = true;
		$this->logger?->error(
			message: '[RecordMetadataConditions] A stored rule has a condition on '.$key
				.', which no record carries; the rule fails closed (an allow grants nothing, a deny denies)',
			context: ['file' => __FILE__, 'line' => __LINE__, 'schema' => $schema, 'key' => $key]
		);
	}//end logOnce()

	/**
	 * The schema id an object's `@self` names, or an empty string.
	 *
	 * @param array<int|string, mixed> $object The object.
	 *
	 * @return string The schema id.
	 */
	private function schemaOf(array $object): string {
		$schema = ($object['@self']['schema'] ?? null);
		if (is_array($schema) === true) {
			$schema = ($schema['id'] ?? null);
		}

		if (is_scalar($schema) === true) {
			return (string)$schema;
		}

		return '';
	}//end schemaOf()
}//end class
