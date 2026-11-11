<?php

/**
 * OpenRegister OutboundTransportRun
 *
 * One outbound transport of one rule firing, as the dispatcher runs it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Notification
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

namespace OCA\OpenRegister\Notifications\Service\Notification;

/**
 * The firing and the transport declaration the outbound-transport steps share.
 *
 * Built and used only inside AnnotationNotificationDispatcher.
 *
 * @spec openspec/specs/notificatie-engine/spec.md#requirement-one-rule-reaches-a-person-and-an-integration-recorded-once-req-nrg-004
 */
final class OutboundTransportRun {

	/**
	 * The handler the transport names (DI tag or FQCN).
	 *
	 * @var string
	 */
	public readonly string $handler;

	/**
	 * The transport's `config` block, empty when it declares none.
	 *
	 * @var array<string, mixed>
	 */
	public readonly array $config;

	/**
	 * Constructor.
	 *
	 * @param RuleFiring           $firing    The rule and the event.
	 * @param array<string, mixed> $transport The transport declaration: its `handler` and `config`.
	 */
	public function __construct(
		public readonly RuleFiring $firing,
		array $transport,
	) {
		$this->handler = (string)($transport['handler'] ?? '');
		if (is_array(($transport['config'] ?? null)) === true) {
			$this->config = $transport['config'];
		} else {
			$this->config = [];
		}
	}//end __construct()
}//end class
