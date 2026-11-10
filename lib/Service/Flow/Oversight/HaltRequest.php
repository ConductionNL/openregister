<?php

/**
 * A request to halt one app's work for one organisation: which organisation,
 * which app, why, and which node types it stops.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Oversight
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/organisation-capability-halt/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Oversight;

/**
 * What engaging a halt asks for; {@see self::trimmed()} is the form a halt stores.
 *
 * @spec openspec/changes/organisation-capability-halt/specs/flow-engine/spec.md
 */
final class HaltRequest {
	/**
	 * Describe the request.
	 *
	 * @param string $organisation   The organisation uuid.
	 * @param string $app            The app whose work stops.
	 * @param string $reason         Why, in words a person reads later.
	 * @param string $nodeTypePrefix The node types it stops; '' means "<app>.".
	 *
	 * @spec openspec/changes/organisation-capability-halt/specs/flow-engine/spec.md
	 */
	public function __construct(
		public readonly string $organisation,
		public readonly string $app,
		public readonly string $reason,
		public readonly string $nodeTypePrefix = '',
	) {
	}//end __construct()

	/**
	 * The same request with every value trimmed.
	 *
	 * @return self The trimmed request.
	 *
	 * @spec openspec/changes/organisation-capability-halt/specs/flow-engine/spec.md
	 */
	public function trimmed(): self {
		return new self(organisation: trim($this->organisation), app: trim($this->app), reason: trim($this->reason), nodeTypePrefix: trim($this->nodeTypePrefix));
	}//end trimmed()

	/**
	 * The node-type prefix the halt stops: the declared one, or "<app>.".
	 *
	 * @return string The prefix.
	 *
	 * @spec openspec/changes/organisation-capability-halt/specs/flow-engine/spec.md
	 */
	public function prefix(): string {
		if ($this->nodeTypePrefix === '') {
			return $this->app . '.';
		}

		return $this->nodeTypePrefix;
	}//end prefix()
}//end class
