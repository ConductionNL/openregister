<?php

/**
 * OpenRegister configuration drafts contract
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ConfigurationDeployment
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ConfigurationDeployment;

use OCA\OpenRegister\Db\ConfigurationDraft;
use OCA\OpenRegister\Db\ConfigurationDraftSet;

/**
 * The draft sets of pending configuration values: open, draft into, approve, discard.
 *
 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
 */
interface ConfigurationDraftsInterface {
	/**
	 * Whether this instance requires an approver other than the author.
	 *
	 * @return boolean True when four eyes are required.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function requiresFourEyes(): bool;

	/**
	 * Open a set.
	 *
	 * @param string      $name        The name an operator will recognise it by.
	 * @param string|null $description What it is for.
	 *
	 * @return ConfigurationDraftSet The opened set.
	 *
	 * @throws DeploymentRefusedException When the name is empty.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function openSet(string $name, ?string $description = null): ConfigurationDraftSet;

	/**
	 * Read a set back, or refuse by name.
	 *
	 * @param string $uuid The set uuid.
	 *
	 * @return ConfigurationDraftSet The set.
	 *
	 * @throws DeploymentRefusedException When no such set exists.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function loadSet(string $uuid): ConfigurationDraftSet;

	/**
	 * Draft one pending value into a set.
	 *
	 * @param ConfigurationDraftTarget $target The set to draft into and the address of the value.
	 * @param mixed                    $value  The pending value.
	 *
	 * @return ConfigurationDraft The pending value.
	 *
	 * @throws DeploymentRefusedException When the set is closed or the address is not draftable.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function draftValue(ConfigurationDraftTarget $target, mixed $value): ConfigurationDraft;

	/**
	 * Draft the removal of one value.
	 *
	 * @param ConfigurationDraftTarget $target The set to draft into and the address of the value.
	 *
	 * @return ConfigurationDraft The pending removal.
	 *
	 * @throws DeploymentRefusedException When the set is closed or the address is not draftable.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function draftRemoval(ConfigurationDraftTarget $target): ConfigurationDraft;

	/**
	 * The pending values in a set.
	 *
	 * @param string $setUuid The set uuid.
	 *
	 * @return ConfigurationDraft[] The pending values.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function draftsIn(string $setUuid): array;

	/**
	 * Approve a set for deployment; under four eyes the author may not approve their own set.
	 *
	 * @param string $setUuid The set to approve.
	 *
	 * @return ConfigurationDraftSet The approved set.
	 *
	 * @throws DeploymentRefusedException When the set is closed, empty, or self-approved under four eyes.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function approveSet(string $setUuid): ConfigurationDraftSet;

	/**
	 * Abandon a set without deploying it.
	 *
	 * @param string $setUuid The set to discard.
	 *
	 * @return ConfigurationDraftSet The discarded set.
	 *
	 * @throws DeploymentRefusedException When the set has already been deployed.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function discardSet(string $setUuid): ConfigurationDraftSet;

	/**
	 * List sets, newest first.
	 *
	 * @param string|null $state Optional state filter.
	 * @param integer     $limit How many at most.
	 *
	 * @return ConfigurationDraftSet[] The sets.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function listSets(?string $state = null, int $limit = 100): array;
}//end interface
