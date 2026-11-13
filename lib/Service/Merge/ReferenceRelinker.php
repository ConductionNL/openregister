<?php

/**
 * ReferenceRelinker: moves every reference to a losing record onto the survivor.
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

use Psr\Log\LoggerInterface;

/**
 * Plans and applies the reference moves of a merge (design D-1, D-2).
 *
 * `plan()` reads every object that references the loser through the relation
 * index and, from each object's own data, computes the fields that move: a
 * scalar reference becomes the survivor, an array reference replaces the loser
 * and drops a duplicate the survivor already makes, and a nested reference is
 * found wherever it sits. The whole field is the move, so a reversal restores
 * it exactly or reports an edit. A reference matches the loser by its uuid,
 * with or without a URI prefix the survivor's uuid then keeps.
 *
 * `apply()` writes each move through the save path as the merging user, so the
 * user's rights, validation and audit apply; a move the user may not make is
 * left and reported.
 *
 * @spec openspec/changes/mdm-merge-relinks-every-reference/specs/mdm-merge/spec.md#requirement-a-merge-relinks-every-reference-to-the-losing-record
 */
class ReferenceRelinker {

	/**
	 * The most reference moves one merge may make.
	 */
	public const DEFAULT_CAP = 5000;

	/**
	 * Constructor.
	 *
	 * @param ReferenceFinderInterface $finder The objects that reference a record.
	 * @param ReferenceWriterInterface $writer Writes one move through the save path.
	 * @param LoggerInterface          $logger Receives a skipped move and a capped plan.
	 * @param int                      $cap    The move cap.
	 */
	public function __construct(
		private readonly ReferenceFinderInterface $finder,
		private readonly ReferenceWriterInterface $writer,
		private readonly LoggerInterface $logger,
		private readonly int $cap=self::DEFAULT_CAP,
	) {
	}//end __construct()

	/**
	 * Every field that moves from the loser to the survivor, capped.
	 *
	 * @param string $loserUuid    The record that goes away.
	 * @param string $survivorUuid The record that stays.
	 *
	 * @return ReferenceRelinkPlan
	 *
	 * @spec openspec/changes/mdm-merge-relinks-every-reference/specs/mdm-merge/spec.md#requirement-a-merge-relinks-every-reference-to-the-losing-record
	 */
	public function plan(string $loserUuid, string $survivorUuid): ReferenceRelinkPlan {
		$moves = [];
		foreach ($this->finder->referencingObjects(uuid: $loserUuid) as $object) {
			foreach ($this->movesIn(object: $object, loserUuid: $loserUuid, survivorUuid: $survivorUuid) as $move) {
				$moves[] = $move;
				if (count($moves) >= $this->cap) {
					$this->logger->warning(
						'[ReferenceRelinker] "' . $loserUuid . '" is referenced from more than the ' . $this->cap
						. '-move cap; the merge is refused rather than relinking in part'
					);
					return new ReferenceRelinkPlan(moves: $moves, cap: $this->cap, exceedsCap: true);
				}
			}
		}

		return new ReferenceRelinkPlan(moves: $moves, cap: $this->cap);
	}//end plan()

	/**
	 * Apply a plan as the actor; the moves written and the moves the actor could not make.
	 *
	 * @param ReferenceRelinkPlan $plan  The plan.
	 * @param string              $actor The merging user.
	 *
	 * @return array{applied: array<int, array{object: string, path: string, before: mixed, after: mixed}>, skipped: array<int, array{object: string, path: string}>}
	 *
	 * @spec openspec/changes/mdm-merge-relinks-every-reference/specs/mdm-merge/spec.md#requirement-a-merge-relinks-every-reference-to-the-losing-record
	 */
	public function apply(ReferenceRelinkPlan $plan, string $actor): array {
		$applied = [];
		$skipped = [];
		foreach ($plan->moves as $move) {
			if ($this->writer->write(move: $move, actor: $actor) === true) {
				$applied[] = $move->toSnapshot();
				continue;
			}

			$this->logger->info('[ReferenceRelinker] "' . $actor . '" may not update "' . $move->objectUuid . '"; left "' . $move->path . '" pointing at the losing record');
			$skipped[] = ['object' => $move->objectUuid, 'path' => $move->path];
		}

		return ['applied' => $applied, 'skipped' => $skipped];
	}//end apply()

