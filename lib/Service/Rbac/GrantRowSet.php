<?php

/**
 * The grant rows of one object while they are assembled.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/an-object-lists-its-grants-with-their-origin/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

/**
 * One object's rows, folded per principal and origin, and the record the matcher reads.
 *
 * Built per call by {@see ObjectGrantsReport::rowsFor()} and never shared.
 *
 * @spec openspec/changes/an-object-lists-its-grants-with-their-origin/specs/rbac-scopes/spec.md
 */
final class GrantRowSet {
	/**
	 * The record as the matcher reads it.
	 *
	 * @var array<string, mixed>
	 */
	public readonly array $envelope;

	/**
	 * The rows so far, keyed by what folds them together.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $rows = [];

	/**
	 * Start an empty set for one record.
	 *
	 * @param array<string, mixed> $envelope The record as the matcher reads it.
	 *
	 * @spec openspec/changes/an-object-lists-its-grants-with-their-origin/specs/rbac-scopes/spec.md
	 */
	public function __construct(array $envelope) {
		$this->envelope = $envelope;
	}//end __construct()

	/**
	 * The rows, in the order they were first added.
	 *
	 * @return array<string, array<string, mixed>> The rows.
	 *
	 * @spec openspec/changes/an-object-lists-its-grants-with-their-origin/specs/rbac-scopes/spec.md
	 */
	public function rows(): array {
		return $this->rows;
	}//end rows()

	/**
	 * Fold a row into the one with the same principal, origin, level, role and ancestor.
	 *
	 * The verbs and the matched properties are merged. A folded row is relative
	 * only when every entry behind it is.
	 *
	 * @param array<string, mixed> $row The row to fold in.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/an-object-lists-its-grants-with-their-origin/specs/rbac-scopes/spec.md
	 */
	public function fold(array $row): void {
		$key = self::keyOf(row: $row);

		if ($row['origin'] === ObjectGrantsReport::ORIGIN_RULE) {
			$parts = ['rule', $row['level'], $row['principal']['type'], $row['principal']['id'], $row['role']];
			$row['id'] = implode(':', array_filter($parts, static fn ($part) => $part !== null));
		}

		if (isset($this->rows[$key]) === false) {
			$this->rows[$key] = $row;
			return;
		}

		$this->rows[$key]['verbs'] = array_values(array_unique(array_merge($this->rows[$key]['verbs'], $row['verbs'])));
		$this->rows[$key]['matchedOn'] = array_values(array_unique(array_merge($this->rows[$key]['matchedOn'], $row['matchedOn'])));
		$this->rows[$key]['relative'] = ($this->rows[$key]['relative'] === true && $row['relative'] === true);
	}//end fold()

	/**
	 * Take one verb out of every row naming a principal, and drop rows left empty.
	 *
	 * @param array{0: string, 1: string} $principal The principal's type and id.
	 * @param string                      $action    The verb an enforcing deny removes.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/an-object-lists-its-grants-with-their-origin/specs/rbac-scopes/spec.md
	 */
	public function strip(array $principal, string $action): void {
		[$type, $principalId] = $principal;
		foreach ($this->rows as $key => $row) {
			if ($row['principal']['type'] !== $type || $row['principal']['id'] !== $principalId) {
				continue;
			}

			$this->rows[$key]['verbs'] = array_values(array_diff($row['verbs'], [$action]));
			if ($this->rows[$key]['verbs'] === []) {
				unset($this->rows[$key]);
			}
		}
	}//end strip()

	/**
	 * What folds two rows together.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string The fold key.
	 */
	private static function keyOf(array $row): string {
		return implode(
			"\0",
			[
				$row['origin'],
				$row['level'],
				(string)$row['role'],
				$row['principal']['type'],
				$row['principal']['id'],
				(string)($row['inheritedFrom']['uuid'] ?? ''),
			]
		);
	}//end keyOf()
}//end class
