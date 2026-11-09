<?php

/**
 * ShareWalkRun — one walk of an object folder for the current user's shares.
 *
 * The share manager and the user travel together through the private walk
 * helpers of {@see SharesProvider}; the node being walked is the run's
 * progress. This run object never leaves the provider (ADR-011 Rule 3).
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration\Providers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/integration-shares/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration\Providers;

/**
 * The share manager, the user whose shares are read, and the node in hand.
 *
 * @spec openspec/specs/integration-shares/spec.md
 */
final class ShareWalkRun {

	/**
	 * The child node whose shares are being read.
	 *
	 * @var mixed
	 */
	public mixed $node = null;

	/**
	 * Constructor.
	 *
	 * @param object $shareManager The share manager.
	 * @param string $userId       The current user uid.
	 */
	public function __construct(
		public readonly object $shareManager,
		public readonly string $userId,
	) {
	}//end __construct()
}//end class
