<?php

/**
 * OpenRegister portal task delivery request.
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
 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-delivery-rides-the-portal-contribution-surface-and-nothing-else
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Portal;

use OCA\OpenRegister\Kernel\Contracts\TaskInterface;

/**
 * One delivery request: a task's ask, re-ask or reminder, and the message
 * portaliq renders for it (ADR-011 Rule 2 operation object).
 *
 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-delivery-rides-the-portal-contribution-surface-and-nothing-else
 */
final class DeliveryRequest {

	/**
	 * Constructor.
	 *
	 * @param TaskInterface        $task    The external task.
	 * @param string               $kind    `ask`, `re-ask` or `reminder`.
	 * @param array<string, mixed> $message What portaliq renders.
	 */
	public function __construct(
		public readonly TaskInterface $task,
		public readonly string $kind,
		public readonly array $message,
	) {
	}//end __construct()
}//end class
