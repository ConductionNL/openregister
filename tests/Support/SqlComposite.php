<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Support;

use OCP\DB\QueryBuilder\ICompositeExpression;

/**
 * An AND/OR of SQL parts that renders `(a) OR (b)`, as Nextcloud's own composite does.
 *
 * Nextcloud's `OC\DB\QueryBuilder\CompositeExpression` ships with the server,
 * not with `nextcloud/ocp`, so a test without a server in scope needs its own.
 */
final class SqlComposite implements ICompositeExpression, \Countable {
	/**
	 * @param string       $type  AND or OR.
	 * @param list<string> $parts The SQL parts.
	 */
	public function __construct(
		private readonly string $type,
		private array $parts = [],
	) {
	}

	public function addMultiple(array $parts = []): ICompositeExpression {
		foreach ($parts as $part) {
			$this->add($part);
		}

		return $this;
	}

	public function add($part): ICompositeExpression {
		if ($part === null || ($part instanceof self && count($part) === 0)) {
			return $this;
		}

		$this->parts[] = (string)$part;
		return $this;
	}

	public function count(): int {
		return count($this->parts);
	}

	public function getType(): string {
		return $this->type;
	}

	public function __toString(): string {
		if (count($this->parts) === 1) {
			return $this->parts[0];
		}

		return '(' . implode(') ' . $this->type . ' (', $this->parts) . ')';
	}
}
