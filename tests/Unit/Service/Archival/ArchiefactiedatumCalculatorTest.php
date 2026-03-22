<?php

declare(strict_types=1);

/**
 * ArchiefactiedatumCalculator Unit Tests
 *
 * Tests the archive action date calculator with all three afleidingswijzen.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Archival
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 */

namespace Unit\Service\Archival;

use DateTime;
use OCA\OpenRegister\Service\Archival\ArchiefactiedatumCalculator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Test class for ArchiefactiedatumCalculator
 */
class ArchiefactiedatumCalculatorTest extends TestCase
{
    private LoggerInterface&MockObject $logger;
    private ArchiefactiedatumCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logger     = $this->createMock(LoggerInterface::class);
        $this->calculator = new ArchiefactiedatumCalculator($this->logger);
    }

    /**
     * Test afgehandeld method calculates from closure date + bewaartermijn.
     */
    public function testCalculateAfgehandeld(): void
    {
        $config = [
            'afleidingswijze' => 'afgehandeld',
            'bewaartermijn'   => 'P5Y',
        ];

        $closureDate = new DateTime('2026-03-01');

        $result = $this->calculator->calculate($config, [], $closureDate);

        $this->assertNotNull($result);
        $this->assertEquals('2031-03-01', $result->format('Y-m-d'));
    }

    /**
     * Test afgehandeld without closure date returns null.
     */
    public function testCalculateAfgehandeldNoClosure(): void
    {
        $config = [
            'afleidingswijze' => 'afgehandeld',
            'bewaartermijn'   => 'P5Y',
        ];

        $result = $this->calculator->calculate($config, [], null);

        $this->assertNull($result);
    }

    /**
     * Test eigenschap method calculates from property value + bewaartermijn.
     */
    public function testCalculateEigenschap(): void
    {
        $config = [
            'afleidingswijze' => 'eigenschap',
            'eigenschap'      => 'vervaldatum',
            'bewaartermijn'   => 'P10Y',
        ];

        $objectData = [
            'vervaldatum' => '2028-06-15',
        ];

        $result = $this->calculator->calculate($config, $objectData);

        $this->assertNotNull($result);
        $this->assertEquals('2038-06-15', $result->format('Y-m-d'));
    }

    /**
     * Test eigenschap with missing property returns null.
     */
    public function testCalculateEigenschapMissingProperty(): void
    {
        $config = [
            'afleidingswijze' => 'eigenschap',
            'eigenschap'      => 'vervaldatum',
            'bewaartermijn'   => 'P10Y',
        ];

        $result = $this->calculator->calculate($config, []);

        $this->assertNull($result);
    }

    /**
     * Test termijn method calculates from closure + procestermijn + bewaartermijn.
     */
    public function testCalculateTermijn(): void
    {
        $config = [
            'afleidingswijze' => 'termijn',
            'procestermijn'   => 'P2Y',
            'bewaartermijn'   => 'P5Y',
        ];

        $closureDate = new DateTime('2026-01-01');

        $result = $this->calculator->calculate($config, [], $closureDate);

        $this->assertNotNull($result);
        // brondatum = 2026-01-01 + P2Y = 2028-01-01
        // archiefactiedatum = 2028-01-01 + P5Y = 2033-01-01
        $this->assertEquals('2033-01-01', $result->format('Y-m-d'));
    }

    /**
     * Test missing afleidingswijze returns null.
     */
    public function testCalculateMissingAfleidingswijze(): void
    {
        $config = [
            'bewaartermijn' => 'P5Y',
        ];

        $result = $this->calculator->calculate($config, []);

        $this->assertNull($result);
    }

    /**
     * Test missing bewaartermijn returns null.
     */
    public function testCalculateMissingBewaartermijn(): void
    {
        $config = [
            'afleidingswijze' => 'afgehandeld',
        ];

        $result = $this->calculator->calculate($config, [], new DateTime());

        $this->assertNull($result);
    }

    /**
     * Test invalid bewaartermijn format returns null.
     */
    public function testCalculateInvalidBewaartermijn(): void
    {
        $config = [
            'afleidingswijze' => 'afgehandeld',
            'bewaartermijn'   => 'invalid',
        ];

        $result = $this->calculator->calculate($config, [], new DateTime());

        $this->assertNull($result);
    }

    /**
     * Test unknown afleidingswijze returns null.
     */
    public function testCalculateUnknownAfleidingswijze(): void
    {
        $config = [
            'afleidingswijze' => 'unknown_method',
            'bewaartermijn'   => 'P5Y',
        ];

        $result = $this->calculator->calculate($config, []);

        $this->assertNull($result);
    }

    /**
     * Test recalculation when property value changes.
     */
    public function testRecalculateOnPropertyChange(): void
    {
        $config = [
            'afleidingswijze' => 'eigenschap',
            'eigenschap'      => 'vervaldatum',
            'bewaartermijn'   => 'P10Y',
        ];

        // Original calculation.
        $originalData = ['vervaldatum' => '2028-06-15'];
        $original     = $this->calculator->calculate($config, $originalData);
        $this->assertEquals('2038-06-15', $original->format('Y-m-d'));

        // Recalculation with updated property.
        $updatedData = ['vervaldatum' => '2030-12-31'];
        $updated     = $this->calculator->calculate($config, $updatedData);
        $this->assertEquals('2040-12-31', $updated->format('Y-m-d'));
    }
}
