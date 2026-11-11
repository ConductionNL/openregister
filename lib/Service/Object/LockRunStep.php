<?php

/**
 * LockRunStep: the flow run and node that take a run-scoped object lock.
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
 * Which flow run took a lock, and from which node, as LockHandler::lock() was told.
 *
 * @spec openspec/specs/object-interactions/spec.md
 */
final class LockRunStep {

	/**
	 * Constructor
	 *
	 * @param string|null $runUuid Flow run taking the lock, for a run-scoped lock.
	 * @param string|null $nodeId  Flow node that took it, recorded for the sweep.
	 */
	public function __construct(
		public readonly ?string $runUuid,
		public readonly ?string $nodeId,
	) {
	}//end __construct()
}//end class
