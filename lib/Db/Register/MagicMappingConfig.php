<?php

/**
 * OpenRegister MagicMappingConfig
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db\Register
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\Register;

/**
 * Reads and edits the magic mapping settings in a register's configuration.
 *
 * Two formats are read:
 * - New format: { "schemas": { "<slug>": { "magicMapping": true } } }
 * - Legacy format: { "enableMagicMapping": true, "magicMappingSchemas": ["<slug>", "<id>"] }
 *
 * Edits write the new format and return the whole configuration.
 *
 * @spec exclude register plumbing moved out of the entity, not a capability of its own
 */
final class MagicMappingConfig {

	/**
	 * The register's configuration.
	 *
	 * @var array<mixed>
	 */
	private array $config;

	/**
	 * Constructor.
	 *
	 * @param array<mixed> $config The register's configuration.
	 */
	public function __construct(array $config) {
		$this->config = $config;
	}//end __construct()

	/**
	 * Whether a per-schema entry, or the legacy list, enables magic mapping.
	 *
	 * @param int         $schemaId   The schema id.
	 * @param string|null $schemaSlug The schema slug, when known.
	 *
	 * @return bool
	 *
	 * @spec exclude register plumbing moved out of the entity, not a capability of its own
	 */
	public function isEnabled(int $schemaId, ?string $schemaSlug): bool {
		foreach ($this->entries(schemaId: $schemaId, schemaSlug: $schemaSlug) as $entry) {
			if (($entry['magicMapping'] ?? false) === true) {
				return true;
			}
		}

		return $this->isListed(schemaId: $schemaId, schemaSlug: $schemaSlug);
	}//end isEnabled()

	/**
	 * The first per-schema entry decides; without one, an enabled schema creates its table.
	 *
	 * @param int         $schemaId   The schema id.
	 * @param string|null $schemaSlug The schema slug, when known.
	 *
	 * @return bool
	 *
	 * @spec exclude register plumbing moved out of the entity, not a capability of its own
	 */
	public function isAutoCreateTable(int $schemaId, ?string $schemaSlug): bool {
		$entries = $this->entries(schemaId: $schemaId, schemaSlug: $schemaSlug);
		if ($entries !== []) {
			return ($entries[0]['autoCreateTable'] ?? false) === true;
		}

		return $this->isEnabled(schemaId: $schemaId, schemaSlug: $schemaSlug);
	}//end isAutoCreateTable()

	/**
	 * The configuration with the schema's entry replaced.
	 *
	 * @param int          $schemaId The schema id.
	 * @param array<mixed> $entry    The new entry, see entry().
	 *
	 * @return array<mixed>
	 *
	 * @spec exclude register plumbing moved out of the entity, not a capability of its own
	 */
	public function with(int $schemaId, array $entry): array {
		$config = $this->config;
		if (isset($config['schemas']) === false) {
			$config['schemas'] = [];
		}

		$config['schemas'][$schemaId] = $entry;

		return $config;
	}//end with()

	/**
	 * The configuration with the schema's entry switched off, or null when it has no entry.
	 *
	 * @param int $schemaId The schema id.
	 *
	 * @return array<mixed>|null
	 *
	 * @spec exclude register plumbing moved out of the entity, not a capability of its own
	 */
	public function withDisabled(int $schemaId): ?array {
		if (isset($this->config['schemas'][$schemaId]) === false) {
			return null;
		}

		$config = $this->config;
		$config['schemas'][$schemaId]['magicMapping'] = false;

		return $config;
	}//end withDisabled()

	/**
	 * The ids of the per-schema entries that enable magic mapping.
	 *
	 * @return int[]
	 *
	 * @psalm-return list<int>
	 *
	 * @spec exclude register plumbing moved out of the entity, not a capability of its own
	 */
	public function enabledSchemaIds(): array {
		$schemaIds = [];
		foreach (($this->config['schemas'] ?? []) as $schemaId => $schemaConfig) {
			if (($schemaConfig['magicMapping'] ?? false) === true) {
				$schemaIds[] = (int)$schemaId;
			}
		}

		return $schemaIds;
	}//end enabledSchemaIds()

	/**
	 * A per-schema entry that enables magic mapping.
	 *
	 * @param bool        $autoCreateTable Whether to create the table.
	 * @param string|null $comment         Why magic mapping is enabled, when given.
	 *
	 * @return array<string, bool|string>
	 *
	 * @spec exclude register plumbing moved out of the entity, not a capability of its own
	 */
	public function entry(bool $autoCreateTable, ?string $comment): array {
		$entry = ['magicMapping' => true, 'autoCreateTable' => $autoCreateTable];
		if ($comment !== null) {
			$entry['comment'] = $comment;
		}

		return $entry;
	}//end entry()

	/**
	 * The schema's per-schema entries, by slug first and then by id, when present.
	 *
	 * @param int         $schemaId   The schema id.
	 * @param string|null $schemaSlug The schema slug, when known.
	 *
	 * @return list<mixed>
	 */
	private function entries(int $schemaId, ?string $schemaSlug): array {
		$schemaConfigs = ($this->config['schemas'] ?? []);
		if (empty($schemaConfigs) === true) {
			return [];
		}

		$bySlug = null;
		if ($schemaSlug !== null) {
			$bySlug = ($schemaConfigs[$schemaSlug] ?? null);
		}

		return array_values(array_filter([$bySlug, ($schemaConfigs[$schemaId] ?? null)], static fn (mixed $entry): bool => $entry !== null));
	}//end entries()

	/**
	 * Whether the legacy format enables magic mapping and lists the schema by id or slug.
	 *
	 * @param int         $schemaId   The schema id.
	 * @param string|null $schemaSlug The schema slug, when known.
	 *
	 * @return bool
	 */
	private function isListed(int $schemaId, ?string $schemaSlug): bool {
		if (($this->config['enableMagicMapping'] ?? false) !== true) {
			return false;
		}

		$listed = ($this->config['magicMappingSchemas'] ?? []);

		return in_array((string)$schemaId, $listed, true) === true
			|| ($schemaSlug !== null && in_array($schemaSlug, $listed, true) === true);
	}//end isListed()
}//end class
