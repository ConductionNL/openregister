<?php

/**
 * Behaviour tests for CaseBusinessStateWriter::mirrorResult(): the result and its optional timestamp field.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Case
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Case;

use DateTime;
use OCA\OpenRegister\Db\CaseItem;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Case\CaseBusinessStateWriter;
use PHPUnit\Framework\TestCase;

/**
 * The timestamp is checked by shape and range, never pinned.
 *
 * @covers \OCA\OpenRegister\Service\Case\CaseBusinessStateWriter
 * @uses   \OCA\OpenRegister\Db\CaseItem
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 */
class CaseBusinessStateWriterResultTest extends TestCase
{
    private static function row(array $writeThrough): CaseItem
    {
        $row = new CaseItem();
        $row->setObjectUuid('case-1');
        $row->setRegisterId(3);
        $row->setSchemaId(8);
        $row->setPlanSettings(['writeThrough' => $writeThrough]);
        return $row;
    }//end row()

    public function testAResultWithATimestampFieldWritesBothThroughToTheCaseObject(): void
    {
        $written = [];
        $objects = $this->createMock(ObjectOperationsInterface::class);
        $objects->expects($this->once())
            ->method('patchObject')
            ->willReturnCallback(
                static function (string $objectId, array $data, mixed $register, mixed $schema, bool $_rbac) use (&$written): ObjectEntity {
                    $written = [$objectId, $data, $register, $schema, $_rbac];
                    return new ObjectEntity();
                }
            );
        $before = time();

        $wrote = (new CaseBusinessStateWriter($objects))->mirrorResult(
            self::row(['resultField' => ' outcome ', 'resultAtField' => ' decidedAt ']),
            'granted'
        );

        $this->assertTrue($wrote);
        $this->assertSame(['case-1', 3, 8, false], [$written[0], $written[2], $written[3], $written[4]]);
        $this->assertSame(['outcome', 'decidedAt'], array_keys($written[1]));
        $this->assertSame('granted', $written[1]['outcome']);
        $stamp = DateTime::createFromFormat(DATE_ATOM, $written[1]['decidedAt']);
        $this->assertNotFalse($stamp);
        $this->assertGreaterThanOrEqual($before, $stamp->getTimestamp());
    }//end testAResultWithATimestampFieldWritesBothThroughToTheCaseObject()

    public function testAPlanWithoutAResultFieldWritesNothing(): void
    {
        $objects = $this->createMock(ObjectOperationsInterface::class);
        $objects->expects($this->never())->method('patchObject');

        $this->assertFalse((new CaseBusinessStateWriter($objects))->mirrorResult(self::row(['resultAtField' => 'at']), 'granted'));
    }//end testAPlanWithoutAResultFieldWritesNothing()
}//end class
