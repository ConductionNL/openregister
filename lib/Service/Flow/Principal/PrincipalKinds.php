<?php

/**
 * PrincipalKinds: which principal kinds answer to a bare name.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Principal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md#requirement-stored-assignee-strings-are-migrated-once-and-what-cannot-be-migrated-is-reported
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Principal;

/**
 * Tries a bare name under every registered type and reports which resolve it.
 *
 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md#requirement-stored-assignee-strings-are-migrated-once-and-what-cannot-be-migrated-is-reported
 */
final class PrincipalKinds implements PrincipalKindsInterface {

	/**
	 * Constructor.
	 *
	 * @param PrincipalResolverRegistryInterface $principals The registry.
	 */
	public function __construct(
		private readonly PrincipalResolverRegistryInterface $principals,
	) {

	}//end __construct()

	/**
	 * Which registered kinds answer to this name, right now.
	 *
	 * @param string $name The stored string.
	 *
	 * @return array<int, string> The types that resolve it, in a stable order.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) `PrincipalReference::from()` is
	 * a named constructor on a value object, which this rule cannot tell apart
	 * from a static call into a service.
	 *
	 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md#requirement-stored-assignee-strings-are-migrated-once-and-what-cannot-be-migrated-is-reported
	 */
	public function kindsAnsweringTo(string $name): array {
		$kinds = [];
		foreach ($this->principals->types() as $type) {
			$reference = PrincipalReference::from(value: ['type' => $type, 'id' => $name]);
			if ($reference === null) {
				continue;
			}

			if ($this->principals->resolve(reference: $reference) !== []) {
				$kinds[] = $type;
			}
		}

		return $kinds;

	}//end kindsAnsweringTo()
}//end class
