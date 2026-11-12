<?php

/**
 * The claims a verified portal assertion carries beside its subject.
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

/**
 * The audience, tenant, trust and session of one verified portal assertion.
 *
 * Built only from a VERIFIED assertion, alongside the subject it names.
 *
 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-only-the-matched-party-completes-fail-closed
 */
final readonly class PortalSubjectClaims {

	/**
	 * Constructor.
	 *
	 * @param string $audience The external audience (`client`, `supplier`, ...).
	 * @param string $organisation The tenant the subject is scoped to.
	 * @param string $trust The trust level (`low|substantial|high`).
	 * @param string $jti The originating session id, for audit correlation.
	 */
	public function __construct(
		public string $audience = '',
		public string $organisation = '',
		public string $trust = '',
		public string $jti = '',
	) {
	}//end __construct()
}//end class
