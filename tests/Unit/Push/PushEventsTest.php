<?php

/**
 * PushEventsTest
 *
 * Unit tests for the PushEvents constants class.
 *
 * @category Test
 * @package  Unit\Push
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/add-live-updates/tasks.md#task-8
 */

declare(strict_types=1);

namespace Unit\Push;

use OCA\OpenRegister\Push\PushEvents;
use PHPUnit\Framework\TestCase;

/**
 * Tests for PushEvents constants.
 *
 * @coversDefaultClass \OCA\OpenRegister\Push\PushEvents
 */
class PushEventsTest extends TestCase
{
    /**
     * Test that OR_OBJECT equals the expected event string.
     *
     * @return void
     *
     * @spec openspec/changes/add-live-updates/tasks.md#task-8
     */
    public function testOrObjectConstantValue(): void
    {
        $this->assertSame('or-object', PushEvents::OR_OBJECT);
    }//end testOrObjectConstantValue()

    /**
     * Test that OR_COLLECTION equals the expected event string.
     *
     * @return void
     *
     * @spec openspec/changes/add-live-updates/tasks.md#task-8
     */
    public function testOrCollectionConstantValue(): void
    {
        $this->assertSame('or-collection', PushEvents::OR_COLLECTION);
    }//end testOrCollectionConstantValue()

    /**
     * Test that OR_OBJECT is a non-empty string.
     *
     * @return void
     *
     * @spec openspec/changes/add-live-updates/tasks.md#task-8
     */
    public function testOrObjectIsNonEmptyString(): void
    {
        $this->assertIsString(PushEvents::OR_OBJECT);
        $this->assertNotEmpty(PushEvents::OR_OBJECT);
    }//end testOrObjectIsNonEmptyString()

    /**
     * Test that OR_COLLECTION is a non-empty string.
     *
     * @return void
     *
     * @spec openspec/changes/add-live-updates/tasks.md#task-8
     */
    public function testOrCollectionIsNonEmptyString(): void
    {
        $this->assertIsString(PushEvents::OR_COLLECTION);
        $this->assertNotEmpty(PushEvents::OR_COLLECTION);
    }//end testOrCollectionIsNonEmptyString()

    /**
     * Test that OR_OBJECT and OR_COLLECTION are distinct values.
     *
     * @return void
     *
     * @spec openspec/changes/add-live-updates/tasks.md#task-8
     */
    public function testConstantsAreDistinct(): void
    {
        $this->assertNotSame(PushEvents::OR_OBJECT, PushEvents::OR_COLLECTION);
    }//end testConstantsAreDistinct()
}//end class
