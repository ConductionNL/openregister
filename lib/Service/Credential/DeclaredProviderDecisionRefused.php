<?php

/**
 * DeclaredProviderDecisionRefused — an administrator decision that cannot be recorded.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Credential
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/app-declared-credential-providers/specs/app-declared-credential-providers/spec.md#requirement-approval-decisions-are-recorded-in-the-audit-trail-with-the-deciding-user
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Credential;

use RuntimeException;

/**
 * Carries the HTTP status a refused decision answers with; the message is static and secret-free.
 *
 * @spec openspec/changes/app-declared-credential-providers/specs/app-declared-credential-providers/spec.md#requirement-approval-decisions-are-recorded-in-the-audit-trail-with-the-deciding-user
 */
class DeclaredProviderDecisionRefused extends RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param string $message The static reason.
	 * @param int    $status  The HTTP status to answer with.
	 *
	 * @spec openspec/changes/app-declared-credential-providers/specs/app-declared-credential-providers/spec.md#requirement-approval-decisions-are-recorded-in-the-audit-trail-with-the-deciding-user
	 */
	public function __construct(string $message, private readonly int $status) {
		parent::__construct(message: $message);
	}//end __construct()

	/**
	 * The HTTP status to answer with.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/app-declared-credential-providers/specs/app-declared-credential-providers/spec.md#requirement-approval-decisions-are-recorded-in-the-audit-trail-with-the-deciding-user
	 */
	public function getStatus(): int {
		return $this->status;
	}//end getStatus()
}//end class
