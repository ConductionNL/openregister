<?php

/**
 * Where a deployment comes from: a draft set, or the deployment it rolls back.
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

use OCA\OpenRegister\Db\ConfigurationDeployment;
use OCA\OpenRegister\Db\ConfigurationDraftSet;

/**
 * The provenance fields of one deployment row: the set it deploys (or none),
 * its author and approver, and the deployment it restores (or none).
 *
 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
 */
final class DeploymentOrigin {
	/**
	 * Constructor.
	 *
	 * @param string|null $setUuid      The deployed set, or null for a rollback.
	 * @param string|null $author       Who wrote the values.
	 * @param string|null $approver     Who approved them, if anyone.
	 * @param string|null $restoresUuid The deployment a rollback restores, or null.
	 */
	public function __construct(
		public readonly ?string $setUuid,
		public readonly ?string $author,
		public readonly ?string $approver,
		public readonly ?string $restoresUuid,
	) {
	}//end __construct()

	/**
	 * The origin of deploying a draft set: its author and approver.
	 *
	 * @param ConfigurationDraftSet $set The set.
	 *
	 * @return self
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public static function ofSet(ConfigurationDraftSet $set): self {
		return new self(setUuid: (string)$set->getUuid(), author: $set->getCreatedBy(), approver: $set->getApprovedBy(), restoresUuid: null);
	}//end ofSet()

	/**
	 * The origin of a rollback: the actor is the author, and nobody approves.
	 *
	 * @param string      $restoresUuid The deployment being rolled back.
	 * @param string|null $actor        The current user.
	 *
	 * @return self
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public static function rollbackOf(string $restoresUuid, ?string $actor): self {
		return new self(setUuid: null, author: $actor, approver: null, restoresUuid: $restoresUuid);
	}//end rollbackOf()

	/**
	 * The state a deployment from this origin is recorded in: rolled back for a rollback, applied otherwise.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function state(): string {
		if ($this->restoresUuid !== null) {
			return ConfigurationDeployment::STATE_ROLLED_BACK;
		}

		return ConfigurationDeployment::STATE_APPLIED;
	}//end state()
}//end class
