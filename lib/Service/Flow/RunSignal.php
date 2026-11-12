<?php

/**
 * One answer to a suspended run: what the signaller says, who says it and,
 * when known, which node it addresses.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-engine-consumer-seams/spec.md#requirement-a-server-side-signal-passes-the-same-guard-as-the-http-resume
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * A signal for a suspended run, on behalf of an actor.
 *
 * @spec openspec/specs/flow-engine-consumer-seams/spec.md#requirement-a-server-side-signal-passes-the-same-guard-as-the-http-resume
 */
final class RunSignal {
	/**
	 * Describe the signal.
	 *
	 * @param array       $payload  What the signaller wants the run to know.
	 * @param string|null $actorUid Who is answering; null or '' means anonymous.
	 * @param string|null $nodeId   The node the answer addresses, when known.
	 *
	 * @spec openspec/specs/flow-engine-consumer-seams/spec.md#requirement-a-server-side-signal-passes-the-same-guard-as-the-http-resume
	 */
	public function __construct(
		public readonly array $payload,
		public readonly ?string $actorUid,
		public readonly ?string $nodeId = null,
	) {
	}//end __construct()

	/**
	 * The acting uid; an empty actor is an anonymous one.
	 *
	 * @return string|null The trimmed uid, or null when there is effectively none.
	 *
	 * @spec openspec/specs/flow-engine-consumer-seams/spec.md#requirement-a-server-side-signal-passes-the-same-guard-as-the-http-resume
	 */
	public function actor(): ?string {
		if ($this->actorUid === null || trim($this->actorUid) === '') {
			return null;
		}

		return trim($this->actorUid);
	}//end actor()
}//end class
