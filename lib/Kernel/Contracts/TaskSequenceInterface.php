<?php
/**
 * TaskSequenceInterface: what the sequence events need of a TaskSequence row.
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
 *
 * @spec openspec/specs/flow-approval-consolidation/spec.md#requirement-the-four-approval-events-are-replaced-by-a-named-complete-mapping
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

use JsonSerializable;

/**
 * An ordered sequence of approval tasks anchored on one object.
 *
 * Implemented by \OCA\OpenRegister\Db\TaskSequence. The sequence events
 * carry it in their shared base, so the base names no area above Kernel;
 * the column accessors are served by Nextcloud's Entity::__call and are
 * listed as tags here.
 *
 * @method int getId()
 * @method string|null getUuid()
 * @method string|null getAnchorObjectUuid()
 * @method string|null getStatus()
 *
 * @spec openspec/specs/flow-approval-consolidation/spec.md#requirement-the-four-approval-events-are-replaced-by-a-named-complete-mapping
 */
interface TaskSequenceInterface extends JsonSerializable {

	/**
	 * The sequence as a JSON-ready array.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/flow-approval-consolidation/spec.md#requirement-the-four-approval-events-are-replaced-by-a-named-complete-mapping
	 */
	public function jsonSerialize(): array;
}//end interface
