<?php

/**
 * The state of one deployment while it applies: its UUID, its actor and the undo entries so far.
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

/**
 * One apply of DeploymentService: built by it and never handed out.
 *
 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
 */
final class DeploymentRun {

	/**
	 * The undo entries of the writes so far, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $undo = [];

	/**
	 * Constructor.
	 *
	 * @param string      $uuid  The deployment doing the writing.
	 * @param string|null $actor Who is doing the writing.
	 */
	public function __construct(
		public readonly string $uuid,
		public readonly ?string $actor,
	) {
	}//end __construct()
}//end class
