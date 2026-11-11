<?php

/**
 * OpenRegister App Plan Write
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
 * A plan write by an app's own in-process code: the anchor, the definition and the acting app id.
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-in-process-callers-act-as-a-named-app
 */
final readonly class AppPlanWrite {

	/**
	 * The write.
	 *
	 * @param CaseAnchor           $anchor     The anchoring object.
	 * @param array<string, mixed> $definition The definition (`settings`, `items`).
	 * @param string               $app        The acting app id.
	 */
	public function __construct(
		public CaseAnchor $anchor,
		public array $definition,
		public string $app,
	) {
	}//end __construct()
}//end class
