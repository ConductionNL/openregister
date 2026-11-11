<?php

/**
 * ConfigurationDraftMapperInterface: what callers outside the component use of ConfigurationDraftMapper.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
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

namespace OCA\OpenRegister\Db;

use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationAddress;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * The configuration drafts the draft and deployment services read and write.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own. Bound to ConfigurationDraftMapper.
 */
interface ConfigurationDraftMapperInterface {

	/**
	 * Every pending value in one set, in the order they were written.
	 *
	 * @param string $setUuid The set uuid.
	 *
	 * @return ConfigurationDraft[] The drafts.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function findBySet(string $setUuid): array;

	/**
	 * The pending value one set holds for one address, when it holds one.
	 *
	 * @param string      $setUuid   The set uuid.
	 * @param string      $layer     The layer.
	 * @param string|null $layerRef  The layer reference, null at instance level.
	 * @param string      $configKey The configuration key.
	 *
	 * @return ConfigurationDraft|null The draft, or null when the set holds none.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function findAtAddress(
		string $setUuid,
		string $layer,
		?string $layerRef,
		string $configKey
	): ?ConfigurationDraft;

	/**
	 * The pending value one set holds for one address, when it holds one.
	 *
	 * @param string               $setUuid The set uuid.
	 * @param ConfigurationAddress $address The layer, layer reference and configuration key.
	 *
	 * @return ConfigurationDraft|null The draft, or null when the set holds none.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function findAt(string $setUuid, ConfigurationAddress $address): ?ConfigurationDraft;

	/**
	 * Find a draft by its uuid.
	 *
	 * @param string $uuid The draft uuid.
	 *
	 * @return ConfigurationDraft The draft.
	 *
	 * @throws DoesNotExistException When no such draft exists.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function findByUuid(string $uuid): ConfigurationDraft;

	/**
	 * Create a draft row, assigning a uuid and timestamps.
	 *
	 * @param array<string, mixed> $data The draft values.
	 *
	 * @return ConfigurationDraft The persisted draft.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function createFromArray(array $data): ConfigurationDraft;

	/**
	 * Persist a draft, refreshing the updated timestamp.
	 *
	 * @param ConfigurationDraft $draft The draft to persist.
	 *
	 * @return ConfigurationDraft The updated draft.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function save(ConfigurationDraft $draft): ConfigurationDraft;
}//end interface
