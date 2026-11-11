<?php

/**
 * One leaf clause of a scheduled filter, read against one object.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Notification;

use DateTimeImmutable;

/**
 * The field, operator and operand of a leaf node, the object's value for the
 * field, and the moment the clause is evaluated at.
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */
final class ScheduledFilterClause {

	/**
	 * The field the clause reads.
	 *
	 * @var string
	 */
	public readonly string $field;

	/**
	 * The operator name.
	 *
	 * @var string
	 */
	public readonly string $operator;

	/**
	 * The operand as parsed.
	 *
	 * @var mixed
	 */
	public readonly mixed $operand;

	/**
	 * The object's value for the field, or null when absent.
	 *
	 * @var mixed
	 */
	public readonly mixed $actual;

	/**
	 * Logical "now" for the clause.
	 *
	 * @var DateTimeImmutable
	 */
	public readonly DateTimeImmutable $now;

	/**
	 * Read a leaf node against a target.
	 *
	 * @param array<string, mixed>  $node   The leaf node.
	 * @param ScheduledFilterTarget $target The object and the scan moment.
	 */
	public function __construct(array $node, ScheduledFilterTarget $target) {
		$this->field    = (string)($node['field'] ?? '');
		$this->operator = (string)($node['operator'] ?? '');
		$this->operand  = ($node['operand'] ?? null);
		$this->actual   = ($target->objectData[$this->field] ?? null);
		$this->now      = $target->now;
	}//end __construct()
}//end class
