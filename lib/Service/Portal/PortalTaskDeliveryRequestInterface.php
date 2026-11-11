<?php

/**
 * PortalTaskDeliveryRequestInterface: recording an ask, re-ask or reminder for portaliq.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Portal
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Portal;

use OCA\OpenRegister\Db\PortalTaskDelivery;
use OCA\OpenRegister\Kernel\Contracts\TaskInterface;

/**
 * What the portal task reminder listener needs of PortalTaskDeliveryService.
 * Bound to PortalTaskDeliveryService in ContractBindings, so it resolves to the same shared instance.
 */
interface PortalTaskDeliveryRequestInterface {

	/**
	 * Record one delivery request per channel for an external task.
	 *
	 * @param TaskInterface        $task    The external task.
	 * @param string               $kind    `ask`, `re-ask` or `reminder`.
	 * @param array<string, mixed> $message What portaliq renders.
	 *
	 * @return array<int, PortalTaskDelivery> The rows written (possibly none).
	 */
	public function request(TaskInterface $task, string $kind, array $message): array;

	/**
	 * The message an ask, re-ask or reminder carries to portaliq.
	 *
	 * @param TaskInterface $task   The external task.
	 * @param string|null   $reason The re-ask reason, when this is a re-ask.
	 *
	 * @return array<string, mixed> The message.
	 */
	public function messageFor(TaskInterface $task, ?string $reason = null): array;
}//end interface
