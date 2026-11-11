<?php

/**
 * OpenRegister TrustTuple
 *
 * The (entityType, attribute, sourceSystem) tuple a trust-configuration row is matched against.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Survivorship
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

namespace OCA\OpenRegister\Service\Survivorship;

/**
 * One (entityType, attribute, sourceSystem) tuple.
 *
 * @spec openspec/changes/mdm-survivorship-engine/tasks.md#1.1
 */
final class TrustTuple {
	/**
	 * Constructor.
	 *
	 * @param string $entityType   Entity type to match.
	 * @param string $attribute    Attribute name to match.
	 * @param string $sourceSystem Source system to match.
	 *
	 * @spec openspec/changes/mdm-survivorship-engine/tasks.md#1.1
	 */
	public function __construct(
		public readonly string $entityType,
		public readonly string $attribute,
		public readonly string $sourceSystem,
	) {
	}//end __construct()
}//end class
