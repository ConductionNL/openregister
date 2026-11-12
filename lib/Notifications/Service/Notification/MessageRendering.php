<?php

/**
 * What a notification says and where it links to.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Notifications\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Notification;

use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\DeepLinkResolverInterface;
use OCP\IURLGenerator;

/**
 * The units that compose what a recipient reads: the dialect's placeholder
 * evaluator, and the URL generator, deeplink registry and object resolver
 * that turn an object or a relation into a link.
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */
final readonly class MessageRendering {
	/**
	 * Constructor.
	 *
	 * @param NotificationTemplating     $templating The shared placeholder evaluator.
	 * @param ?IURLGenerator             $urlGenerator URL generator for action-target deeplinks.
	 * @param ?ObjectOperationsInterface $objectService Object resolver for relation-target deeplinks (RBAC).
	 * @param ?DeepLinkResolverInterface $deepLinkRegistry Canonical per-app deeplink resolver.
	 */
	public function __construct(
		public NotificationTemplating $templating,
		public ?IURLGenerator $urlGenerator = null,
		public ?ObjectOperationsInterface $objectService = null,
		public ?DeepLinkResolverInterface $deepLinkRegistry = null,
	) {
	}//end __construct()
}//end class
