<?php

/**
 * OpenRegister TransitionRun
 *
 * One transition being applied to a resolved, cleared subject.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Lifecycle;

/**
 * One transition being applied to a subject already resolved and cleared.
 *
 * Built by {@see TransitionEngine} once the subject passed its checks, and
 * handed to the static, provider and graph helpers instead of the request,
 * the annotation and the lifecycle field separately (ADR-011 rule 3). It
 * never leaves the engine.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class TransitionRun {

	/**
	 * Name the run.
	 *
	 * @param TransitionRequest    $request    The object, the action, the payload and the path.
	 * @param array<string, mixed> $annotation The schema's `x-openregister-lifecycle` block.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public readonly TransitionRequest $request,
		public readonly array $annotation,
	) {
	}//end __construct()

	/**
	 * The lifecycle field name on the object (`field`, or the older `property`).
	 *
	 * @return string The field name, empty when the annotation names none.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function field(): string {
		return (string)($this->annotation['field'] ?? ($this->annotation['property'] ?? ''));
	}//end field()

	/**
	 * The object's lifecycle value before the move.
	 *
	 * @return string The current state, empty when unset.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function currentState(): string {
		return (string)(($this->request->object->getObject() ?? [])[$this->field()] ?? '');
	}//end currentState()
}//end class
