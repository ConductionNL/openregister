<?php

/**
 * WriteConstraintCheck
 *
 * This file is part of the OpenRegister app for Nextcloud.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

/**
 * Compares a write with the stored object for the readOnly and immutable keywords.
 *
 * @category  Service
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */
final class WriteConstraintCheck {
	/**
	 * Start a comparison.
	 *
	 * @param array $incoming The candidate object data
	 * @param array $stored   The previously-stored object data
	 */
	public function __construct(
		private readonly array $incoming,
		private readonly array $stored,
	) {
	}//end __construct()

	/**
	 * A violation for each named property the write changes.
	 *
	 * @param list<string> $names The properties declared readOnly
	 *
	 * @return array<int, array{property: string, attempted: mixed, stored: mixed, message: string}>
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function readOnlyViolations(array $names): array {
		$violations = [];
		foreach ($names as $name) {
			if ($this->changes(name: $name) === true) {
				$violations[] = $this->violation(name: $name, message: "Property '%s' is declared readOnly and cannot be modified after creation.");
			}
		}

		return $violations;
	}//end readOnlyViolations()

	/**
	 * A violation for each named property that holds a value and that the write changes.
	 *
	 * @param list<string> $names The properties declared immutable
	 *
	 * @return array<int, array{property: string, attempted: mixed, stored: mixed, message: string}>
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function immutableViolations(array $names): array {
		$violations = [];
		foreach ($names as $name) {
			if (in_array(($this->stored[$name] ?? null), [null, ''], true) === false && $this->changes(name: $name) === true) {
				$violations[] = $this->violation(name: $name, message: "Property '%s' is immutable once set and cannot be changed.");
			}
		}

		return $violations;
	}//end immutableViolations()

	/**
	 * Whether the write names the property with another value.
	 *
	 * @param string $name The property
	 *
	 * @return bool True when it differs from what is stored
	 */
	private function changes(string $name): bool {
		return array_key_exists($name, $this->incoming) === true && $this->incoming[$name] !== ($this->stored[$name] ?? null);
	}//end changes()

	/**
	 * One violation.
	 *
	 * @param string $name    The property
	 * @param string $message The sprintf template
	 *
	 * @return array{property: string, attempted: mixed, stored: mixed, message: string}
	 */
	private function violation(string $name, string $message): array {
		return [
			'property'  => $name,
			'attempted' => $this->incoming[$name],
			'stored'    => ($this->stored[$name] ?? null),
			'message'   => sprintf($message, $name),
		];
	}//end violation()
}//end class
