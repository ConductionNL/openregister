<?php

/**
 * ActivityFeedObject: the object a merged activity feed is about.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/activity-leaf/specs/integration-activity/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration;

/**
 * Register slug, schema slug and uuid of the object whose feed is read.
 *
 * @spec openspec/changes/activity-leaf/specs/integration-activity/spec.md
 */
final class ActivityFeedObject {
	/**
	 * Constructor.
	 *
	 * @param string $register Register slug of the object.
	 * @param string $schema   Schema slug of the object.
	 * @param string $id       The object's uuid.
	 */
	public function __construct(
		public readonly string $register,
		public readonly string $schema,
		public readonly string $id,
	) {
	}//end __construct()
}//end class
