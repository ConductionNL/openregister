<?php

/**
 * Where a link to an object points: the owning app's page, else Open Register's own.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Notifications\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service;

use OCA\OpenRegister\Service\DeepLinkResolverInterface;
use OCP\IURLGenerator;

/**
 * Where a link to an object points: the owning app's page, else Open Register's own.
 *
 * Used by the notifier, the activity entries and the timeline search provider.
 *
 * @spec openspec/specs/notificatie-engine/spec.md#requirement-an-object-notification-must-link-to-the-object
 */
final class ObjectDeepLinks {
	/**
	 * Bundle the two link builders.
	 *
	 * @param IURLGenerator                  $urls     Builds Open Register's own routes and absolute urls.
	 * @param DeepLinkResolverInterface|null $resolver The owning app's detail route per register and schema;
	 *                                                 null only where the container cannot build one.
	 */
	public function __construct(
		public readonly IURLGenerator $urls,
		public readonly ?DeepLinkResolverInterface $resolver = null,
	) {
	}//end __construct()
}//end class
