<?php

/**
 * OpenRegister Flow Messaging Overrides
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCA\OpenRegister\Notifications\Service\Notification\OptOutAuthorityInterface;

/**
 * The optional seams of a flow send: the ambient step frame and the opt-out authority; null leaves the default.
 *
 * @spec openspec/specs/flow-messaging-nodes/spec.md#requirement-flows-send-through-the-notification-subsystem-never-beside-it
 */
final readonly class FlowMessagingOverrides {

	/**
	 * Bundle the collaborators.
	 *
	 * @param FlowRunContext|null $runContext The ambient step frame, for the sending step's node id; null when a caller has no run.
	 * @param OptOutAuthorityInterface|null $optOut The opt-out seam; null builds one on the same dispatcher.
	 */
	public function __construct(
		public ?FlowRunContext $runContext = null,
		public ?OptOutAuthorityInterface $optOut = null,
	) {
	}//end __construct()
}//end class
