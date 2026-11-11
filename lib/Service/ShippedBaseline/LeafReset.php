<?php

/**
 * One leaf of a schema part going back to its shipped value, or away when it was never shipped.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ShippedBaseline
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/shipped-baseline-reset-is-reachable/specs/schema-import/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ShippedBaseline;

/**
 * The path segments of a leaf and the shipped value it is reset to.
 *
 * A null shipped value means the leaf was added locally and never shipped,
 * so resetting it removes it.
 *
 * @spec openspec/changes/shipped-baseline-reset-is-reachable/specs/schema-import/spec.md
 */
final class LeafReset {

	/**
	 * Constructor.
	 *
	 * @param array<int, string> $segments The path, top-level part first.
	 * @param mixed              $to       The shipped value, null when never shipped.
	 */
	public function __construct(
		public readonly array $segments,
		public readonly mixed $to,
	) {
	}//end __construct()

	/**
	 * Whether the reset removes the leaf rather than replacing it.
	 *
	 * @return bool True when the leaf was never shipped.
	 *
	 * @spec openspec/changes/shipped-baseline-reset-is-reachable/specs/schema-import/spec.md
	 */
	public function removes(): bool {
		return $this->to === null;
	}//end removes()

	/**
	 * The same reset one level down: the first segment dropped.
	 *
	 * @return self The reset below the first segment.
	 *
	 * @spec openspec/changes/shipped-baseline-reset-is-reachable/specs/schema-import/spec.md
	 */
	public function below(): self {
		return new self(segments: array_slice($this->segments, 1), to: $this->to);
	}//end below()
}//end class
