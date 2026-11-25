<?php

/**
 * The state-history projection, as a store an anonymisation reaches.
 *
 * The projection keeps one interval per value a projected property held, so an
 * anonymised record whose former value sits in it is still findable by that
 * value ("was ever ..."). The intervals of every property the plan changed
 * leave with the value; the properties it kept keep their history.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/anonymising-as-an-archival-outcome/specs/retention-management/spec.md#requirement-anonymising-reaches-every-derived-copy-or-does-nothing-req-aao-003
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

use OCA\OpenRegister\Kernel\Contracts\ObjectPropertyHistoryInterface;

/**
 * Removes the history of the changed properties of one record.
 *
 * @spec openspec/changes/anonymising-as-an-archival-outcome/specs/retention-management/spec.md#requirement-anonymising-reaches-every-derived-copy-or-does-nothing-req-aao-003
 */
final class StateHistoryAnonymisationTarget implements AnonymisationTarget {

	/**
	 * Constructor.
	 *
	 * @param ObjectPropertyHistoryInterface $history The projection.
	 */
	public function __construct(
		private readonly ObjectPropertyHistoryInterface $history,
	) {
	}//end __construct()

	/**
	 * What this target is.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/anonymising-as-an-archival-outcome/specs/retention-management/spec.md#requirement-anonymising-reaches-every-derived-copy-or-does-nothing-req-aao-003
	 */
	public function name(): string {
		return 'the state-history projection';
	}//end name()

	/**
	 * Reach the projection without writing: a projection that cannot be read
	 * stops the act while the record is still whole.
	 *
	 * @param AnonymisationPlan $plan The plan.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/anonymising-as-an-archival-outcome/specs/retention-management/spec.md#requirement-anonymising-reaches-every-derived-copy-or-does-nothing-req-aao-003
	 */
	public function prepare(AnonymisationPlan $plan): void {
		$this->history->countForObjectProperties(objectUuid: $plan->objectUuid, properties: $plan->changedProperties);
	}//end prepare()

	/**
	 * Drop the intervals of the changed properties.
	 *
	 * @param AnonymisationPlan $plan The plan.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/anonymising-as-an-archival-outcome/specs/retention-management/spec.md#requirement-anonymising-reaches-every-derived-copy-or-does-nothing-req-aao-003
	 */
	public function apply(AnonymisationPlan $plan): void {
		$this->history->deleteForObjectProperties(objectUuid: $plan->objectUuid, properties: $plan->changedProperties);
	}//end apply()
}//end class
