<?php

/**
 * TmloService Unit Tests
 *
 * Tests for TMLO metadata service including:
 * - Populate defaults from schema/register configuration
 * - Validate archival status transitions
 * - Validate TMLO field values
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\TmloService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for TmloService
 *
 * @covers \OCA\OpenRegister\Service\TmloService
 */
class TmloServiceTest extends TestCase
{

    /**
     * The TmloService under test
     *
     * @var TmloService
     */
    private TmloService $service;

    /**
     * Mock register mapper
     *
     * @var RegisterMapper
     */
    private RegisterMapper $registerMapper;

    /**
     * Mock schema mapper
     *
     * @var SchemaMapper
     */
    private SchemaMapper $schemaMapper;

    /**
     * Mock logger
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;


    /**
     * Set up test fixtures
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->registerMapper = $this->createMock(RegisterMapper::class);
        $this->schemaMapper   = $this->createMock(SchemaMapper::class);
        $this->logger         = $this->createMock(LoggerInterface::class);

        $this->service = new TmloService(
            $this->registerMapper,
            $this->schemaMapper,
            $this->logger
        );
    }//end setUp()


    /**
     * Test isTmloEnabled returns true when register has tmloEnabled=true.
     *
     * @return void
     */
    public function testIsTmloEnabledTrue(): void
    {
        $register = $this->createMock(Register::class);
        $register->method('getConfiguration')
            ->willReturn(['tmloEnabled' => true]);

        $this->assertTrue($this->service->isTmloEnabled($register));
    }//end testIsTmloEnabledTrue()


    /**
     * Test isTmloEnabled returns false when tmloEnabled is not set.
     *
     * @return void
     */
    public function testIsTmloEnabledFalse(): void
    {
        $register = $this->createMock(Register::class);
        $register->method('getConfiguration')
            ->willReturn([]);

        $this->assertFalse($this->service->isTmloEnabled($register));
    }//end testIsTmloEnabledFalse()


    /**
     * Test isTmloEnabled returns false when tmloEnabled is explicitly false.
     *
     * @return void
     */
    public function testIsTmloEnabledExplicitlyFalse(): void
    {
        $register = $this->createMock(Register::class);
        $register->method('getConfiguration')
            ->willReturn(['tmloEnabled' => false]);

        $this->assertFalse($this->service->isTmloEnabled($register));
    }//end testIsTmloEnabledExplicitlyFalse()


    /**
     * Test populateDefaults sets archiefstatus to actief by default.
     *
     * @return void
     */
    public function testPopulateDefaultsSetsArchiefstatusActief(): void
    {
        $register = $this->createMock(Register::class);
        $register->method('getConfiguration')
            ->willReturn(['tmloEnabled' => true]);

        $schema = $this->createMock(Schema::class);
        $schema->method('getConfiguration')
            ->willReturn([]);

        $object = new ObjectEntity();

        $result = $this->service->populateDefaults($object, $register, $schema);
        $tmlo   = $result->getTmlo();

        $this->assertEquals('actief', $tmlo['archiefstatus']);
    }//end testPopulateDefaultsSetsArchiefstatusActief()


    /**
     * Test populateDefaults merges schema defaults.
     *
     * @return void
     */
    public function testPopulateDefaultsMergesSchemaDefaults(): void
    {
        $register = $this->createMock(Register::class);
        $register->method('getConfiguration')
            ->willReturn(['tmloEnabled' => true]);

        $schema = $this->createMock(Schema::class);
        $schema->method('getConfiguration')
            ->willReturn([
                'tmloDefaults' => [
                    'classificatie'    => '1.1',
                    'archiefnominatie' => 'vernietigen',
                    'bewaarTermijn'    => 'P7Y',
                ],
            ]);

        $object = new ObjectEntity();

        $result = $this->service->populateDefaults($object, $register, $schema);
        $tmlo   = $result->getTmlo();

        $this->assertEquals('1.1', $tmlo['classificatie']);
        $this->assertEquals('vernietigen', $tmlo['archiefnominatie']);
        $this->assertEquals('P7Y', $tmlo['bewaarTermijn']);
        $this->assertEquals('actief', $tmlo['archiefstatus']);
    }//end testPopulateDefaultsMergesSchemaDefaults()


