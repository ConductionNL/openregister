<?php

/**
 * What one deployment applies: its changes, its name and where it comes from.
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

use DateTime;

/**
 * The changes a deployment applies, under one name, from one origin.
 *
 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
 */
final class DeploymentPlan {
	/**
	 * Constructor.
	 *
	 * @param array<int, array<string, mixed>> $changes The changes, in order.
	 * @param string                           $name    The deployment's name.
	 * @param DeploymentOrigin                 $origin  The set or deployment it comes from.
	 */
	public function __construct(
		public readonly array $changes,
		public readonly string $name,
		public readonly DeploymentOrigin $origin,
	) {
	}//end __construct()

	/**
	 * The deployment row for this plan.
	 *
	 * @param string      $uuid       The new deployment's UUID.
	 * @param string|null $deployedBy Who deploys it.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function row(string $uuid, ?string $deployedBy): array {
		$origin = $this->origin;

		return [
			'uuid' => $uuid, 'name' => $this->name, 'setUuid' => $origin->setUuid, 'author' => $origin->author, 'approver' => $origin->approver,
			'deployedBy' => $deployedBy, 'deployedAt' => new DateTime(), 'restoresUuid' => $origin->restoresUuid,
			'changes' => $this->changes, 'state' => $origin->state(),
		];
	}//end row()
}//end class
