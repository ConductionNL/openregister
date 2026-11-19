<?php
/**
 * DecisionInterface: what readers of a decision outside lib/Db need of a Decision row.
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
 * One decision on one subject: a verdict, who decided it, when, and a note.
 *
 * Implemented by \OCA\OpenRegister\Db\Decision. The column accessors are
 * served by Nextcloud's Entity::__call, so they are listed as tags here.
 *
 * @method string|null getUuid()
 * @method string|null getSubjectType()
 * @method string|null getSubjectId()
 * @method string|null getVerdict()
 * @method string|null getDecidedBy()
 * @method DateTime|null getDecidedAt()
 * @method string|null getNote()
 * @method string|null getSupersedes()
 *
 * @spec openspec/changes/reviewer-owns-their-decisions/specs/decisions/spec.md#requirement-a-decision-is-its-own-object-req-dec-001
 */
interface DecisionInterface extends JsonSerializable {
	/**
	 * Serialise for the API.
	 *
	 * @return array<string, mixed> The decision as plain data.
	 *
	 * @spec openspec/changes/reviewer-owns-their-decisions/specs/decisions/spec.md#requirement-a-decision-is-its-own-object-req-dec-001
	 */
	public function jsonSerialize(): array;
}//end interface
