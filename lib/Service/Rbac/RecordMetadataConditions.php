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
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\AuthorizationBlockException;
use Psr\Log\LoggerInterface;

/**
 * Finds `match` keys that name record metadata a record does not carry.
 *
 * An underscore-prefixed `match` key names record metadata and is answered
 * from the record's `@self` ({@see ObjectEntity::getMatchEnvelope()}). A key
 * whose field is not in that envelope, such as `_published`, can never match a
 * record: an allow on it grants nothing and a deny on it never fires. The known
 * fields are read from the envelope itself, so they follow the entity.
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
	 * An underscore-prefixed property is metadata. When the object carries a
	 * `@self`, that is the record's metadata and the only answer: a body key of
	 * the same name is written by whoever wrote the body. A field the `@self`
	 * holds as empty answers null; so does a field this `@self` lacks, and a key
	 * that names no record metadata at all, which callers refuse before asking
	 * ({@see unknownKeys()}). Only an object without any `@self` falls back to
	 * its body.
	 *
	 * @param array<int|string, mixed> $object   The object.
	 * @param string                   $property The condition's key.
	 *
	 * @return mixed The value, or null.
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md
	 */
	public function valueIn(array $object, string $property): mixed {
		if (str_starts_with($property, '_') === false || is_array(($object['@self'] ?? null)) === false) {
			return ($object[$property] ?? null);
		}

		$field = substr($property, 1);
		$field = (self::ALIASES[$field] ?? $field);
		if (array_key_exists($field, $object['@self']) === false) {
			return null;
		}

		return $object['@self'][$field];
	}//end valueIn()

	/**
	 * The underscore-prefixed keys of a `match` clause that name no record metadata.
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
			if (str_starts_with($key, '_') === true && in_array(substr($key, 1), $this->fields(), true) === false) {
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
			if (isset($this->logged[$schema.'|'.$key]) === true) {
				continue;
			}

			$this->logged[$schema.'|'.$key] = true;
			$this->logger?->error(
				message: '[RecordMetadataConditions] A stored rule has a condition on '.$key
					.', which no record carries; the rule fails closed (an allow grants nothing, a deny denies)',
				context: ['file' => __FILE__, 'line' => __LINE__, 'schema' => $schema, 'key' => $key]
			);
		}

		return true;
	}//end refuses()

	/**
	 * One sentence per rule of an authorization block that names unknown metadata.
	 *
	 * Covers the grant rules of every action and the entries of the `deny`
	 * block. Other control keys are not rule lists and are left to their own
	 * validators.
	 *
	 * @param array|null $authorization The block.
	 * @param string     $context       What the block belongs to, for the message.
	 *
	 * @return array<int, string> The findings; empty when every condition can match.
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md
	 */
	public function findings(?array $authorization, string $context): array {
		$findings = [];
		foreach ($this->ruleLists(authorization: $authorization) as $label => $rules) {
			foreach ($rules as $rule) {
				if (is_array($rule) === false || is_array(($rule['match'] ?? null)) === false) {
					continue;
				}

				foreach ($this->unknownKeys(match: $rule['match']) as $key) {
					$findings[] = sprintf(
						'The %s rule for %s in %s has a condition on "%s", but records carry no "%s" metadata,'
						.' so it can never match. Underscore-prefixed keys name record metadata: %s.',
						$label,
						$this->principalOf(rule: $rule),
						$context,
						$key,
						substr($key, 1),
						'_'.implode(', _', $this->fields())
					);
				}
			}
		}

		return $findings;
	}//end findings()

	/**
	 * The findings of a schema's own block and of every property's rules.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return array<int, string> The findings.
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md
	 */
	public function schemaFindings(Schema $schema): array {
		$name = sprintf('schema "%s"', (string)($schema->getSlug() ?? $schema->getTitle() ?? $schema->getId()));
		$findings = $this->findings(authorization: $schema->getAuthorization(), context: $name);

		foreach (($schema->getProperties() ?? []) as $property => $config) {
			if (is_array($config) === false || is_array(($config['authorization'] ?? null)) === false) {
				continue;
			}

			$context = sprintf('property "%s" of %s', (string)$property, $name);
			$findings = array_merge($findings, $this->findings(authorization: $config['authorization'], context: $context));
		}

		return $findings;
	}//end schemaFindings()

	/**
	 * Refuse a save that carries findings.
	 *
	 * @param array<int, string> $findings The findings.
	 *
	 * @return void
	 *
	 * @throws AuthorizationBlockException When there is at least one finding.
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md
	 */
	public function assertNone(array $findings): void {
		if ($findings === []) {
			return;
		}

		throw new AuthorizationBlockException(message: implode(' ', $findings));
	}//end assertNone()

	/**
	 * The rule lists of a block, keyed by how a message names them.
	 *
	 * @param array|null $authorization The block.
	 *
	 * @return array<string, array<int|string, mixed>> The lists.
	 */
	private function ruleLists(?array $authorization): array {
		$lists = [];
		foreach (($authorization ?? []) as $action => $rules) {
			if (is_array($rules) === false) {
				continue;
			}

			if ($action === DenyResolver::DENY_KEY) {
				foreach ($rules as $denied => $entries) {
					if (is_array($entries) === true) {
						$lists['deny '.(string)$denied] = $entries;
					}
				}

				continue;
			}

			if (array_is_list($rules) === true) {
				$lists[(string)$action] = $rules;
			}
		}

		return $lists;
	}//end ruleLists()

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

	/**
	 * Whom a rule names, for the message.
	 *
	 * @param array<string, mixed> $rule The rule.
	 *
	 * @return string The principal.
	 */
	private function principalOf(array $rule): string {
		if (is_string(($rule['group'] ?? null)) === true) {
			return sprintf('group "%s"', $rule['group']);
		}

		if (is_string(($rule['user'] ?? null)) === true) {
			return sprintf('user "%s"', $rule['user']);
		}

		return 'an unnamed principal';
	}//end principalOf()
}//end class
