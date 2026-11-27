<?php

/**
 * CurrentTokenGrantInterface: which token's grant is bound to this request.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
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
 * The id of the narrowed credential acting for the user, so the audit writer
 * (Compliance) names it without naming the Core TokenGrantSource (ADR-013
 * Rule 1). Implemented by Service\Rbac\TokenGrantSource and aliased to its one
 * shared instance.
 */
interface CurrentTokenGrantInterface {

	/**
	 * The id of the token whose grant is bound to this request.
	 *
	 * @return string|null The token id, or null when no grant with an id is bound.
	 *
	 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md#requirement-the-effective-grant-is-visible-and-writes-name-the-token
	 */
	public function currentTokenId(): ?string;
}//end interface
