<?php

/**
 * The columns a rule's `match` keys may name.
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

use OCA\OpenRegister\Db\Schema;

/**
 * Resolves a rule's `match` key to a column of one schema's magic table.
 *
 * A key is known when it names a column the table is built with: a metadata
 * column, a linked-type column the schema declares, or a declared property.
 * Whatever a key holds, {@see columnOf()} answers only lowercase letters, digits
 * and underscores.
 *
 * @spec openspec/specs/row-field-level-security/spec.md
 */
class RuleColumns {

	/**
	 * The metadata columns of every magic table, as {@see \OCA\OpenRegister\Db\MagicMapper} builds them.
	 *
	 * @var array<int, string>
	 */
	public const METADATA = [
		'_id', '_uuid', '_slug', '_uri', '_version', '_register', '_schema', '_owner', '_organisation',
		'_application', '_folder', '_name', '_description', '_summary', '_image', '_size', '_schema_version',
		'_created', '_updated', '_last_user_activity', '_expires', '_files', '_relations', '_locked',
		'_authorization', '_validation', '_quality', '_deleted', '_archived', '_frozen', '_status', '_geo',
		'_retention', '_groups', '_tmlo',
	];

	/**
	 * The linked types that get a column, as {@see \OCA\OpenRegister\Db\MagicMapper} maps them.
	 *
	 * @var array<string, string>
	 */
	public const LINKED_TYPES = [
		'mail'     => '_mail',
		'contacts' => '_contacts',
		'notes'    => '_notes',
		'todos'    => '_todos',
		'calendar' => '_calendar',
		'talk'     => '_talk',
		'deck'     => '_deck',
	];

	/**
	 * Schema property entries the table builder does not turn into a column.
	 *
	 * @var array<int, string>
	 */
	private const NOT_COLUMNS = ['objectNameField', 'objectDescriptionField', 'objectSummaryField', 'required', '$schema', '$id'];

	/**
	 * What a property key has to look like to reach a column unchanged in meaning.
	 *
	 * @var string
	 */
	private const PROPERTY_KEY = '/^[A-Za-z0-9][A-Za-z0-9_-]*$/D';

	/**
	 * Constructor.
	 *
	 * @param Schema|null $schema The schema whose table is read; without one only metadata columns are known.
	 * @param string      $prefix The table alias with its dot, empty when unaliased.
	 */
	public function __construct(
		private readonly ?Schema $schema = null,
		public readonly string $prefix = '',
	) {
	}//end __construct()

	/**
	 * The column name a key renders to, for any key.
	 *
	 * @param string $key The `match` key.
	 *
	 * @return string The name, holding only lowercase letters, digits and underscores.
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md
	 */
	public function columnOf(string $key): string {
		$name = (new RecordMetadataConditions())->columnKey(key: $key);
		$name = strtolower((string)preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $name));
		$name = (string)preg_replace('/[^a-z0-9_]+/', '_', $name);

		return rtrim((string)preg_replace('/_+/', '_', $name), '_');
	}//end columnOf()

	/**
	 * Whether every key of a clause names a column of this table.
	 *
	 * @param array<int|string, mixed> $match The clause.
	 *
	 * @return bool True when the clause may compile.
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md
	 */
	public function admits(array $match): bool {
		foreach (array_keys($match) as $key) {
			if ($this->isKnown(key: (string)$key) === false) {
				return false;
			}
		}

		return true;
	}//end admits()

	/**
	 * Whether a save must refuse a condition on this key.
	 *
	 * Metadata keys are judged by {@see RecordMetadataConditions}. Without a
	 * schema, as for a register's rules, only the key's form can be judged.
	 *
	 * @param string $key The `match` key.
	 *
	 * @return bool True when the key is malformed or names no property of the schema.
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md
	 */
	public function refusesAtSave(string $key): bool {
		if ($this->namesMetadata(key: $key) === true) {
			return false;
		}

		if (preg_match(self::PROPERTY_KEY, $key) !== 1) {
			return true;
		}

		return $this->schema !== null && $this->isDeclared(key: $key) === false;
	}//end refusesAtSave()

	/**
	 * Whether a key names a column of this table.
	 *
	 * @param string $key The `match` key.
	 *
	 * @return bool True when it does.
	 */
	private function isKnown(string $key): bool {
		if ($this->namesMetadata(key: $key) === true) {
			return in_array($this->columnOf(key: $key), $this->metadataColumns(), true);
		}

		return $this->isDeclared(key: $key) && preg_match('/^[a-z0-9]/', $this->columnOf(key: $key)) === 1;
	}//end isKnown()

	/**
	 * Whether a key has the form of a metadata key.
	 *
	 * @param string $key The `match` key.
	 *
	 * @return bool True when it does.
	 */
	private function namesMetadata(string $key): bool {
		return str_starts_with($key, '_') === true || str_starts_with($key, '@self.') === true;
	}//end namesMetadata()

	/**
	 * Whether the schema declares this key as a property the table has a column for.
	 *
	 * @param string $key The `match` key.
	 *
	 * @return bool True when it does.
	 */
	private function isDeclared(string $key): bool {
		return in_array($key, self::NOT_COLUMNS, true) === false
			&& is_array(($this->schema?->getProperties()[$key] ?? null)) === true;
	}//end isDeclared()

	/**
	 * The metadata columns of this table, the schema's linked types included.
	 *
	 * @return array<int, string> The columns.
	 */
	private function metadataColumns(): array {
		$linked = array_filter(
			self::LINKED_TYPES,
			fn (string $type): bool => in_array($type, ($this->schema?->getLinkedTypes() ?? []), true),
			ARRAY_FILTER_USE_KEY
		);

		return array_merge(self::METADATA, array_values($linked));
	}//end metadataColumns()
}//end class
