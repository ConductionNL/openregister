<?php

/**
 * OpenRegister lock stores
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
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/object-interactions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\MagicMapperInterface;

/**
 * The stores LockHandler reads and writes lock state through.
 *
 * @spec openspec/specs/object-interactions/spec.md
 */
final class LockStores {
	/**
	 * Bundle the stores.
	 *
	 * @param MagicMapperInterface      $magicMapper      Magic mapper for magic table operations
	 * @param AuditTrailMapperInterface $auditTrailMapper Audit trail mapper for logging actions
	 * @param AdvisoryLockStore         $advisory         Pre-creation locks, for identifiers that do not resolve to a stored object
	 * @param RunLockRegistry|null      $runLocks         Bookkeeping for run-held locks, so the terminal listener and the sweep can find them
	 *
	 * @spec openspec/specs/object-interactions/spec.md
	 */
	public function __construct(
		public readonly MagicMapperInterface $magicMapper,
		public readonly AuditTrailMapperInterface $auditTrailMapper,
		public readonly AdvisoryLockStore $advisory,
		public readonly ?RunLockRegistry $runLocks = null,
	) {
	}//end __construct()
}//end class
