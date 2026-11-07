<?php

/**
 * What a schema declares in its `x-openregister-lifecycle` block.
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

use OCA\OpenRegister\Db\Schema;

/**
 * A schema together with its lifecycle block.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class LifecycleDeclaration {
	/**
	 * Constructor.
	 *
	 * @param Schema               $schema     The schema that declares the lifecycle.
	 * @param array<string, mixed> $annotation Its `x-openregister-lifecycle` block.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public readonly Schema $schema,
		public readonly array $annotation,
	) {
	}//end __construct()

	/**
	 * The lifecycle field. `property` is an alias for `field`, and `field` wins when both are present.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function field(): string {
		return (string)($this->annotation['field'] ?? ($this->annotation['property'] ?? ''));
	}//end field()

	/**
	 * The declared transitions, as authored.
	 *
	 * @return mixed
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function transitions(): mixed {
		return ($this->annotation['transitions'] ?? []);
	}//end transitions()

	/**
	 * Whether the block declares a non-empty transition list.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function hasTransitions(): bool {
		return is_array($this->transitions()) === true && $this->transitions() !== [];
	}//end hasTransitions()
}//end class
