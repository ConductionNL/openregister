<?php

/**
 * Golden tests for the inverse relations a bulk save writes between the objects of one chunk.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 * @author   Conduction <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\BulkRowChecks;
use OCA\OpenRegister\Service\Object\BulkSaveReporting;
use OCA\OpenRegister\Service\Object\BulkSaveSession;
use OCA\OpenRegister\Service\Object\BulkSaveStores;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObjects;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Pins handleBulkInverseRelationsWithAnalysis: which targets get which source uuids, in which order.
 *
 * @covers \OCA\OpenRegister\Service\Object\SaveObjects
 */
class SaveObjectsBulkInverseRelationsGoldenTest extends TestCase {

	private const A = 'aaaaaaaa-1111-4111-8111-111111111111';
	private const B = 'bbbbbbbb-2222-4222-8222-222222222222';
	private const C = 'cccccccc-3333-4333-8333-333333333333';
	private const D = 'dddddddd-4444-4444-8444-444444444444';

	/**
	 * Run the inverse relation pass over the objects.
	 *
	 * @param array $objects  The prepared objects.
	 * @param array $analysis The schema analysis by schema id.
	 *
	 * @return array The objects afterwards.
	 */
	private function apply(array $objects, array $analysis): array {
		$handler = new SaveObjects(
			stores: new BulkSaveStores(catalog: new \OCA\OpenRegister\Db\SchemaCatalog(registers: $this->createMock(RegisterMapper::class), schemas: $this->createMock(SchemaMapper::class), tables: $this->createMock(MagicMapper::class)), saveHandler: $this->createMock(SaveObject::class)),
			session: new BulkSaveSession(userSession: $this->createMock(IUserSession::class), organisationService: $this->createMock(OrganisationService::class)),
			reporting: new BulkSaveReporting(logger: $this->createMock(LoggerInterface::class)),
			checks: new BulkRowChecks()
		);

		$method = new ReflectionMethod(SaveObjects::class, 'handleBulkInverseRelationsWithAnalysis');
		$method->invokeArgs($handler, [&$objects, $analysis]);
		return $objects;
	}//end apply()

	/**
	 * One inverse property definition.
	 *
	 * @param string $inversedBy The target property.
	 * @param bool   $isArray    Whether the source holds a list.
	 *
	 * @return array
	 */
	private static function inverse(string $inversedBy, bool $isArray): array {
		return ['inversedBy' => $inversedBy, 'writeBack' => false, 'isArray' => $isArray];
	}//end inverse()

	public function testSingleAndListRelationsWriteTheSourceIntoEachTargetOnce(): void {
		$analysis = [1 => ['inverseProperties' => ['parent' => self::inverse('children', false), 'tags' => self::inverse('taggedBy', true)]]];
		$objects = [
			['@self' => ['schema' => 1, 'id' => self::A], 'parent' => self::C, 'tags' => [self::C, 'not-a-uuid', 12, self::D, self::C]],
			['@self' => ['schema' => 1, 'id' => self::B], 'parent' => self::C, 'tags' => self::D],
			['@self' => ['schema' => 2, 'id' => self::C], 'children' => 'scalar', 'taggedBy' => [self::B]],
			['@self' => ['schema' => 2, 'id' => self::D], 'parent' => self::A],
		];

		$result = $this->apply($objects, $analysis);

		$this->assertSame(
			[
				['@self' => ['schema' => 1, 'id' => self::A], 'parent' => self::C, 'tags' => [self::C, 'not-a-uuid', 12, self::D, self::C]],
				['@self' => ['schema' => 1, 'id' => self::B], 'parent' => self::C, 'tags' => self::D],
				['@self' => ['schema' => 2, 'id' => self::C], 'children' => [self::A, self::B], 'taggedBy' => [self::B, self::A]],
				['@self' => ['schema' => 2, 'id' => self::D], 'parent' => self::A, 'taggedBy' => [self::A]],
			],
			$result
		);
	}//end testSingleAndListRelationsWriteTheSourceIntoEachTargetOnce()

