<?php

/**
 * OpenRegister CaseCompletion
 *
 * A request to finish a case with a result: which case, which result, and who finishes it.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Case
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
 * @spec openspec/specs/flow-cases/spec.md#requirement-a-zaaktype-maps-to-a-case-skeleton-and-reports-what-it-could-not-map
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Case;

/**
 * The anchoring object, the result and the acting identity of one case completion.
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-a-zaaktype-maps-to-a-case-skeleton-and-reports-what-it-could-not-map
 */
final class CaseCompletion {

	/**
	 * Constructor.
	 *
	 * @param string      $objectUuid The anchoring object.
	 * @param string      $result     The result, from the plan's allowed set.
	 * @param string|null $actor      The acting identity.
	 */
	public function __construct(
		public readonly string $objectUuid,
		public readonly string $result,
		public readonly ?string $actor,
	) {
	}//end __construct()
}//end class
