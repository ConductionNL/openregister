<?php

/**
 * Activity Filter Unit Test
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Activity
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Tests\Unit\Activity;

use OCA\OpenRegister\Activity\Filter;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Activity Filter.
 */
class FilterTest extends TestCase
{
    private Filter $filter;

    protected function setUp(): void
    {
        parent::setUp();
        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnArgument(0);

        $urlGenerator = $this->createMock(IURLGenerator::class);
        $urlGenerator->method('getAbsoluteURL')->willReturn('https://example.com/icon.svg');
        $urlGenerator->method('imagePath')->willReturn('/apps/openregister/img/app-dark.svg');

        $this->filter = new Filter($l, $urlGenerator);
    }

    /**
     * Test: getIdentifier returns openregister.
     */
    public function testGetIdentifier(): void
    {
        $this->assertSame('openregister', $this->filter->getIdentifier());
    }

    /**
     * Test: getName returns translated name.
     */
    public function testGetName(): void
    {
        $this->assertSame('Open Register', $this->filter->getName());
    }

    /**
     * Test: getPriority returns 50.
     */
    public function testGetPriority(): void
    {
        $this->assertSame(50, $this->filter->getPriority());
    }

    /**
     * Test: getIcon returns an absolute URL string.
     */
    public function testGetIcon(): void
    {
        $this->assertStringContainsString('icon.svg', $this->filter->getIcon());
    }

    /**
     * Test: filterTypes returns all three OpenRegister activity types.
     */
    public function testFilterTypes(): void
    {
        $expected = ['openregister_objects', 'openregister_registers', 'openregister_schemas'];
        $this->assertSame($expected, $this->filter->filterTypes([]));
    }

    /**
     * Test: allowedApps returns openregister.
     */
    public function testAllowedApps(): void
    {
        $this->assertSame(['openregister'], $this->filter->allowedApps());
    }
}