	public function testSourcesWithoutIdOrKnownSchemaAndTargetsOutsideTheChunkAreLeftAlone(): void {
		$analysis = [1 => ['inverseProperties' => ['parent' => self::inverse('children', false), 'list' => self::inverse('in', true)]]];
		$objects = [
			['@self' => ['schema' => 1], 'parent' => self::B],
			['@self' => ['schema' => false, 'id' => self::C], 'parent' => self::B],
			['@self' => ['schema' => 7, 'id' => self::D], 'parent' => self::B],
			['parent' => self::B],
			['@self' => ['schema' => 1, 'id' => self::A], 'parent' => [self::B], 'list' => self::B],
			['@self' => ['schema' => 1, 'id' => self::B], 'parent' => self::C, 'list' => ['eeeeeeee-5555-4555-8555-555555555555']],
		];

		$result = $this->apply($objects, $analysis);

		$expected = $objects;
		$expected[1]['children'] = [self::B];
		$this->assertSame($expected, $result);
	}//end testSourcesWithoutIdOrKnownSchemaAndTargetsOutsideTheChunkAreLeftAlone()

	public function testWritesMadeEarlierInThePassAreReadByLaterSources(): void {
		$analysis = [
			1 => [
				'inverseProperties' => [
					'parent' => self::inverse('children', false),
					'children' => self::inverse('childOf', true),
					'self' => self::inverse('selfOf', false),
					'selfOf' => self::inverse('echo', true),
				],
			],
		];
		$objects = [
			['@self' => ['schema' => 1, 'id' => self::A], 'parent' => self::B, 'self' => self::A],
			['@self' => ['schema' => 1, 'id' => self::B]],
		];

		$result = $this->apply($objects, $analysis);

		$this->assertSame(
			[
				['@self' => ['schema' => 1, 'id' => self::A], 'parent' => self::B, 'self' => self::A, 'selfOf' => [self::A], 'echo' => [self::A], 'childOf' => [self::B]],
				['@self' => ['schema' => 1, 'id' => self::B], 'children' => [self::A]],
			],
			$result
		);
	}//end testWritesMadeEarlierInThePassAreReadByLaterSources()

	public function testAnIntegerIdIsWrittenAsAStringAndALaterDuplicateIdWinsTheTarget(): void {
		$analysis = [1 => ['inverseProperties' => ['parent' => self::inverse('children', false)]]];
		$objects = [
			['@self' => ['schema' => 1, 'id' => 7], 'parent' => self::B],
			['@self' => ['schema' => 2, 'id' => self::B], 'n' => 'first'],
			['@self' => ['schema' => 2, 'id' => self::B], 'n' => 'second'],
			['@self' => ['schema' => 1, 'id' => '7'], 'parent' => self::B],
		];

		$result = $this->apply($objects, $analysis);

		$this->assertSame(['@self' => ['schema' => 2, 'id' => self::B], 'n' => 'first'], $result[1]);
		$this->assertSame(['@self' => ['schema' => 2, 'id' => self::B], 'n' => 'second', 'children' => ['7']], $result[2]);
	}//end testAnIntegerIdIsWrittenAsAStringAndALaterDuplicateIdWinsTheTarget()

	public function testTheObjectsShareNoReferencesAfterThePass(): void {
		$analysis = [1 => ['inverseProperties' => ['parent' => self::inverse('children', false)]]];
		$objects = [
			['@self' => ['schema' => 1, 'id' => self::A], 'parent' => self::B],
			['@self' => ['schema' => 1, 'id' => self::B]],
		];

		$result = $this->apply($objects, $analysis);
		$copy = $result;
		$copy[1]['children'][] = 'changed';
		$copy[0]['parent'] = 'changed';

		$this->assertSame([self::A], $result[1]['children']);
		$this->assertSame(self::B, $result[0]['parent']);
	}//end testTheObjectsShareNoReferencesAfterThePass()
}//end class
