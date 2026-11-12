<?php

/**
 * OpenRegister LifecycleGraphCheck
 *
 * One validation of a lifecycle kept as data: the declared states and guard
 * catalogue it checks against, and the errors and touched states it collects.
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
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Lifecycle;

/**
 * What one {@see LifecycleTransitionsValidator::validate()} call checks against and has found.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class LifecycleGraphCheck {

	/**
	 * The errors found so far, in order.
	 *
	 * @var array<int, array{code: string, message: string}>
	 */
	private array $errors = [];

	/**
	 * The declared states some transition starts or ends in.
	 *
	 * @var array<string, true>
	 */
	private array $touched = [];

	/**
	 * The declared, non-empty state names.
	 *
	 * @var array<string, true>
	 */
	public readonly array $stateNames;

	/**
	 * Constructor.
	 *
	 * @param array<int, mixed>       $states      State names, or `{name}` objects.
	 * @param array<int, string>|null $knownGuards The guard catalogue, or null to shape-check guards only.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		array $states,
		public readonly ?array $knownGuards,
	) {
		$this->stateNames = self::collectStateNames(states: $states);
	}//end __construct()

	/**
	 * Record one error.
	 *
	 * @param string $code    Stable error code.
	 * @param string $message Human-readable message.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function addError(string $code, string $message): void {
		$this->errors[] = ['code' => $code, 'message' => $message];
	}//end addError()

	/**
	 * Record that a transition starts or ends in a state.
	 *
	 * @param string $state The declared state.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function touch(string $state): void {
		$this->touched[$state] = true;
	}//end touch()

	/**
	 * Whether some transition starts or ends in a state.
	 *
	 * @param string $state The state.
	 *
	 * @return bool True when one does.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function isTouched(string $state): bool {
		return isset($this->touched[$state]);
	}//end isTouched()

	/**
	 * The errors found, in order.
	 *
	 * @return array<int, array{code: string, message: string}> List of errors (empty = valid).
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function errors(): array {
		return $this->errors;
	}//end errors()

	/**
	 * Collect the declared, non-empty state names.
	 *
	 * @param array<int, mixed> $states State names, or `{name}` objects.
	 *
	 * @return array<string, true>
	 */
	private static function collectStateNames(array $states): array {
		$names = [];
		foreach ($states as $state) {
			if (is_array($state) === true) {
				$state = ($state['name'] ?? null);
			}

			if (is_string($state) === true && $state !== '') {
				$names[$state] = true;
			}
		}

		return $names;
	}//end collectStateNames()
}//end class
