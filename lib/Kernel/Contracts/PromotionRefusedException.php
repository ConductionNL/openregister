<?php

/**
 * OpenRegister PromotionRefusedException
 *
 * A promotion refused by name, with the HTTP status it answers with: thrown on both sides of the Configuration boundary.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Kernel
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/configuration-as-a-deployment/tasks.md#8-promotion-between-environments-row-op-otap
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

use RuntimeException;

/**
 * A refused promotion: a reason word, a message, what was refused and the status.
 *
 * @spec openspec/specs/environment-otap/spec.md#requirement-configuration-promotion-must-transfer-settings-between-otap-environments
 */
final class PromotionRefusedException extends RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param string                  $reason     A short reason word (invalid, unknown, empty, state, ...).
	 * @param string                  $message    What was refused, by name.
	 * @param array<int, mixed>       $refusals   The refused items.
	 * @param integer                 $statusCode The HTTP status this refusal answers with.
	 */
	public function __construct(
		public readonly string $reason,
		string $message,
		public readonly array $refusals = [],
		public readonly int $statusCode = 409,
	) {
		parent::__construct(message: $message);

	}//end __construct()

	/**
	 * The response body: the reason, the message and the refused items.
	 *
	 * @return array{error: string, message: string, refusals: array<int, mixed>}
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/tasks.md#8-promotion-between-environments-row-op-otap
	 */
	public function toResponseBody(): array {
		return ['error' => $this->reason, 'message' => $this->getMessage(), 'refusals' => $this->refusals];

	}//end toResponseBody()
}//end class
