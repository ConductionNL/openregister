<?php

/**
 * Characterisation of the SchemaNotInRegisterException message: the register named by id and
 * slug, by id alone or not at all, and an empty register against one that lacks the slug.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Exception
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Exception;

use OCA\OpenRegister\Exception\SchemaNotInRegisterException;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;

/**
 * Golden messages and accessors of SchemaNotInRegisterException.
 *
 * @covers \OCA\OpenRegister\Exception\SchemaNotInRegisterException
 */
final class SchemaNotInRegisterExceptionMessageGoldenTest extends TestCase {

	/**
	 * An empty register named by id and slug.
	 *
	 * @return void
	 */
	public function testEmptyNamedRegister(): void {
		$exception = new SchemaNotInRegisterException('anonymizationLink', 6, 'document', 9, 0);

		$this->assertInstanceOf(DoesNotExistException::class, $exception);
		$this->assertSame(
			'Schema slug "anonymizationLink" cannot resolve because register "document" (id 6) carries no schemas at all. '
			. '9 schema(s) elsewhere on this instance carry this slug, and resolving to one of '
			. 'them would bind data to a register its owner did not choose. '
			. 'Run `occ openregister:registers:relink-schemas` to inspect and repair the linkage.',
			$exception->getMessage()
		);
	}//end testEmptyNamedRegister()

	/**
	 * A register that carries schemas but not this slug, named by id alone.
	 *
	 * @return void
	 */
	public function testRegisterLackingTheSlugNamedByIdAlone(): void {
		$exception = new SchemaNotInRegisterException('ghost', 7, null, 3, 14);

		$this->assertSame(
			'Schema slug "ghost" is not carried by register "?" (id 7), which carries 14 schema(s). 3 schema(s) elsewhere '
			. 'on this instance carry this slug; none of them is served here, because naming a register '
			. 'makes it a boundary. If the linkage was lost, run '
			. '`occ openregister:registers:relink-schemas` to inspect and repair it.',
			$exception->getMessage()
		);
	}//end testRegisterLackingTheSlugNamedByIdAlone()

	/**
	 * Without a register id the register is "the named register", whatever slug is given.
	 *
	 * @return void
	 */
	public function testUnnamedRegister(): void {
		$empty = new SchemaNotInRegisterException('s');
		$lacking = new SchemaNotInRegisterException('s', null, 'ignored', 1, 2);

		$this->assertSame(
			'Schema slug "s" cannot resolve because the named register carries no schemas at all. '
			. '0 schema(s) elsewhere on this instance carry this slug, and resolving to one of '
			. 'them would bind data to a register its owner did not choose. '
			. 'Run `occ openregister:registers:relink-schemas` to inspect and repair the linkage.',
			$empty->getMessage()
		);
		$this->assertSame(
			'Schema slug "s" is not carried by the named register, which carries 2 schema(s). 1 schema(s) elsewhere '
			. 'on this instance carry this slug; none of them is served here, because naming a register '
			. 'makes it a boundary. If the linkage was lost, run '
			. '`occ openregister:registers:relink-schemas` to inspect and repair it.',
			$lacking->getMessage()
		);
	}//end testUnnamedRegister()

	/**
	 * The accessors return what was given.
	 *
	 * @return void
	 */
	public function testAccessors(): void {
		$exception = new SchemaNotInRegisterException('slug', 4, 'reg', 5, 6);

		$this->assertSame('slug', $exception->getSchemaSlug());
		$this->assertSame(4, $exception->getRegisterId());
		$this->assertSame('reg', $exception->getRegisterSlug());
		$this->assertSame(5, $exception->getCandidatesElsewhere());
		$this->assertSame(6, $exception->getRegisterSchemaCount());
	}//end testAccessors()
}//end class
