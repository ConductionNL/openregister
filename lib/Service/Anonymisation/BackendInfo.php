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
	 * Whether the backend is reachable per the latest probe.
	 *
	 * @var boolean
	 */
	public readonly bool $available;

	/**
	 * ISO-8601 timestamp of the latest probe, or null.
	 *
	 * @var string|null
	 */
	public readonly ?string $lastProbedAt;

	/**
	 * Latency of the latest probe in milliseconds, or null.
	 *
	 * @var integer|null
	 */
	public readonly ?int $latencyMs;

	/**
	 * Constructor.
	 *
	 * @param string      $name       The method enum (regex/presidio/openanonymiser/llm/hybrid).
	 * @param bool        $configured Whether the backend has usable configuration.
	 * @param ProbeResult $probe      The latest probe: reachability, when, and its latency.
	 * @param DetectorVersion|null $detector What the backend is built from, when reported.
	 * @param BackendStatement|null $statement What the backend does with the text: training use and retention.
	 */
	public function __construct(
		public readonly string $name,
		public readonly bool $configured,
		ProbeResult $probe,
		public readonly ?DetectorVersion $detector=null,
		public readonly ?BackendStatement $statement=null,
	) {
		$this->available = $probe->reachable;
		$this->lastProbedAt = $probe->probedAt;
		$this->latencyMs = $probe->latencyMs;
	}//end __construct()

	/**
	 * Serialise to a JSON-friendly array.
	 *
	 * @return array<string, mixed> name, available, configured, lastProbedAt and latencyMs; detector and statement when known.
	 *
	 * @spec openspec/changes/anonymisation-discloses-itself/specs/anonymisation-disclosure/spec.md#requirement-training-use-and-retention-are-stated-per-backend-req-adi-003
	 */
	public function jsonSerialize(): array {
		$info = [
			'name' => $this->name,
			'available' => $this->available,
			'configured' => $this->configured,
			'lastProbedAt' => $this->lastProbedAt,
			'latencyMs' => $this->latencyMs,
		];
		if ($this->detector !== null) {
			$info['detector'] = $this->detector->jsonSerialize();
		}

		if ($this->statement !== null) {
			$info['statement'] = $this->statement->jsonSerialize();
		}

		return $info;
	}//end jsonSerialize()
}//end class
