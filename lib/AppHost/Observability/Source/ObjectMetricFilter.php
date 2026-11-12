<?php

/**
 * ObjectMetricFilter: one declared filter of an objectCount/objectSum metric
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category AppHost
 * @package  OCA\OpenRegister\AppHost\Observability\Source
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost\Observability\Source;

/**
 * One operator applied to one object field, as an objectCount or objectSum
 * descriptor declares it under `filter`. Built and consumed by
 * {@see ObjectMetricSource} only.
 *
 * @spec openspec/specs/apphost-observability/spec.md
 */
final class ObjectMetricFilter {

	/**
	 * Constructor.
	 *
	 * @param string $field    Object field name.
	 * @param string $operator eq|neq|lt|lte|gt|gte|like; anything else adds nothing.
	 * @param mixed  $value    Raw value, which may be a `now`/`today` date token.
	 */
	public function __construct(
		public readonly string $field,
		public readonly string $operator,
		public readonly mixed $value,
	) {
	}//end __construct()
}//end class
