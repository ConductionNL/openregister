<?php

/**
 * CodeRun: one call of authored code, as the code runner receives it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-code-step-in-a-sidecar/specs/flow-engine/spec.md#requirement-a-code-step-runs-authored-javascript-outside-nextcloud
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * The source, the mode, the items and the limits of one code step call.
 *
 * Built by the code node from a validated config, so every limit here is
 * already within the instance ceiling.
 *
 * @spec openspec/changes/flow-code-step-in-a-sidecar/specs/flow-engine/spec.md#requirement-a-code-step-runs-authored-javascript-outside-nextcloud
 */
final class CodeRun {
	/**
	 * Constructor.
	 *
	 * @param string             $source    The authored JavaScript.
	 * @param string             $mode      `perItem` or `allItems`.
	 * @param array<int, array>  $items     The step's input items, each `{json: {...}}`.
	 * @param int                $timeoutMs The time limit of the call.
	 * @param int                $memoryMb  The memory limit of the call.
	 * @param array<int, string> $egress    The hosts the code may call; empty for none.
	 */
	public function __construct(
		public readonly string $source,
		public readonly string $mode,
		public readonly array $items,
		public readonly int $timeoutMs,
		public readonly int $memoryMb,
		public readonly array $egress=[],
	) {
	}//end __construct()

	/**
	 * The request body the runner's `POST /run` reads.
	 *
	 * @return array{source: string, mode: string, items: array, limits: array{timeoutMs: int, memoryMb: int, egress: array<int, string>}}
	 *
	 * @spec openspec/changes/flow-code-step-in-a-sidecar/specs/flow-engine/spec.md#requirement-a-code-step-runs-authored-javascript-outside-nextcloud
	 */
	public function toRequest(): array {
		return [
			'source' => $this->source,
			'mode'   => $this->mode,
			'items'  => $this->items,
			'limits' => [
				'timeoutMs' => $this->timeoutMs,
				'memoryMb'  => $this->memoryMb,
				'egress'    => array_values($this->egress),
			],
		];
	}//end toRequest()
}//end class
