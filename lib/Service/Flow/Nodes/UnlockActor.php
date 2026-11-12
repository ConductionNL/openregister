<?php

/**
 * Who an unlock step releases locks as: the run that holds them and the identity it acts under.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Nodes
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/run-scoped-object-locking/specs/run-scoped-object-locking/spec.md#requirement-a-lock-step-takes-a-lock-or-parks-the-run-and-retries
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

use OCP\IUser;

/**
 * The run uuid the locks belong to and the run's acting identity.
 *
 * Built by {@see UnlockObjectNode::execute()} and never leaves that class.
 *
 * @spec openspec/changes/run-scoped-object-locking/specs/run-scoped-object-locking/spec.md#requirement-a-lock-step-takes-a-lock-or-parks-the-run-and-retries
 */
final class UnlockActor {
	/**
	 * Constructor.
	 *
	 * @param string $runUuid The run whose locks are released.
	 * @param IUser  $owner   The identity the release runs as.
	 */
	public function __construct(
		public readonly string $runUuid,
		public readonly IUser $owner,
	) {
	}//end __construct()
}//end class
