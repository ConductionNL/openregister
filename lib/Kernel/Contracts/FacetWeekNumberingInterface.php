<?php
/**
 * FacetWeekNumberingInterface: which week labels the facets of a set of schemas follow.
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

/**
 * Picks the week relabelling for the facets of a set of schemas.
 *
 * Implemented by \OCA\OpenRegister\Service\Object\FacetWeekNumbering and bound
 * in the Processes area's FlowWiring::BINDINGS.
 *
 * @spec openspec/changes/working-calendar-admin
 */
interface FacetWeekNumberingInterface {

	/**
	 * The week relabelling the schemas' shared calendar asks for.
	 *
	 * @param array<int, mixed> $schemas The schemas the facets cover.
	 *
	 * @return FacetWeekRelabelInterface|null Null keeps ISO weeks.
	 */
	public function labelsFor(array $schemas): ?FacetWeekRelabelInterface;
}//end interface
