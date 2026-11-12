<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Support\Schema;

use Doctrine\DBAL\Schema\Index;
use OCP\DB\Schema\IIndex;

/**
 * An OCP IIndex over a Doctrine index, for tests that run without a Nextcloud server.
 */
final class DoctrineIndex implements IIndex {
	public function __construct(
		private readonly Index $index,
	) {
	}

	public function getName(): string {
		return $this->index->getName();
	}

	public function getColumns(): array {
		return $this->index->getColumns();
	}

	public function isUnique(): bool {
		return $this->index->isUnique();
	}

	public function isPrimary(): bool {
		return $this->index->isPrimary();
	}

	public function isSimpleIndex(): bool {
		return $this->index->isSimpleIndex();
	}

	public function hasColumnAtPosition(string $name, int $position = 0): bool {
		return $this->index->hasColumnAtPosition($name, $position);
	}

	public function spansColumns(array $columnNames): bool {
		return $this->index->spansColumns($columnNames);
	}

	/**
	 * Doctrine index methods the OCP interface does not name.
	 *
	 * @param string $name      The method.
	 * @param array  $arguments Its arguments.
	 *
	 * @return mixed
	 */
	public function __call(string $name, array $arguments): mixed {
		return $this->index->$name(...$arguments);
	}
}
