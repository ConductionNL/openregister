<?php

/**
 * MapNode: a mapping named by its reference column, and the refusal when neither find() nor the reference lookup knows it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow\Nodes
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Nodes;

use OCA\OpenRegister\Db\Mapping;
use OCA\OpenRegister\Db\MappingMapperInterface;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\Nodes\MapNode;
use OCA\OpenRegister\Service\MappingService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\MapNode
 * @uses \OCA\OpenRegister\Db\Mapping
 * @uses \OCA\OpenRegister\Service\Flow\FlowItemShape
 * @uses \OCA\OpenRegister\Service\Flow\FlowItems
 * @uses \OCA\OpenRegister\Service\Flow\Nodes\LogicNodeBase
 */
class MapNodeReferenceBehaviourTest extends TestCase {

	private MappingService&MockObject $mappings;

	private MappingMapperInterface&MockObject $mapper;

	private MapNode $node;

	protected function setUp(): void {
		parent::setUp();
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$this->mappings = $this->createMock(MappingService::class);
		$this->mapper = $this->createMock(MappingMapperInterface::class);
		$this->mapper->method('find')->willThrowException(new DoesNotExistException('no row'));
		$this->node = new MapNode($l10n, $this->createMock(IURLGenerator::class), $this->mappings, $this->mapper);
	}//end setUp()

	public function testAMappingKnownOnlyByItsReferenceIsUsed(): void {
		$mapping = new Mapping();
		$this->mapper->expects(self::once())->method('findByRef')->with('zgw-zaak')->willReturn([$mapping]);
		$this->mappings->expects(self::once())->method('executeMapping')->with($mapping, ['a' => 1])->willReturn(['b' => 1]);

		$out = $this->node->execute([FlowItems::item(json: ['a' => 1])], ['mapping' => ' zgw-zaak '], []);

		self::assertSame(['b' => 1], $out[0][FlowItems::JSON]);
	}//end testAMappingKnownOnlyByItsReferenceIsUsed()

	public function testAReferenceNothingAnswersToIsRefusedEvenWhenTheLookupFails(): void {
		$this->mapper->method('findByRef')->willThrowException(new \RuntimeException('db down'));
		$this->mappings->expects(self::never())->method('executeMapping');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('No mapping matches "zgw-zaak" by id, uuid, slug or reference.');

		$this->node->execute([FlowItems::item(json: ['a' => 1])], ['mapping' => 'zgw-zaak'], []);
	}//end testAReferenceNothingAnswersToIsRefusedEvenWhenTheLookupFails()

	public function testAnEmptyReferenceAnswerIsRefused(): void {
		$this->mapper->method('findByRef')->willReturn([]);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('No mapping matches "nope" by id, uuid, slug or reference.');

		$this->node->execute([FlowItems::item(json: [])], ['mapping' => 'nope'], []);
	}//end testAnEmptyReferenceAnswerIsRefused()
}//end class