    /**
     * Test populateDefaults does not override explicit values.
     *
     * @return void
     */
    public function testPopulateDefaultsDoesNotOverrideExplicitValues(): void
    {
        $register = $this->createMock(Register::class);
        $register->method('getConfiguration')
            ->willReturn(['tmloEnabled' => true]);

        $schema = $this->createMock(Schema::class);
        $schema->method('getConfiguration')
            ->willReturn([
                'tmloDefaults' => [
                    'classificatie' => '1.1',
                ],
            ]);

        $object = new ObjectEntity();
        $object->setTmlo(['classificatie' => '2.2']);

        $result = $this->service->populateDefaults($object, $register, $schema);
        $tmlo   = $result->getTmlo();

        $this->assertEquals('2.2', $tmlo['classificatie']);
    }//end testPopulateDefaultsDoesNotOverrideExplicitValues()


    /**
     * Test populateDefaults does nothing when TMLO is disabled.
     *
     * @return void
     */
    public function testPopulateDefaultsSkipsWhenDisabled(): void
    {
        $register = $this->createMock(Register::class);
        $register->method('getConfiguration')
            ->willReturn(['tmloEnabled' => false]);

        $schema = $this->createMock(Schema::class);
        $object = new ObjectEntity();

        $result = $this->service->populateDefaults($object, $register, $schema);
        $tmlo   = $result->getTmlo();

        $this->assertEmpty($tmlo);
    }//end testPopulateDefaultsSkipsWhenDisabled()


    /**
     * Test populateDefaults calculates archiefactiedatum from bewaarTermijn.
     *
     * @return void
     */
    public function testPopulateDefaultsCalculatesArchiefactiedatum(): void
    {
        $register = $this->createMock(Register::class);
        $register->method('getConfiguration')
            ->willReturn(['tmloEnabled' => true]);

        $schema = $this->createMock(Schema::class);
        $schema->method('getConfiguration')
            ->willReturn([
                'tmloDefaults' => [
                    'bewaarTermijn' => 'P1Y',
                ],
            ]);

        $object = new ObjectEntity();

        $result = $this->service->populateDefaults($object, $register, $schema);
        $tmlo   = $result->getTmlo();

        $this->assertNotNull($tmlo['archiefactiedatum']);
        // Should be approximately 1 year from now.
        $expectedDate = (new \DateTime())->modify('+1 year')->format('Y-m-d');
        $this->assertEquals($expectedDate, $tmlo['archiefactiedatum']);
    }//end testPopulateDefaultsCalculatesArchiefactiedatum()


    /**
     * Test validateFieldValues accepts valid archiefnominatie.
     *
     * @return void
     */
    public function testValidateFieldValuesAcceptsValidArchiefnominatie(): void
    {
        $errors = $this->service->validateFieldValues([
            'archiefnominatie' => 'blijvend_bewaren',
        ]);

        $this->assertEmpty($errors);
    }//end testValidateFieldValuesAcceptsValidArchiefnominatie()


    /**
     * Test validateFieldValues rejects invalid archiefnominatie.
     *
     * @return void
     */
    public function testValidateFieldValuesRejectsInvalidArchiefnominatie(): void
    {
        $errors = $this->service->validateFieldValues([
            'archiefnominatie' => 'invalid_value',
        ]);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('archiefnominatie', $errors[0]);
    }//end testValidateFieldValuesRejectsInvalidArchiefnominatie()


    /**
     * Test validateFieldValues rejects invalid bewaarTermijn.
     *
     * @return void
     */
    public function testValidateFieldValuesRejectsInvalidDuration(): void
    {
        $errors = $this->service->validateFieldValues([
            'bewaarTermijn' => 'not-a-duration',
        ]);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('bewaarTermijn', $errors[0]);
    }//end testValidateFieldValuesRejectsInvalidDuration()


    /**
     * Test validateFieldValues accepts valid ISO-8601 duration.
     *
     * @return void
     */
    public function testValidateFieldValuesAcceptsValidDuration(): void
    {
        $errors = $this->service->validateFieldValues([
            'bewaarTermijn' => 'P10Y',
        ]);

        $this->assertEmpty($errors);
    }//end testValidateFieldValuesAcceptsValidDuration()


    /**
     * Test validateStatusTransition allows actief to semi_statisch.
     *
     * @return void
     */
    public function testValidateTransitionActiefToSemiStatisch(): void
    {
        $errors = $this->service->validateStatusTransition(
            ['archiefstatus' => 'semi_statisch'],
            'actief'
        );

        $this->assertEmpty($errors);
    }//end testValidateTransitionActiefToSemiStatisch()


