<?php

/**
 * How a raw manifest value is shown in a descriptor validation message.
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
 * Shared by MetricDescriptor and HealthCheckDescriptor, which both quote the
 * offending value when they refuse a manifest entry.
 *
 * @spec openspec/changes/apphost-observability-engine/tasks.md#task-3.3
 */
final class DescriptorValue {

	/**
	 * Render a raw (possibly non-scalar) value as a short string for an
	 * error message, without leaking object internals.
	 *
	 * @param mixed $value Raw value.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/apphost-observability-engine/tasks.md#task-3.3
	 */
	public static function describe(mixed $value): string {
		if (is_scalar($value) === true) {
			return (string)$value;
		}

		return gettype($value);
	}//end describe()
}//end class
