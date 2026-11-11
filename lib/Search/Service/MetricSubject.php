<?php

/**
 * OpenRegister metric subject
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Search\Service
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

namespace OCA\OpenRegister\Search\Service;

/**
 * What an operational metric is about: the entity it measured and the user who caused it.
 *
 * @spec openspec/specs/production-observability/spec.md#metrics-storage-strategy
 */
final class MetricSubject {
	/**
	 * The subject.
	 *
	 * @param string|null $entityType Entity type (file, object, etc.)
	 * @param string|null $entityId   Entity ID
	 * @param string|null $userId     User ID
	 *
	 * @spec openspec/specs/production-observability/spec.md#metrics-storage-strategy
	 */
	public function __construct(
		public readonly ?string $entityType = null,
		public readonly ?string $entityId = null,
		public readonly ?string $userId = null,
	) {
	}//end __construct()
}//end class
