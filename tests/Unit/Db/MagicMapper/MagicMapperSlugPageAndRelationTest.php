<?php

/**
 * Characterisation test for MagicMapper::findSlugBackfillPage() and findByRelation().
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Tests
 * @package   OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionProperty;

/**
 * Pins the no-table page of a slug backfill and the empty answers of findByRelation().
 */
class MagicMapperSlugPageAndRelationTest extends TestCase {

	/**
	 * A mapper whose register+schema tables do not exist.
	 *
	 * @return MagicMapper
	 */
	private function mapper(): MagicMapper {
		$mapper = $this->getMockBuilder(MagicMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['existsTableForRegisterSchema'])
			->getMock();
		$mapper->method('existsTableForRegisterSchema')->willReturn(false);
		(new ReflectionProperty(MagicMapper::class, 'logger'))->setValue($mapper, new NullLogger());

		return $mapper;
	}//end mapper()

	public function testABackfillPageOfAMissingTableIsEmptyAndKeepsTheCursor(): void {
		$page = $this->mapper()->findSlugBackfillPage(new Register(), new Schema(), 17, 100);

		$this->assertSame(['rows' => [], 'fetched' => 0, 'lastId' => 17], $page);
	}//end testABackfillPageOfAMissingTableIsEmptyAndKeepsTheCursor()

	public function testARelationLookupWithoutUuidFindsNothing(): void {
		$this->assertSame([], $this->mapper()->findByRelation(''));
		$this->assertSame([], $this->mapper()->findByRelation('', 'term', true));
	}//end testARelationLookupWithoutUuidFindsNothing()
}//end class
