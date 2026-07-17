<?php

/**
 * `markDerivedTranslationsOutdated` tests for TranslationStatusService.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/i18n-source-of-truth/tasks.md#phase-2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\TranslationMapper;
use OCA\OpenRegister\Service\Object\TranslationHandler;
use OCA\OpenRegister\Service\TranslationStatusService;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the outdated-flip contract.
 */
class TranslationStatusServiceOutdatedTest extends TestCase
{

    /**
     * Happy path: mapper is asked to flip derived rows; the returned count
     * bubbles up.
     */
    public function testFlipsDerivedRowsAndReturnsCount(): void
    {
        $mapper = $this->createMock(TranslationMapper::class);
        $mapper->expects($this->once())
            ->method('markDerivedOutdated')
            ->with('obj-uuid', 'title', 'nl')
            ->willReturn(3);

        $service = new TranslationStatusService(
            $mapper,
            $this->createMock(TranslationHandler::class),
            $this->createMock(IUserSession::class)
        );

        $count = $service->markDerivedTranslationsOutdated('obj-uuid', 'title', 'nl');
        $this->assertSame(3, $count);
    }//end testFlipsDerivedRowsAndReturnsCount()

    /**
     * Empty inputs short-circuit to 0 without touching the mapper.
     */
    public function testEmptyArgumentsReturnZero(): void
    {
        $mapper = $this->createMock(TranslationMapper::class);
        $mapper->expects($this->never())->method('markDerivedOutdated');

        $service = new TranslationStatusService(
            $mapper,
            $this->createMock(TranslationHandler::class),
            $this->createMock(IUserSession::class)
        );

        $this->assertSame(0, $service->markDerivedTranslationsOutdated('', 'title', 'nl'));
        $this->assertSame(0, $service->markDerivedTranslationsOutdated('uuid', '', 'nl'));
        $this->assertSame(0, $service->markDerivedTranslationsOutdated('uuid', 'title', ''));
    }//end testEmptyArgumentsReturnZero()
}//end class
