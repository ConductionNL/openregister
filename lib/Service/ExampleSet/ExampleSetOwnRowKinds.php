<?php

/**
 * OpenRegister Example Set Own Row Kinds
 *
 * The writers of the rows an example set keeps in OpenRegister's own engines: saved views, flows and case plans.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

use OCA\OpenRegister\Search\Service\ExampleSet\ExampleSetViewRowsInterface;

/**
 * The writers of the rows in OpenRegister's own engines, bundled for the engine kinds.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
 */
final readonly class ExampleSetOwnRowKinds {
	/**
	 * Bundle the writers; a writer left out refuses its rows.
	 *
	 * @param ExampleSetViewRowsInterface|null $views     Writes the saved views a fragment declares; null refuses them.
	 * @param ExampleSetFlows|null             $flows     Writes the flows a fragment ships; null refuses them.
	 * @param ExampleSetCasePlans|null         $casePlans Writes the case plans a fragment puts on its objects; null refuses them.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
	 */
	public function __construct(
		public ?ExampleSetViewRowsInterface $views=null,
		public ?ExampleSetFlows $flows=null,
		public ?ExampleSetCasePlans $casePlans=null,
	) {
	}//end __construct()

	/**
	 * The writers by fragment part; null where this OpenRegister has none.
	 *
	 * @return array<string, ExampleSetEngineRows|null>
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
	 */
	public function writers(): array {
		return [
			'views'     => $this->views?->rows(),
			'flows'     => $this->flows,
			'casePlans' => $this->casePlans,
		];
	}//end writers()
}//end class
