<?php

/**
 * ExternalRecipientPolicy
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
 * How far one send-email step may reach outside the instance: its
 * external-recipient mode, and — in the object mode — the addresses the item
 * itself carries.
 *
 * Built by {@see FlowRecipientAddresses::screenAddresses()} once per item.
 *
 * @spec openspec/specs/flow-send-email-external-recipients/spec.md#requirement-a-send-email-step-reaches-an-address-only-as-far-as-the-step-allows
 */
final class ExternalRecipientPolicy {

	/**
	 * Name the policy.
	 *
	 * @param string              $mode   The external-recipient mode (none, object or any).
	 * @param array<string, true> $onItem The item's addresses, lower-cased (object mode only).
	 *
	 * @spec openspec/specs/flow-send-email-external-recipients/spec.md#requirement-a-send-email-step-reaches-an-address-only-as-far-as-the-step-allows
	 */
	public function __construct(
		public readonly string $mode,
		public readonly array $onItem,
	) {
	}//end __construct()

	/**
	 * Why this address is refused, or '' when it is allowed.
	 *
	 * Order matters: an invalid address is named as invalid before the mode is
	 * consulted, so the refusal reason a person reads is the most specific one.
	 *
	 * @param string $address The trimmed address.
	 *
	 * @return string The refusal reason, or '' when the address is allowed.
	 *
	 * @spec openspec/specs/flow-send-email-external-recipients/spec.md#requirement-a-send-email-step-reaches-an-address-only-as-far-as-the-step-allows
	 */
	public function refusalFor(string $address): string {
		if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
			return FlowMessagingService::REFUSED_INVALID_ADDRESS;
		}

		if ($this->mode === FlowMessagingService::EXTERNAL_NONE) {
			return FlowMessagingService::REFUSED_EXTERNAL_OFF;
		}

		if ($this->mode === FlowMessagingService::EXTERNAL_OBJECT && isset($this->onItem[strtolower($address)]) === false) {
			return FlowMessagingService::REFUSED_NOT_ON_ITEM;
		}

		return '';
	}//end refusalFor()
}//end class
