<?php

/**
 * Pins how the link step writes the created object's uuid into the relation:
 * a scalar relation is replaced, a list relation gains the uuid once, and a
 * source object that vanished is refused before anything is written back.
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

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\Nodes\LinkObjectNode;
use OCA\OpenRegister\Service\Handoff\HandoffService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use OCA\OpenRegister\Service\Object\ObjectSaveOptions;

class LinkObjectRelationGoldenTest extends TestCase {

	private ObjectService&MockObject $objects;

	/**
	 * Every saveObject() call, by position.
	 *
	 * @var array<int, array>
	 */
	private array $saved = [];

	protected function setUp(): void {
		parent::setUp();

		$this->objects = $this->createMock(ObjectService::class);
		$this->objects->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ObjectSaveOptions $options = new ObjectSaveOptions(), bool $_rbac = true, bool $_multitenancy = true): ObjectEntity {
				$args = [$object, $options->extend, $options->target->register, $options->target->schema, $options->uuid, $_rbac, $_multitenancy, $options->policy->silent, $options->policy->validation, $options->uploadedFiles, $options->actor->currentUser, $options->policy->failIfExists, $options->actor->unowned, $options->policy->dedupOverride, $options->actor->owner];
				$this->saved[] = $args;
				return $this->object('n-1', []);
			}
		);
	}//end setUp()

	private function link(): LinkObjectNode {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		return new LinkObjectNode($this->objects, $this->createMock(HandoffService::class), $l10n, $this->createMock(IURLGenerator::class));
	}//end link()

	private function object(string $uuid, array $data): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setRegister('3');
		$object->setSchema('7');
		$object->setObject($data);
		return $object;
	}//end object()

	private function linkOne(): array {
		return $this->link()->execute(
			[FlowItems::item(json: ['uuid' => 'req-1', '@self' => ['register' => '3', 'schema' => '7']])],
			['register' => 'notes', 'schema' => 'note', 'data' => [], 'relation' => 'note'],
			[]
		);
	}//end linkOne()

	public function testAScalarRelationIsReplacedByTheNewUuid(): void {
		$this->objects->method('find')->willReturn($this->object('req-1', ['note' => 'n-0', 'title' => 't']));

		$this->linkOne();

		$this->assertCount(2, $this->saved);
		$this->assertSame(['id' => 'req-1', 'note' => 'n-1', 'title' => 't'], $this->saved[1][0]);
		$this->assertSame('3', $this->saved[1][2]);
		$this->assertSame('7', $this->saved[1][3]);
		$this->assertSame('req-1', $this->saved[1][4]);
	}//end testAScalarRelationIsReplacedByTheNewUuid()

	public function testAnAbsentRelationIsSet(): void {
		$this->objects->method('find')->willReturn($this->object('req-1', []));

		$this->linkOne();

		$this->assertSame(['id' => 'req-1', 'note' => 'n-1'], $this->saved[1][0]);
	}//end testAnAbsentRelationIsSet()

	public function testAListRelationGainsTheUuidOnce(): void {
		$this->objects->method('find')->willReturn($this->object('req-1', ['note' => ['n-1', 'n-0']]));

		$this->linkOne();

		$this->assertSame(['n-1', 'n-0'], $this->saved[1][0]['note']);
	}//end testAListRelationGainsTheUuidOnce()

	public function testAVanishedSourceIsRefusedBeforeAnyWriteBack(): void {
		$this->objects->method('find')->willReturn(null);

		try {
			$this->linkOne();
			$this->fail('A vanished source was linked.');
		} catch (RuntimeException $e) {
			$this->assertSame('The object to link to no longer exists.', $e->getMessage());
		}

		$this->assertCount(1, $this->saved);
	}//end testAVanishedSourceIsRefusedBeforeAnyWriteBack()
}//end class
