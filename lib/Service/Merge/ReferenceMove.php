<?php

/**
 * ReferenceMove: one reference re-pointed from a losing record to the survivor.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Merge
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Merge;

/**
 * One field of one object whose value moves from the loser to the survivor.
 *
 * `before` and `after` are the whole field value, a scalar for a scalar
 * reference and the whole list for an array reference, so a reversal can put
 * the field back exactly when it still holds `after` and report an edit when
 * it does not (design D-2).
 *
 * @spec openspec/changes/mdm-merge-relinks-every-reference/specs/mdm-merge/spec.md#requirement-a-merge-relinks-every-reference-to-the-losing-record
 */
final readonly class ReferenceMove {

	/**
	 * Constructor.
	 *
	 * @param string      $objectUuid The object holding the reference.
	 * @param string|null $register   Its register, for the patch target.
	 * @param string|null $schema     Its schema, for the patch target and the preview.
	 * @param string      $path       The dot path of the field that moves.
	 * @param mixed       $before     The field value before the move.
	 * @param mixed       $after      The field value after the move.
	 */
	public function __construct(
		public string $objectUuid,
		public ?string $register,
		public ?string $schema,
		public string $path,
		public mixed $before,
		public mixed $after,
	) {
	}//end __construct()

	/**
	 * The move as the merge snapshot records it.
	 *
	 * @return array{object: string, path: string, before: mixed, after: mixed}
	 *
	 * @spec openspec/changes/mdm-merge-relinks-every-reference/specs/mdm-merge/spec.md#requirement-a-merge-relinks-every-reference-to-the-losing-record
	 */
	public function toSnapshot(): array {
		return ['object' => $this->objectUuid, 'path' => $this->path, 'before' => $this->before, 'after' => $this->after];
	}//end toSnapshot()
}//end class
