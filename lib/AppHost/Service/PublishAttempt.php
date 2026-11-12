<?php

/**
 * One publish to a store registry: the store and the slug that was sent.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\AppHost\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost\Service;

/**
 * What a publish sent, to check the registry's answer against
 * (ADR-011 Rule 2).
 *
 * @spec openspec/specs/apphost-store-plane/spec.md#requirement-a-publish-must-verify-the-slug-the-registry-stored
 */
final class PublishAttempt {
	/**
	 * Constructor.
	 *
	 * @param StoreDescriptor $descriptor The calling app's store parameters.
	 * @param string          $slug       The slug that was sent.
	 */
	public function __construct(
		public readonly StoreDescriptor $descriptor,
		public readonly string $slug,
	) {
	}//end __construct()
}//end class
