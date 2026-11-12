<?php

/**
 * OpenRegister TransitionPayload
 *
 * The payloads a transition's actions run against, threaded through the
 * action chain by `LifecycleActionExecutor`.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Lifecycle
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

namespace OCA\OpenRegister\Service\Lifecycle;

/**
 * One matched transition: its name, the new (@self) and the old (@previous) payload.
 *
 * Immutable: a self-mutating action yields a copy carrying its result.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class TransitionPayload {

	/**
	 * Constructor.
	 *
	 * @param string               $transition   The matched transition name, for diagnostics.
	 * @param array<string, mixed> $objectData   The new (@self) payload.
	 * @param array<string, mixed> $previousData The old (@previous) payload.
	 */
	public function __construct(
		public readonly string $transition,
		public readonly array $objectData,
		public readonly array $previousData,
	) {
	}//end __construct()

	/**
	 * A copy carrying the payload an action returned.
	 *
	 * @param array<string, mixed> $objectData The new (@self) payload.
	 *
	 * @return self The copy.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function withObjectData(array $objectData): self {
		return new self(transition: $this->transition, objectData: $objectData, previousData: $this->previousData);
	}//end withObjectData()

	/**
	 * The payload a condition scope reads: `previous` or, otherwise, `self`.
	 *
	 * @param string $scope The scope named in the condition.
	 *
	 * @return array<string, mixed> The payload.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function scope(string $scope): array {
		if ($scope === 'previous') {
			return $this->previousData;
		}

		return $this->objectData;
	}//end scope()
}//end class
