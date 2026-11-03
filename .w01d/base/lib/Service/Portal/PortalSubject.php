<?php

/**
 * The acting portal subject, as the portal's edge asserted it.
 *
 * A value object, built only from a VERIFIED assertion. Everything upstream of
 * it (DigiD, eHerkenning, eIDAS, the session) terminates at portaliq's edge;
 * OpenRegister sees a server-derived subject reference and the tenant and
 * trust it was resolved with, and nothing else.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Portal
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-only-the-matched-party-completes-fail-closed
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Portal;

use OCA\OpenRegister\Db\Task;

/**
 * One resolved portal subject.
 *
 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-only-the-matched-party-completes-fail-closed
 */
final class PortalSubject {

	/**
	 * Constructor.
	 *
	 * @param string $subjectRef The server-derived subject reference.
	 * @param string $audience The external audience (`client`, `supplier`, ...).
	 * @param string $organisation The tenant the subject is scoped to.
	 * @param string $trust The trust level (`low|substantial|high`).
	 * @param string $jti The originating session id, for audit correlation.
	 */
	public function __construct(
		public readonly string $subjectRef,
		public readonly string $audience = '',
		public readonly string $organisation = '',
		public readonly string $trust = '',
		public readonly string $jti = '',
	) {

	}//end __construct()

	/**
	 * The party reference this subject acts as: what is compared, whole, to
	 * a task's stored party reference.
	 *
	 * @return string `party:<subjectRef>`.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-only-the-matched-party-completes-fail-closed
	 */
	public function partyReference(): string {
		return Task::EXTERNAL_PARTY_PREFIX . $this->subjectRef;
	}//end partyReference()

	/**
	 * The identity recorded in the task audit for this subject's acts.
	 *
	 * @return string The party reference; the audit's `performer_type` says `external`.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-tasks/spec.md#requirement-the-external-performer-type-is-portal-scoped-and-never-pooled
	 */
	public function actor(): string {
		return $this->partyReference();
	}//end actor()

	/**
	 * Build a party reference for a resolved case party, the way the node
	 * freezes it on the task.
	 *
	 * @param string $reference The raw reference read from the case.
	 *
	 * @return string `party:<reference>`.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-the-matched-party-comes-from-the-case-and-is-frozen-at-creation
	 */
	public static function partyReferenceFor(string $reference): string {
		return Task::EXTERNAL_PARTY_PREFIX . trim($reference);
	}//end partyReferenceFor()
}//end class
