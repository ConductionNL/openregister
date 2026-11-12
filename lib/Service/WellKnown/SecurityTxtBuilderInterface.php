<?php

/**
 * SecurityTxtBuilderInterface: the part of SecurityTxtBuilder that the well-known controller uses.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\WellKnown
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/api-surface-governance/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\WellKnown;

use DateTimeImmutable;

/**
 * The methods of SecurityTxtBuilder that callers outside its component use.
 * Callers depend on this interface, not on the class.
 */
interface SecurityTxtBuilderInterface {

	/**
	 * The file, or null when no contact is administered.
	 *
	 * @param DateTimeImmutable|null $now The moment to compute `Expires` from.
	 *
	 * @return string|null The file body, or null.
	 *
	 * @spec openspec/specs/api-surface-governance/spec.md#requirement-the-instance-answers-the-well-known-paths-and-honours-an-administered-proxy-req-avs-004
	 */
	public function build(?DateTimeImmutable $now = null): ?string;
}//end interface
