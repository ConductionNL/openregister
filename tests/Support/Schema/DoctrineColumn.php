<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Support\Schema;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Types\Type;
use OCP\DB\Schema\ColumnType;
use OCP\DB\Schema\IColumn;

/**
 * An OCP IColumn over a Doctrine column, for tests that run without a Nextcloud server.
 */
final class DoctrineColumn implements IColumn {
	public function __construct(
		private readonly Column $column,
	) {
	}

	public function setType(string|Type|ColumnType $type): self {
		if ($type instanceof ColumnType) {
			$type = $type->value;
		}

		if (is_string($type) === true) {
			$type = Type::getType($type);
		}

		$this->column->setType($type);
		return $this;
	}

	public function setLength(?int $length): self {
		$this->column->setLength($length);
		return $this;
	}

	public function setPrecision(int $precision): self {
		$this->column->setPrecision($precision);
		return $this;
	}

	public function setScale(int $scale): self {
		$this->column->setScale($scale);
		return $this;
	}

	public function setUnsigned(bool $unsigned): self {
		$this->column->setUnsigned($unsigned);
		return $this;
	}

	public function setFixed(bool $fixed): self {
		$this->column->setFixed($fixed);
		return $this;
	}

	public function setNotnull(bool $notnull): self {
		$this->column->setNotnull($notnull);
		return $this;
	}

	public function setDefault(mixed $default): self {
		$this->column->setDefault($default);
		return $this;
	}

	public function getName(): string {
		return $this->column->getName();
	}

	public function getType(): ColumnType {
		return ColumnType::from(Type::lookupName($this->column->getType()));
	}

	public function getLength(): ?int {
		return $this->column->getLength();
	}

	public function getPrecision(): int {
		return (int)$this->column->getPrecision();
	}

	public function getScale(): int {
		return $this->column->getScale();
	}

	public function getUnsigned(): bool {
		return $this->column->getUnsigned();
	}

	public function getFixed(): bool {
		return $this->column->getFixed();
	}

	public function getNotnull(): bool {
		return $this->column->getNotnull();
	}

	public function getDefault(): mixed {
		return $this->column->getDefault();
	}

	public function getAutoincrement(): bool {
		return $this->column->getAutoincrement();
	}

	/**
	 * Doctrine column methods the OCP interface does not name.
	 *
	 * @param string $name      The method.
	 * @param array  $arguments Its arguments.
	 *
	 * @return mixed
	 */
	public function __call(string $name, array $arguments): mixed {
		return $this->column->$name(...$arguments);
	}
}
