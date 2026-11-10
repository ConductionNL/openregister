<?php

/**
 * StateDeclarationScope: what a lifecycle `states` block is validated against.
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
 * The properties the schema declares and the states the lifecycle declares.
 *
 * Built and consumed by {@see LifecycleStateValidator} only.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class StateDeclarationScope {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed>    $properties The schema's declared properties.
	 * @param array<string, int>|null $enumSet    The declared states, or null when the mode does not publish them.
	 */
	public function __construct(
		public readonly array $properties,
		public readonly ?array $enumSet,
	) {
	}//end __construct()
}//end class
