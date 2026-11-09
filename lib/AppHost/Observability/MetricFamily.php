<?php

/**
 * OpenRegister AppHost — Metric Family
 *
 * The exposition identity of one Prometheus metric family: the name, the
 * TYPE line and the HELP line a renderer prints above its samples.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category ValueObject
 * @package  OCA\OpenRegister\AppHost\Observability
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

namespace OCA\OpenRegister\AppHost\Observability;

/**
 * Name, Prometheus type and HELP text of one metric family.
 *
 * @psalm-immutable
 */
final class MetricFamily {
	/**
	 * Constructor.
	 *
	 * @param string      $name Metric name (without the `{app}_` prefix).
	 * @param string      $type Prometheus type (gauge|counter).
	 * @param string|null $help HELP text.
	 */
	public function __construct(
		public readonly string $name,
		public readonly string $type,
		public readonly ?string $help = null,
	) {
	}//end __construct()
}//end class
