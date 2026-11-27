<?php

/**
 * ConsumerGrantsInterface: every Consumer token's holder and stored grant, for the lapse sweep.
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
 * Lets the Core token-grant lapse sweep read the Consumers (Integrations)
 * without naming the Consumer entity or its mapper (ADR-013 Rule 1).
 * Implemented by Service\Consumer\ConsumerGrantListing.
 */
interface ConsumerGrantsInterface {

	/**
	 * Every Consumer, as the facts the sweep needs.
	 *
	 * @return array<int, array{tokenId: string, holder: string, name: string, stored: mixed}>
	 *         tokenId: the Consumer uuid (else id); holder: the user it acts as ('' for none);
	 *         name: its name (else the token id); stored: its stored grant, as kept under TokenGrant::KEY.
	 *
	 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md#requirement-a-token-carries-a-required-end-date-and-is-warned-before-it-lapses-req-sat-003
	 */
	public function consumerGrants(): array;
}//end interface
