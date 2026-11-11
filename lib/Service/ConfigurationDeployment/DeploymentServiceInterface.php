<?php

/**
 * DeploymentServiceInterface: what callers outside the component use of DeploymentService.
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
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ConfigurationDeployment;

use OCA\OpenRegister\Db\ConfigurationDeployment;

/**
 * Deploy a draft set, roll a deployment back, and read the history.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface DeploymentServiceInterface {

	/**
	 * Deploy a draft set.
	 *
	 * @param string      $setUuid The set to deploy.
	 * @param string|null $name    The name the deployment is recorded under.
	 *
	 * @return ConfigurationDeployment The recorded deployment.
	 *
	 * @throws DeploymentRefusedException When the set refuses, in full, before anything is written.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function deploy(string $setUuid, ?string $name = null): ConfigurationDeployment;

	/**
	 * Roll a deployment back, recorded as a deployment of its own.
	 *
	 * @param string      $deploymentUuid The deployment to restore the values of.
	 * @param string|null $name           The name the rollback is recorded under.
	 *
	 * @return ConfigurationDeployment The recorded rollback.
	 *
	 * @throws DeploymentRefusedException When there is nothing to restore.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function rollback(string $deploymentUuid, ?string $name = null): ConfigurationDeployment;

	/**
	 * Read a deployment back, or refuse by name.
	 *
	 * @param string $uuid The deployment uuid.
	 *
	 * @return ConfigurationDeployment The deployment.
	 *
	 * @throws DeploymentRefusedException When no such deployment exists.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function loadDeployment(string $uuid): ConfigurationDeployment;

	/**
	 * The deployment history, newest first.
	 *
	 * @param integer $limit How many at most.
	 *
	 * @return ConfigurationDeployment[] The history.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function history(int $limit = 100): array;
}//end interface
