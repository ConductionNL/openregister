<?php

/**
 * RecipientLookup
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * What a flow step's recipient entries are resolved against: the item's json,
 * and whether the channel takes bare addresses (email) or treats them as
 * unknown (notifications).
 *
 * Built by {@see FlowRecipientAddresses::resolveRecipients()} once per item.
 *
 * @spec openspec/specs/flow-send-email-external-recipients/spec.md#requirement-a-send-email-step-reaches-an-address-only-as-far-as-the-step-allows
 */
final class RecipientLookup {

	/**
	 * Name the lookup.
	 *
	 * @param array<string, mixed> $json            The item's json.
	 * @param bool                 $acceptAddresses Whether addresses are candidates (email) or unknowns.
	 *
	 * @spec openspec/specs/flow-send-email-external-recipients/spec.md#requirement-a-send-email-step-reaches-an-address-only-as-far-as-the-step-allows
	 */
	public function __construct(
		public readonly array $json,
		public readonly bool $acceptAddresses,
	) {
	}//end __construct()
}//end class
