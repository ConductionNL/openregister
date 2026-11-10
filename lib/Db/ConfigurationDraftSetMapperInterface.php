<?php

/**
 * ConfigurationDraftSetMapperInterface: what callers outside the component use of ConfigurationDraftSetMapper.
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

use OCP\AppFramework\Db\DoesNotExistException;

/**
 * The configuration draft sets the draft and deployment services read and write.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own. Bound to ConfigurationDraftSetMapper.
 */
interface ConfigurationDraftSetMapperInterface {

	/**
	 * Find a set by its uuid.
	 *
	 * @param string $uuid The set uuid.
	 *
	 * @return ConfigurationDraftSet The set.
	 *
	 * @throws DoesNotExistException When no such set exists.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function findByUuid(string $uuid): ConfigurationDraftSet;

	/**
	 * List sets, newest first, optionally filtered by state.
	 *
	 * @param string|null $state Optional state filter.
	 * @param integer     $limit How many rows at most.
	 *
	 * @return ConfigurationDraftSet[] The sets.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function findAllSets(?string $state = null, int $limit = 100): array;

	/**
	 * Create a set, assigning a uuid, the open state and timestamps.
	 *
	 * @param array<string, mixed> $data The set values.
	 *
	 * @return ConfigurationDraftSet The persisted set.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function createFromArray(array $data): ConfigurationDraftSet;

	/**
	 * Persist a set, refreshing the updated timestamp.
	 *
	 * @param ConfigurationDraftSet $set The set to persist.
	 *
	 * @return ConfigurationDraftSet The updated set.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function save(ConfigurationDraftSet $set): ConfigurationDraftSet;
}//end interface
