<?php

/**
 * OpenRegister Anonymisation Backend Info
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  ValueObject
 * @package   OCA\OpenRegister\Service\Anonymisation
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Anonymisation;

use JsonSerializable;

/**
 * Per-method availability and configuration record.
 *
 * The two flags are independent: a backend MAY be configured but unreachable
 * (latest probe failed), or available but not configured (e.g. an installed
 * ExApp without a matching operator-selected method).
 *
 * @category ValueObject
 * @package  OCA\OpenRegister\Service\Anonymisation
 */
final class BackendInfo implements JsonSerializable {
	/**
	 * Constructor.
	 *
	 * @param string $name The method enum (regex/presidio/openanonymiser/llm/hybrid).
	 * @param bool $available Whether the backend is reachable per the latest probe.
	 * @param bool $configured Whether the backend has usable configuration.
	 * @param string|null $lastProbedAt ISO-8601 timestamp of the latest probe, or null.
	 * @param int|null $latencyMs Latency of the latest probe in milliseconds, or null.
	 */
	public function __construct(
		public readonly string $name,
		public readonly bool $available,
		public readonly bool $configured,
		public readonly ?string $lastProbedAt,
		public readonly ?int $latencyMs,
	) {
	}//end __construct()

	/**
	 * Serialise to a JSON-friendly array.
	 *
	 * @return array{name: string, available: bool, configured: bool, lastProbedAt: string|null, latencyMs: int|null}
	 */
	public function jsonSerialize(): array {
		return [
			'name' => $this->name,
			'available' => $this->available,
			'configured' => $this->configured,
			'lastProbedAt' => $this->lastProbedAt,
			'latencyMs' => $this->latencyMs,
		];
	}//end jsonSerialize()
}//end class
