<?php

/**
 * The two object-facing collaborators: the anchor reader (system read for
 * sentries, RBAC read for visibility, both fail-closed) and the business-state
 * writer (patches only when the plan maps a field, through the ordinary path).
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Case
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Case;

use OCA\OpenRegister\Db\CaseItem;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Case\CaseAnchorReader;
use OCA\OpenRegister\Service\Case\CaseBusinessStateWriter;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Coverage of CaseAnchorReader and CaseBusinessStateWriter.
 *
 * @covers \OCA\OpenRegister\Service\Case\CaseAnchorReader
 * @covers \OCA\OpenRegister\Service\Case\CaseBusinessStateWriter
 */
class CaseAnchorAndWriterTest extends TestCase {

	/**
	 * read(): the object's data; [] on null; [] on failure. mayRead(): true
	 * only on a successful RBAC read.
	 *
	 * @return void
	 */
	public function testTheAnchorReaderFailsClosed(): void {
		$entity = new ObjectEntity();
		$entity->setUuid('obj-1');
		$entity->setObject(['status' => 'open']);
		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturnCallback(
			static function (int|string $id): ?ObjectEntity {
				if ($id === 'missing') {
					return null;
				}

				if ($id === 'broken') {
					throw new RuntimeException('no such table');
				}

				return new ObjectEntity();
			}
		);
		$reader = new CaseAnchorReader(objects: $objects, logger: new NullLogger());

		$this->assertSame([], $reader->read(objectUuid: 'missing', registerId: 1, schemaId: 1));
		$this->assertSame([], $reader->read(objectUuid: 'broken', registerId: 1, schemaId: 1));
		$this->assertIsArray($reader->read(objectUuid: 'ok', registerId: null, schemaId: null));
		$this->assertFalse($reader->mayRead(objectUuid: 'missing', registerId: 1, schemaId: 1));
		$this->assertFalse($reader->mayRead(objectUuid: 'broken', registerId: 1, schemaId: 1));
		$this->assertTrue($reader->mayRead(objectUuid: 'ok', registerId: 1, schemaId: 1));

		$loaded = $this->createMock(ObjectService::class);
		$loaded->method('find')->willReturn($entity);
		$this->assertSame(['id' => 'obj-1', 'status' => 'open'], (new CaseAnchorReader(objects: $loaded, logger: new NullLogger()))->read(objectUuid: 'x', registerId: 1, schemaId: 1));
	}//end testTheAnchorReaderFailsClosed()

	/**
	 * mirrorStatus() and mirrorResult() patch the mapped fields with a stamp;
	 * without a mapping nothing is written.
	 *
	 * @return void
	 */
	public function testTheWriterPatchesOnlyMappedFields(): void {
		$objects = $this->createMock(ObjectService::class);
		$patches = [];
		$objects->method('patchObject')->willReturnCallback(
			static function (string $objectId, array $data) use (&$patches): ObjectEntity {
				$patches[] = [$objectId, $data];

				return new ObjectEntity();
			}
		);
		$writer = new CaseBusinessStateWriter(objects: $objects);

		$milestone = CaseFixtures::row(id: 1, key: 'application-complete', type: CaseItem::TYPE_MILESTONE, state: CaseItem::STATE_COMPLETED);
		$milestone->setName('Aanvraag volledig');
		$milestone->setPlanSettings(['writeThrough' => ['statusField' => 'status', 'statusAtField' => 'statusReachedAt', 'resultField' => 'resultaat']]);

		$this->assertTrue($writer->mirrorStatus(milestone: $milestone));
		$this->assertSame(CaseFixtures::OBJECT, $patches[0][0]);
		$this->assertSame('Aanvraag volledig', $patches[0][1]['status']);
		$this->assertArrayHasKey('statusReachedAt', $patches[0][1]);

		$this->assertTrue($writer->mirrorResult(anyRow: $milestone, result: 'verleend'));
		$this->assertSame(['resultaat' => 'verleend'], $patches[1][1], 'No resultAtField mapped: no stamp.');

		$unmapped = CaseFixtures::row(id: 2, key: 'm', type: CaseItem::TYPE_MILESTONE, state: CaseItem::STATE_COMPLETED);
		$unmapped->setPlanSettings(['writeThrough' => 'status']);
		$this->assertFalse($writer->mirrorStatus(milestone: $unmapped));
		$this->assertFalse($writer->mirrorResult(anyRow: $unmapped, result: 'x'));
		$unmapped->setPlanSettings(null);
		$this->assertFalse($writer->mirrorStatus(milestone: $unmapped));
		$this->assertCount(2, $patches);

		$keyed = CaseFixtures::row(id: 3, key: 'ontvangen', type: CaseItem::TYPE_MILESTONE, state: CaseItem::STATE_COMPLETED);
		$keyed->setName(null);
		$keyed->setPlanSettings(['writeThrough' => ['statusField' => 'status']]);
		$writer->mirrorStatus(milestone: $keyed);
		$this->assertSame(['status' => 'ontvangen'], $patches[2][1], 'Without a name the key is the status.');
	}//end testTheWriterPatchesOnlyMappedFields()
}//end class
