<?php

/**
 * The values of explicit-reveal properties stay out of the relations index.
 *
 * Pins that the single-object and the bulk relation scans skip a top-level
 * explicit-reveal property while still indexing the other references, and
 * that the repair step drops those values from an index stored by path or as
 * a plain list and leaves an index without them untouched.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\SaveObject\RelationScanner;
use OCA\OpenRegister\Service\Object\SaveObject\RevealRelations;
use OCA\OpenRegister\Service\Object\SaveObject\RelationScanRun;
use OCA\OpenRegister\Service\Object\SaveObjects\BulkRelationRun;
use OCA\OpenRegister\Service\Object\SaveObjects\BulkRelationScanner;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

final class RevealValuesStayOutOfRelationsTest extends TestCase {

	private const CASE_UUID = '0f8d1e2a-3b4c-4d5e-8f60-718293a4b5c6';

	private const MODERATOR_UUID = 'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d';

	private static function schema(): Schema {
		$schema = new Schema();
		$schema->setId(5);
		$schema->setProperties([
			'sourceCase' => ['type' => 'string', 'format' => 'uuid'],
			'moderator' => ['type' => 'string', 'format' => 'uuid', 'authorization' => ['reveal' => 'explicit']],
		]);

		return $schema;
	}//end schema()

	private static function data(): array {
		return ['sourceCase' => self::CASE_UUID, 'moderator' => self::MODERATOR_UUID];
	}//end data()

	public function testTheSingleObjectScanSkipsAnExplicitRevealProperty(): void {
		$urls = $this->createMock(IURLGenerator::class);
		$relations = (new RelationScanner(urls: $urls))->scan(data: self::data(), scan: new RelationScanRun(schema: self::schema()));

		$this->assertSame(['sourceCase' => self::CASE_UUID], $relations);
	}//end testTheSingleObjectScanSkipsAnExplicitRevealProperty()

	public function testTheBulkScanSkipsAnExplicitRevealProperty(): void {
		$relations = (new BulkRelationScanner())->scan(data: self::data(), run: new BulkRelationRun(schema: self::schema()));

		$this->assertSame(['sourceCase' => self::CASE_UUID], $relations);
	}//end testTheBulkScanSkipsAnExplicitRevealProperty()

	public function testANestedKeyOfTheSameNameIsStillIndexed(): void {
		$run = new BulkRelationRun(prefix: 'items.0', schema: self::schema());

		$this->assertFalse($run->withholds(key: 'moderator'));
		$this->assertTrue((new BulkRelationRun(schema: self::schema()))->withholds(key: 'moderator'));
	}//end testANestedKeyOfTheSameNameIsStillIndexed()

	public function testTheRepairDropsRevealPathsAndValuesAndLeavesACleanIndexAlone(): void {
		$byPath = RevealRelations::withoutRevealValues(
			relations: ['sourceCase' => self::CASE_UUID, 'moderator' => self::MODERATOR_UUID, 'moderator.0' => self::MODERATOR_UUID],
			properties: ['moderator'],
			values: [self::MODERATOR_UUID]
		);
		$asList = RevealRelations::withoutRevealValues(
			relations: [self::CASE_UUID, self::MODERATOR_UUID],
			properties: ['moderator'],
			values: [self::MODERATOR_UUID]
		);
		$clean = RevealRelations::withoutRevealValues(
			relations: ['sourceCase' => self::CASE_UUID],
			properties: ['moderator'],
			values: [self::MODERATOR_UUID]
		);

		$this->assertSame(['sourceCase' => self::CASE_UUID], $byPath);
		$this->assertSame([self::CASE_UUID], $asList);
		$this->assertNull($clean);
	}//end testTheRepairDropsRevealPathsAndValuesAndLeavesACleanIndexAlone()
}//end class
