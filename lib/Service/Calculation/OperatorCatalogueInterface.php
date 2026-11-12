<?php

/**
 * OperatorCatalogueInterface: what callers outside the component use of OperatorCatalogue.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Calculation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Calculation;

/**
 * The operator catalogue an expression builder is generated from.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface OperatorCatalogueInterface {

	/**
	 * Every operator, in a shape an expression builder can render.
	 *
	 * @return array<int, array{op: string, category: string, arity: string,
	 *   operands: array<int, string>, result: string, description: string}> The catalogue rows.
	 *
	 * @spec openspec/specs/computed-fields/spec.md
	 */
	public function all(): array;

	/**
	 * The categories the catalogue groups its operators under.
	 *
	 * @return array<int, string> Unique category names in declaration order.
	 *
	 * @spec openspec/specs/computed-fields/spec.md
	 */
	public function categories(): array;
}//end interface
