<?php

/**
 * LockRequest: what a caller asks for when it takes an object lock.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/object-interactions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

/**
 * The arguments of LockHandler::lock(), carried together through its steps.
 */
final class LockRequest {

	/**
	 * Constructor
	 *
	 * @param string      $identifier Object ID or UUID.
	 * @param string|null $process    What the lock is taken for.
	 * @param int|null    $duration   Lock duration in seconds.
	 * @param string|null $runUuid    Flow run taking the lock, for a run-scoped lock.
	 * @param string|null $nodeId     Flow node that took it, recorded for the sweep.
	 */
	public function __construct(
		public readonly string $identifier,
		public readonly ?string $process,
		public readonly ?int $duration,
		public readonly ?string $runUuid,
		public readonly ?string $nodeId,
	) {
	}//end __construct()
}//end class
