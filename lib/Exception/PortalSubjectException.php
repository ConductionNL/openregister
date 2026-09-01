<?php

/**
 * The portal seam could not resolve an acting portal subject.
 *
 * Carries a stable machine code beside the message so the wire can name the
 * refusal (`portal-subject-missing`, `-invalid`, `-expired`, `-unconfigured`)
 * without leaking what the verifier saw.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
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

namespace OCA\OpenRegister\Exception;

use RuntimeException;

/**
 * No resolvable portal subject: every such case is a denial.
 *
 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-only-the-matched-party-completes-fail-closed
 */
class PortalSubjectException extends RuntimeException {

	/**
	 * The refusal codes.
	 */
	public const CODE_MISSING = 'portal-subject-missing';

	public const CODE_INVALID = 'portal-subject-invalid';

	public const CODE_EXPIRED = 'portal-subject-expired';

	public const CODE_UNCONFIGURED = 'portal-subject-unconfigured';

	/**
	 * Constructor.
	 *
	 * @param string $refusal One of the CODE_* values.
	 * @param string $message What went wrong, for the log.
	 */
	public function __construct(
		private readonly string $refusal,
		string $message,
	) {
		parent::__construct($message);

	}//end __construct()

	/**
	 * The stable refusal code.
	 *
	 * @return string The code.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-only-the-matched-party-completes-fail-closed
	 */
	public function refusal(): string {
		return $this->refusal;
	}//end refusal()
}//end class
