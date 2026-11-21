<?php

/**
 * OpenRegister DeploymentRecords
 *
 * Where a deployment is recorded: the history, the draft sets and the transaction around both.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
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

use OCA\OpenRegister\Db\ConfigurationDeploymentMapperInterface;
use OCA\OpenRegister\Db\ConfigurationDraftSetMapperInterface;
use OCP\IDBConnection;

/**
 * Where a deployment is recorded: the history, the draft sets and the transaction around both.
 *
 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
 */
final readonly class DeploymentRecords {
	/**
	 * Bundle the collaborators.
	 *
	 * @param ConfigurationDeploymentMapperInterface $deployments The append-only history.
	 * @param ConfigurationDraftSetMapperInterface   $sets        The draft sets.
	 * @param IDBConnection                          $db          The database, for the transaction.
	 */
	public function __construct(
		public ConfigurationDeploymentMapperInterface $deployments,
		public ConfigurationDraftSetMapperInterface $sets,
		public IDBConnection $db,
	) {
	}//end __construct()
}//end class
