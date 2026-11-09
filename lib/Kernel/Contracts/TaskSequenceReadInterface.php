<?php

/**
 * TaskSequenceReadInterface: what callers outside lib/Db read of a TaskSequence row.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

use DateTime;
use JsonSerializable;

/**
 * A task sequence: one ordered approval cycle on an object.
 *
 * Implemented by \OCA\OpenRegister\Db\TaskSequence. The column getters are served by
 * Nextcloud's Entity::__call, so they are listed as tags here. Read only: the
 * setters stay on the entity.
 *
 * @method int getId()
 * @method string|null getUuid()
 * @method string|null getTemplateId()
 * @method integer|null getTemplateVersion()
 * @method array|null getTemplateSnapshot()
 * @method string|null getAnchorObjectUuid()
 * @method integer|null getRegisterId()
 * @method integer|null getSchemaId()
 * @method string|null getChainKey()
 * @method string|null getRequesterId()
 * @method array|null getResolvedTier()
 * @method integer|null getPositionCursor()
 * @method string|null getStatus()
 * @method string|null getOutcome()
 * @method string|null getRunUuid()
 * @method string|null getNodeId()
 * @method DateTime|null getOpenedAt()
 * @method DateTime|null getClosedAt()
 */
interface TaskSequenceReadInterface extends JsonSerializable {

	/**
	 * Whether this sequence is in a terminal status.
	 *
	 * @return bool True when terminal.
	 *
	 * @spec openspec/specs/flow-approval-consolidation/spec.md#requirement-an-approval-is-an-ordered-task-sequence-with-one-position-enabled-at-a-time
	 */
	public function isTerminal(): bool;
}//end interface