	/**
	 * The field moves one object carries.
	 *
	 * @param ReferencingObject $object       The referencing object.
	 * @param string            $loserUuid    The record that goes away.
	 * @param string            $survivorUuid The record that stays.
	 *
	 * @return array<int, ReferenceMove>
	 */
	private function movesIn(ReferencingObject $object, string $loserUuid, string $survivorUuid): array {
		$moves = [];
		foreach ($object->data as $key => $value) {
			$after = $this->rewrite(value: $value, loserUuid: $loserUuid, survivorUuid: $survivorUuid);
			if ($after === $value) {
				continue;
			}

			$moves[] = new ReferenceMove(
				objectUuid: $object->uuid,
				register: $object->register,
				schema: $object->schema,
				path: (string)$key,
				before: $value,
				after: $after
			);
		}

		return $moves;
	}//end movesIn()

	/**
	 * A value with every reference to the loser rewritten to the survivor.
	 *
	 * A list drops a value that becomes a duplicate of one it already holds; a
	 * map is rewritten member by member; a scalar becomes the survivor, keeping
	 * a URI prefix it carried.
	 *
	 * @param mixed  $value        The field value.
	 * @param string $loserUuid    The record that goes away.
	 * @param string $survivorUuid The record that stays.
	 *
	 * @return mixed The rewritten value, identical to the input when nothing referenced the loser.
	 */
	private function rewrite(mixed $value, string $loserUuid, string $survivorUuid): mixed {
		if (is_string($value) === true) {
			return $this->rewriteScalar(value: $value, loserUuid: $loserUuid, survivorUuid: $survivorUuid);
		}

		if (is_array($value) === false) {
			return $value;
		}

		if (array_is_list($value) === true) {
			return $this->rewriteList(list: $value, loserUuid: $loserUuid, survivorUuid: $survivorUuid);
		}

		$rewritten = [];
		foreach ($value as $key => $member) {
			$rewritten[$key] = $this->rewrite(value: $member, loserUuid: $loserUuid, survivorUuid: $survivorUuid);
		}

		return $rewritten;
	}//end rewrite()

	/**
	 * A list rewritten, without a duplicate the rewrite creates.
	 *
	 * @param array<int, mixed> $list         The list.
	 * @param string            $loserUuid    The record that goes away.
	 * @param string            $survivorUuid The record that stays.
	 *
	 * @return array<int, mixed>
	 */
	private function rewriteList(array $list, string $loserUuid, string $survivorUuid): array {
		$rewritten = [];
		foreach ($list as $member) {
			$next = $this->rewrite(value: $member, loserUuid: $loserUuid, survivorUuid: $survivorUuid);
			if (in_array($next, $rewritten, true) === true && $next !== $member) {
				// The rewrite turned this element into one the list already
				// holds; dropping it is what keeps the survivor listed once.
				continue;
			}

			$rewritten[] = $next;
		}

		return $rewritten;
	}//end rewriteList()

	/**
	 * A scalar rewritten: the survivor uuid, keeping any URI prefix of the loser's.
	 *
	 * @param string $value        The scalar.
	 * @param string $loserUuid    The record that goes away.
	 * @param string $survivorUuid The record that stays.
	 *
	 * @return string
	 */
	private function rewriteScalar(string $value, string $loserUuid, string $survivorUuid): string {
		if ($value === $loserUuid) {
			return $survivorUuid;
		}

		if (str_ends_with($value, '/' . $loserUuid) === true) {
			return substr($value, 0, (-strlen($loserUuid))) . $survivorUuid;
		}

		return $value;
	}//end rewriteScalar()
}//end class
