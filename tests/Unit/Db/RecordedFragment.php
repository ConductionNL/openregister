<?php

/**
 * A rendered SQL fragment for the query recording harness.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\ILiteral;
use OCP\DB\QueryBuilder\IParameter;
use OCP\DB\QueryBuilder\IQueryFunction;

/**
 * A recorded SQL fragment (function, literal, parameter or composite).
 */
final class RecordedFragment implements IQueryFunction, ILiteral, IParameter, ICompositeExpression {

	/**
	 * The parts of a composite expression.
	 *
	 * @var list<mixed>
	 */
	private array $parts = [];

	/**
	 * Constructor.
	 *
	 * @param string $sql The rendered fragment.
	 */
	public function __construct(private readonly string $sql) {
	}//end __construct()

	/**
	 * Add several parts.
	 *
	 * @param array $parts The parts.
	 *
	 * @return ICompositeExpression
	 */
	public function addMultiple(array $parts = []): ICompositeExpression {
		foreach ($parts as $part) {
			$this->parts[] = $part;
		}

		return $this;
	}//end addMultiple()

	/**
	 * Add one part.
	 *
	 * @param mixed $part The part.
	 *
	 * @return ICompositeExpression
	 */
	public function add($part): ICompositeExpression {
		$this->parts[] = $part;
		return $this;
	}//end add()

	/**
	 * Number of parts.
	 *
	 * @return int
	 */
	public function count(): int {
		return count($this->parts);
	}//end count()

	/**
	 * Composite type.
	 *
	 * @return string
	 */
	public function getType(): string {
		return 'AND';
	}//end getType()

	/**
	 * The rendered fragment.
	 *
	 * @return string
	 */
	public function __toString(): string {
		if ($this->parts === []) {
			return $this->sql;
		}

		return $this->sql . '+[' . implode(', ', array_map('strval', $this->parts)) . ']';
	}//end __toString()
}//end class
