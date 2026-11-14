<?php

/**
 * OpenRegister webhook ownership services
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/webhooks-for-owners/specs/webhook-payload-mapping/spec.md#requirement-a-person-with-the-right-may-own-webhook-subscriptions
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

use OCA\OpenRegister\Service\OpenRegisterActionAuthService;
use OCA\OpenRegister\Service\Webhook\OwnedWebhookValidator;

/**
 * What the webhooks API needs to let a person own webhooks: the action
 * rights that hold `webhook.own` and the check of an owned subscription.
 *
 * @spec openspec/changes/webhooks-for-owners/specs/webhook-payload-mapping/spec.md#requirement-a-person-with-the-right-may-own-webhook-subscriptions
 */
final class WebhookOwnership {
	/**
	 * Bundle the services.
	 *
	 * @param OpenRegisterActionAuthService $actionAuth The action-rights matrix.
	 * @param OwnedWebhookValidator         $validator  Checks an owned webhook's subscription.
	 *
	 * @spec openspec/changes/webhooks-for-owners/specs/webhook-payload-mapping/spec.md#requirement-a-person-with-the-right-may-own-webhook-subscriptions
	 */
	public function __construct(
		public readonly OpenRegisterActionAuthService $actionAuth,
		public readonly OwnedWebhookValidator $validator,
	) {
	}//end __construct()
}//end class
