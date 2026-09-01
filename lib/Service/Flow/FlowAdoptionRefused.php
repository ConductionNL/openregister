<?php

/**
 * Adopting a flow was refused, and the refusal says why.
 *
 * Adoption has exactly two refusals, and a client must tell them apart: "you
 * are not signed in" wants a session, "this flow already belongs to someone"
 * wants a conversation with that someone. Mirroring
 * {@see FlowLifecycleRefused}, the reason is machine-readable rather than
 * prose so the controller can answer the right status and the UI can offer
 * the right action.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-adoption/specs/flow-storage/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use RuntimeException;

/**
 * A refusal carrying the reason a flow could not be adopted.
 *
 * @spec openspec/changes/flow-adoption/specs/flow-storage/spec.md
 */
class FlowAdoptionRefused extends RuntimeException {
	/**
	 * There is no acting user to become the owner.
	 *
	 * @var string
	 */
	public const REASON_NO_ACTING_USER = 'no-acting-user';

	/**
	 * The flow already has a different owner; adoption is not a takeover.
	 *
	 * @var string
	 */
	public const REASON_ALREADY_OWNED = 'already-owned';

	/**
	 * Constructor.
	 *
	 * @param string $reason One of the REASON_* constants.
	 * @param string $message The human sentence.
	 */
	public function __construct(
		private readonly string $reason,
		string $message,
	) {
		parent::__construct($message);
	}//end __construct()

	/**
	 * The machine-readable reason.
	 *
	 * @return string One of the REASON_* constants.
	 *
	 * @spec openspec/changes/flow-adoption/specs/flow-storage/spec.md
	 */
	public function getReason(): string {
		return $this->reason;
	}//end getReason()
}//end class
