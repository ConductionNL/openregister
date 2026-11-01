<?php

/**
 * OpenRegister ObjectMove
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/identity-survives-a-move/specs/objects-crud/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * One requested move: the object, where it is, where it is going and who asked.
 *
 * @spec openspec/changes/identity-survives-a-move/specs/objects-crud/spec.md
 */
final class ObjectMove {

	/**
	 * Constructor.
	 *
	 * @param ObjectEntity $object The object.
	 * @param MoveAddress  $source Where it is.
	 * @param MoveAddress  $target Where it is going.
	 * @param string       $actor  Who asked.
	 *
	 * @spec openspec/changes/identity-survives-a-move/specs/objects-crud/spec.md
	 */
	public function __construct(
		public readonly ObjectEntity $object,
		public readonly MoveAddress $source,
		public readonly MoveAddress $target,
		public readonly string $actor,
	) {
	}//end __construct()

	/**
	 * The answer a move gives, naming both addresses.
	 *
	 * @param bool               $moved  Whether the object moved.
	 * @param array<int, string> $errors What stood in the way, or what went wrong after the move.
	 *
	 * @return array{moved: bool, uuid: string, from: array<string, mixed>, to: array<string, mixed>, errors: array<int, string>}
	 *
	 * @spec openspec/changes/identity-survives-a-move/specs/objects-crud/spec.md
	 */
	public function answer(bool $moved, array $errors): array {
		return [
			'moved' => $moved,
			'uuid' => (string)$this->object->getUuid(),
			'from' => $this->source->ids(),
			'to' => $this->target->ids(),
			'errors' => $errors,
		];
	}//end answer()
}//end class
