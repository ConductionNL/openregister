<?php

/**
 * OpenRegister CasePlanAct
 *
 * One verb a caller performs on a case plan, and who performs it.
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
 * @spec openspec/specs/flow-cases/spec.md#requirement-a-caseworker-may-attach-work-no-author-drew
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Case;

/**
 * The verb and the acting identity one authorization decision is about.
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-a-caseworker-may-attach-work-no-author-drew
 */
final class CasePlanAct {

	/**
	 * Constructor.
	 *
	 * @param string      $verb  The verb (transition, enable, attach).
	 * @param string|null $actor The acting identity.
	 */
	public function __construct(
		public readonly string $verb,
		public readonly ?string $actor,
	) {
	}//end __construct()
}//end class
