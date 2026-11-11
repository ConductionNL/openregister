<?php

/**
 * ConfigurationDeploymentMapperInterface: what callers outside the component use of ConfigurationDeploymentMapper.
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
 * The deployment history the deployment service and the explainer read and write.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own. Bound to ConfigurationDeploymentMapper.
 */
interface ConfigurationDeploymentMapperInterface {

	/**
	 * Find a deployment by its uuid.
	 *
	 * @param string $uuid The deployment uuid.
	 *
	 * @return ConfigurationDeployment The deployment.
	 *
	 * @throws DoesNotExistException When no such deployment exists.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function findByUuid(string $uuid): ConfigurationDeployment;

	/**
	 * The deployment history, newest first.
	 *
	 * @param integer $limit How many rows at most.
	 *
	 * @return ConfigurationDeployment[] The deployments.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function findHistory(int $limit = 100): array;

	/**
	 * Append a deployment to the history.
	 *
	 * @param array<string, mixed> $data The deployment values.
	 *
	 * @return ConfigurationDeployment The persisted deployment.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function createFromArray(array $data): ConfigurationDeployment;
}//end interface
