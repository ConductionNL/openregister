<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/object-lifecycle/spec.md#requirement-declarative-per-transition-authorization-gate
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\Schema;

/**
 * The `authorization` list a lifecycle transition declares, with the schema whose roles it may name.
 *
 * @spec openspec/specs/object-lifecycle/spec.md#requirement-declarative-per-transition-authorization-gate
 */
final class TransitionGate {

	/**
	 * Constructor.
	 *
	 * @param array<int, mixed> $authorizationList Group ids / `{role}` entries permitted to perform the transition.
	 * @param Schema            $schema            Schema the transition belongs to (for named-role expansion).
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-declarative-per-transition-authorization-gate
	 */
	public function __construct(
		public readonly array $authorizationList,
		public readonly Schema $schema,
	) {
	}//end __construct()
}//end class
