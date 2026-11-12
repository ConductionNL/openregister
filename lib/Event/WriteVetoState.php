<?php

/**
 * OpenRegister WriteVetoState
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Event
 * @package  OCA\OpenRegister\Event
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Event;

/**
 * The veto state of a pre-write object event: whether a listener stopped the
 * write, the errors it gave, and the data it changed.
 */
trait WriteVetoState {
	/**
	 * Whether event propagation has been stopped
	 *
	 * @var boolean
	 */
	private bool $propagationStopped = false;

	/**
	 * Errors from hooks that stopped propagation
	 *
	 * @var array<string, mixed>
	 */
	private array $errors = [];

	/**
	 * Modified data from hooks
	 *
	 * @var array<string, mixed>
	 */
	private array $modifiedData = [];

	/**
	 * Check if propagation has been stopped by a hook
	 *
	 * @return bool True if propagation is stopped
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-event-listener-isolation-must-prevent-cascading-failures
	 */
	public function isPropagationStopped(): bool {
		return $this->propagationStopped;
	}//end isPropagationStopped()

	/**
	 * Stop event propagation (used by hooks to reject creation)
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-event-listener-isolation-must-prevent-cascading-failures
	 */
	public function stopPropagation(): void {
		$this->propagationStopped = true;
	}//end stopPropagation()

	/**
	 * Set errors from hooks
	 *
	 * @param array<string, mixed> $errors The error details
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-event-listener-isolation-must-prevent-cascading-failures
	 */
	public function setErrors(array $errors): void {
		$this->errors = $errors;
	}//end setErrors()

	/**
	 * Get errors from hooks
	 *
	 * @return array<string, mixed> The error details
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-event-listener-isolation-must-prevent-cascading-failures
	 */
	public function getErrors(): array {
		return $this->errors;
	}//end getErrors()

	/**
	 * Set modified data from hooks
	 *
	 * @param array<string, mixed> $data The modified data
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-event-listener-isolation-must-prevent-cascading-failures
	 */
	public function setModifiedData(array $data): void {
		$this->modifiedData = $data;
	}//end setModifiedData()

	/**
	 * Get modified data from hooks
	 *
	 * @return array<string, mixed> The modified data
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-event-listener-isolation-must-prevent-cascading-failures
	 */
	public function getModifiedData(): array {
		return $this->modifiedData;
	}//end getModifiedData()
}//end trait
