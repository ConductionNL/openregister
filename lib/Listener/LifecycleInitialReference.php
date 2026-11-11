<?php

/**
 * A reference to a field of a related object, as a declared initial state.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
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

namespace OCA\OpenRegister\Listener;

/**
 * The `<name>.<field>` a dynamic initial state points at.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class LifecycleInitialReference {
	/**
	 * Constructor.
	 *
	 * @param string $name  The declared reference name.
	 * @param string $field The field on the related object.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public readonly string $name,
		public readonly string $field,
	) {
	}//end __construct()

	/**
	 * Whether both halves are there.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function isComplete(): bool {
		return $this->name !== '' && $this->field !== '';
	}//end isComplete()
}//end class
