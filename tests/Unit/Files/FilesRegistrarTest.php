<?php

/**
 * The Files component binds its interfaces to their classes.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Files
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Files;

use OCA\OpenRegister\Files\FilesRegistrar;
use OCA\OpenRegister\Files\Service\NoteService;
use OCA\OpenRegister\Tests\Unit\AppHost\RecordingRegistrationContext;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Files\FilesRegistrar
 */
class FilesRegistrarTest extends TestCase
{
    public function testEveryInterfaceIsBoundToItsImplementation(): void
    {
        $context = new RecordingRegistrationContext();
        FilesRegistrar::register(context: $context);

        $expected = [];
        foreach (FilesRegistrar::BINDINGS as $contract => $service) {
            $this->assertTrue(is_subclass_of($service, $contract), $service.' implements '.$contract);
            $expected[] = ['alias' => $contract, 'target' => $service];
        }

        $this->assertCount(20, $expected);
        $this->assertSame(expected: $expected, actual: $context->aliases);
    }//end testEveryInterfaceIsBoundToItsImplementation()

    public function testNoteServiceHasAFactory(): void
    {
        $context = new RecordingRegistrationContext();
        FilesRegistrar::register(context: $context);

        $this->assertContains(NoteService::class, $context->services);
    }//end testNoteServiceHasAFactory()
}//end class