    /**
     * Test validateStatusTransition rejects actief to overgebracht.
     *
     * @return void
     */
    public function testValidateTransitionActiefToOvergebrachtRejected(): void
    {
        $errors = $this->service->validateStatusTransition(
            ['archiefstatus' => 'overgebracht'],
            'actief'
        );

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('not allowed', $errors[0]);
    }//end testValidateTransitionActiefToOvergebrachtRejected()


    /**
     * Test validateStatusTransition to overgebracht requires classificatie.
     *
     * @return void
     */
    public function testValidateTransitionToOvergebrachtRequiresFields(): void
    {
        $errors = $this->service->validateStatusTransition(
            [
                'archiefstatus'    => 'overgebracht',
                'archiefnominatie' => 'blijvend_bewaren',
                'archiefactiedatum' => '2025-01-01',
                // classificatie is missing.
            ],
            'semi_statisch'
        );

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('classificatie', $errors[0]);
    }//end testValidateTransitionToOvergebrachtRequiresFields()


    /**
     * Test validateStatusTransition to vernietigd requires vernietigen nominatie.
     *
     * @return void
     */
    public function testValidateTransitionToVernietigdRequiresVernietiginNominatie(): void
    {
        $errors = $this->service->validateStatusTransition(
            [
                'archiefstatus'         => 'vernietigd',
                'archiefnominatie'      => 'blijvend_bewaren',
                'archiefactiedatum'     => '2025-01-01',
                'classificatie'         => '1.1',
                'vernietigingsCategorie' => 'cat1',
            ],
            'semi_statisch'
        );

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('vernietigen', $errors[0]);
    }//end testValidateTransitionToVernietigdRequiresVernietiginNominatie()


    /**
     * Test validateStatusTransition returns empty when no status change.
     *
     * @return void
     */
    public function testValidateTransitionNoChangeReturnsEmpty(): void
    {
        $errors = $this->service->validateStatusTransition(
            ['archiefstatus' => 'actief'],
            'actief'
        );

        $this->assertEmpty($errors);
    }//end testValidateTransitionNoChangeReturnsEmpty()


    /**
     * Test valid transition to overgebracht with all required fields.
     *
     * @return void
     */
    public function testValidTransitionToOvergebracht(): void
    {
        $errors = $this->service->validateStatusTransition(
            [
                'archiefstatus'     => 'overgebracht',
                'archiefnominatie'  => 'blijvend_bewaren',
                'archiefactiedatum' => '2025-06-01',
                'classificatie'     => '1.1',
            ],
            'semi_statisch'
        );

        $this->assertEmpty($errors);
    }//end testValidTransitionToOvergebracht()


    /**
     * Test calculateArchiefactiedatum with valid duration.
     *
     * @return void
     */
    public function testCalculateArchiefactiedatumValid(): void
    {
        $result = $this->service->calculateArchiefactiedatum('P7Y');
        $this->assertNotNull($result);

        $expected = (new \DateTime())->modify('+7 years')->format('Y-m-d');
        $this->assertEquals($expected, $result);
    }//end testCalculateArchiefactiedatumValid()


    /**
     * Test calculateArchiefactiedatum with invalid duration.
     *
     * @return void
     */
    public function testCalculateArchiefactiedatumInvalid(): void
    {
        $result = $this->service->calculateArchiefactiedatum('invalid');
        $this->assertNull($result);
    }//end testCalculateArchiefactiedatumInvalid()


    /**
     * Test getSchemaDefaults returns tmloDefaults from schema configuration.
     *
     * @return void
     */
    public function testGetSchemaDefaults(): void
    {
        $schema = $this->createMock(Schema::class);
        $schema->method('getConfiguration')
            ->willReturn([
                'tmloDefaults' => [
                    'classificatie' => '1.1',
                    'bewaarTermijn' => 'P5Y',
                ],
            ]);

        $defaults = $this->service->getSchemaDefaults($schema);

        $this->assertEquals('1.1', $defaults['classificatie']);
        $this->assertEquals('P5Y', $defaults['bewaarTermijn']);
    }//end testGetSchemaDefaults()


    /**
     * Test getSchemaDefaults returns empty array when no tmloDefaults.
     *
     * @return void
     */
    public function testGetSchemaDefaultsEmpty(): void
    {
        $schema = $this->createMock(Schema::class);
        $schema->method('getConfiguration')
            ->willReturn([]);

        $defaults = $this->service->getSchemaDefaults($schema);

        $this->assertEmpty($defaults);
    }//end testGetSchemaDefaultsEmpty()


}//end class
