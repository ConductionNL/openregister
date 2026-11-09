<?php

/**
 * OpenRegister StateFieldWrite
 *
 * One save as the state field rules read it: the lifecycle annotation, the data
 * before and after, and the states the edit moves between.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener\Support
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

namespace OCA\OpenRegister\Listener\Support;

/**
 * A save as the state field rules read it.
 *
 * @spec openspec/specs/row-field-level-security/spec.md
 */
final class StateFieldWrite {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed>      $annotation The lifecycle annotation.
	 * @param array<string, mixed>      $newData    The object as it would be saved.
	 * @param array<string, mixed>|null $oldData    The object as currently stored; null on a create.
	 * @param string|null               $toState    The state the object ends in.
	 * @param string|null               $fromState  The state the stored object is in; null on a create.
	 */
	public function __construct(
		public readonly array $annotation,
		public readonly array $newData,
		private readonly ?array $oldData,
		public readonly ?string $toState,
		public readonly ?string $fromState,
	) {
	}//end __construct()

	/**
	 * Whether this save creates the object.
	 *
	 * @return bool True on a create.
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md
	 */
	public function isCreate(): bool {
		return $this->oldData === null;
	}//end isCreate()

	/**
	 * The object as currently stored; empty on a create.
	 *
	 * @return array<string, mixed> The stored data.
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md
	 */
	public function oldData(): array {
		return ($this->oldData ?? []);
	}//end oldData()

	/**
	 * The state the edit was made in: the stored state, or the resulting one when the stored state is unknown.
	 *
	 * @return string|null The state whose read-only and hidden rules apply.
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md
	 */
	public function editState(): ?string {
		return ($this->fromState ?? $this->toState);
	}//end editState()

	/**
	 * Whether this write actually changes a property.
	 *
	 * A payload that resubmits the stored value is not a change, which is what
	 * lets a PATCH carry the whole object back without tripping over its own
	 * frozen fields. It is the same allowance property authorization makes.
	 *
	 * @param string $property The property name.
	 *
	 * @return bool True when the value differs.
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md
	 */
	public function changes(string $property): bool {
		if (array_key_exists($property, $this->newData) === false) {
			return false;
		}

		return (($this->oldData()[$property] ?? null) !== $this->newData[$property]);
	}//end changes()
}//end class
