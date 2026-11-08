<?php

/**
 * PortalSubjectAssertionInterface: the part of PortalSubjectAssertion that the portal task controller uses.
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

use OCA\OpenRegister\Exception\PortalSubjectException;
use OCP\IRequest;

/**
 * The methods of PortalSubjectAssertion that callers outside its component use.
 * Callers depend on this interface, not on the class.
 */
interface PortalSubjectAssertionInterface {

	/**
	 * The subject a request acts as.
	 *
	 * @param IRequest $request The incoming request.
	 *
	 * @return PortalSubject The verified subject.
	 *
	 * @throws PortalSubjectException When the header is missing, the verifier is
	 *                                unconfigured, or the token is invalid or expired.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-only-the-matched-party-completes-fail-closed
	 */
	public function resolve(IRequest $request): PortalSubject;
}//end interface
