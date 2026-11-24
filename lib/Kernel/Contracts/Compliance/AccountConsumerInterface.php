<?php
/**
 * AccountConsumerInterface: the API consumer an account is, for the audit trail's token attribution.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Compliance
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Compliance;

use OCA\OpenRegister\Service\Audit\NamedIdentifier;

/**
 * Names the one consumer an account is registered as.
 *
 * The token attribution (Compliance) asks for the owner of the calling app
 * password; the consumer register (Integrations) answers and is bound to this
 * contract.
 *
 * @spec openspec/specs/enhanced-audit-trail/spec.md
 */
interface AccountConsumerInterface {

	/**
	 * The consumer the account is, when exactly one consumer names it; null otherwise.
	 *
	 * Null as well when the lookup fails: an audit row is never refused for it.
	 *
	 * @param string $ownerUid The account's user id.
	 *
	 * @return NamedIdentifier|null The consumer's uuid and name, or null.
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	public function soleConsumerOf(string $ownerUid): ?NamedIdentifier;
}//end interface
