<?php

/**
 * OpenRegister Case Plan Provenance
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Case
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Case;

/**
 * Where the rows of one creation come from: the definition's flow and version, when any, and the origin every row gets.
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-item-state-is-stored-as-rows-never-as-an-encoded-blob
 */
final readonly class CasePlanProvenance {

	/**
	 * The provenance of a plan creation.
	 *
	 * @param string|null $flowUuid    The definition's flow, when any.
	 * @param int|null    $flowVersion The definition's flow version, when any.
	 * @param string|null $origin      The origin every row gets (`adhoc`), or null to derive it from each node.
	 */
	public function __construct(
		public ?string $flowUuid = null,
		public ?int $flowVersion = null,
		public ?string $origin = null,
	) {
	}//end __construct()
}//end class
