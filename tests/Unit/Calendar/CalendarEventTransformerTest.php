<?php

/**
 * Unit tests for CalendarEventTransformer
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Calendar
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace Unit\Calendar;

use OCA\OpenRegister\Calendar\CalendarEventTransformer;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use PHPUnit\Framework\TestCase;

class CalendarEventTransformerTest extends TestCase
{
    private CalendarEventTransformer $transformer;
    private Schema $schema;

    protected function setUp(): void
    {
        $this->transformer = new CalendarEventTransformer();

        $this->schema = new Schema();
        $this->schema->setId(12);
        $this->schema->setTitle('Zaken');
        $this->schema->setProperties([
            'startdatum' => ['type' => 'string', 'format' => 'date'],
            'einddatum'  => ['type' => 'string', 'format' => 'date'],
            'naam'       => ['type' => 'string'],
            'locatie'    => ['type' => 'string'],
        ]);
    }

    private function createObjectEntity(array $data, string $uuid = 'abc-123', int $register = 5): ObjectEntity
    {
        $object = new ObjectEntity();
        $object->setObject($data);
        $object->setUuid($uuid);
        $object->setRegister($register);
        return $object;
    }

    public function testAllDayEventTransformation(): void
    {
        $object = $this->createObjectEntity([
            'startdatum' => '2026-03-25',
            'naam'       => 'Test Zaak',
        ]);

        $config = [
            'enabled'       => true,
            'dtstart'       => 'startdatum',
            'titleTemplate' => '{naam}',
            'allDay'        => true,
        ];

        $result = $this->transformer->transform($object, $this->schema, $config);

        $this->assertNotNull($result);
        $this->assertSame('DATE', $result['objects'][0]['DTSTART'][1]['VALUE']);
        $this->assertSame('20260325', $result['objects'][0]['DTSTART'][0]);
    }

    public function testDateTimeEventTransformation(): void
    {
        $object = $this->createObjectEntity([
            'startdatum' => '2026-03-25T14:00:00',
            'naam'       => 'Test',
        ]);

        $config = [
            'enabled'       => true,
            'dtstart'       => 'startdatum',
            'titleTemplate' => '{naam}',
            'allDay'        => false,
        ];

        $result = $this->transformer->transform($object, $this->schema, $config);

        $this->assertSame('DATE-TIME', $result['objects'][0]['DTSTART'][1]['VALUE']);
        $this->assertSame('20260325T140000Z', $result['objects'][0]['DTSTART'][0]);
    }

    public function testTitleTemplateInterpolation(): void
    {
        $object = $this->createObjectEntity([
            'startdatum' => '2026-03-25',
            'naam'       => 'Vergunning',
            'locatie'    => 'Tilburg',
        ]);

        $config = [
            'enabled'       => true,
            'dtstart'       => 'startdatum',
            'titleTemplate' => '{naam} - {locatie}',
        ];

        $result = $this->transformer->transform($object, $this->schema, $config);

        $this->assertSame('Vergunning - Tilburg', $result['objects'][0]['SUMMARY'][0]);
    }

    public function testTitleTemplateWithMissingFields(): void
    {
        $object = $this->createObjectEntity([
            'startdatum' => '2026-03-25',
        ]);

        $config = [
            'enabled'       => true,
            'dtstart'       => 'startdatum',
            'titleTemplate' => '{naam} - {missing}',
        ];

        $result = $this->transformer->transform($object, $this->schema, $config);

        $this->assertSame(' - ', $result['objects'][0]['SUMMARY'][0]);
    }

    public function testDescriptionTemplateInterpolation(): void
    {
        $object = $this->createObjectEntity([
            'startdatum' => '2026-03-25',
            'naam'       => 'Test',
            'locatie'    => 'Amsterdam',
        ]);

        $config = [
            'enabled'             => true,
            'dtstart'             => 'startdatum',
            'titleTemplate'       => '{naam}',
            'descriptionTemplate' => 'Locatie: {locatie}',
        ];

        $result = $this->transformer->transform($object, $this->schema, $config);

        $this->assertSame('Locatie: Amsterdam', $result['objects'][0]['DESCRIPTION'][0]);
    }

    public function testLocationFieldMapping(): void
    {
        $object = $this->createObjectEntity([
            'startdatum' => '2026-03-25',
            'naam'       => 'Test',
            'locatie'    => 'Kerkstraat 42',
        ]);

        $config = [
            'enabled'       => true,
            'dtstart'       => 'startdatum',
            'titleTemplate' => '{naam}',
            'locationField' => 'locatie',
        ];

        $result = $this->transformer->transform($object, $this->schema, $config);

        $this->assertSame('Kerkstraat 42', $result['objects'][0]['LOCATION'][0]);
    }

    public function testStatusMappingWithConfiguredMapping(): void
    {
        $object = $this->createObjectEntity([
            'startdatum' => '2026-03-25',
            'naam'       => 'Test',
            'status'     => 'afgerond',
        ]);

        $config = [
            'enabled'       => true,
            'dtstart'       => 'startdatum',
            'titleTemplate' => '{naam}',
            'statusField'   => 'status',
            'statusMapping' => [
                'open'      => 'CONFIRMED',
                'afgerond'  => 'CANCELLED',
            ],
        ];

        $result = $this->transformer->transform($object, $this->schema, $config);

        $this->assertSame('CANCELLED', $result['objects'][0]['STATUS'][0]);
    }

    public function testDefaultStatusWhenNoMappingConfigured(): void
    {
        $object = $this->createObjectEntity([
            'startdatum' => '2026-03-25',
            'naam'       => 'Test',
        ]);

        $config = [
            'enabled'       => true,
            'dtstart'       => 'startdatum',
            'titleTemplate' => '{naam}',
        ];

        $result = $this->transformer->transform($object, $this->schema, $config);

        $this->assertSame('CONFIRMED', $result['objects'][0]['STATUS'][0]);
    }

    public function testTranspIsAlwaysTransparent(): void
    {
        $object = $this->createObjectEntity([
            'startdatum' => '2026-03-25',
            'naam'       => 'Test',
        ]);

        $config = [
            'enabled'       => true,
            'dtstart'       => 'startdatum',
            'titleTemplate' => '{naam}',
        ];

        $result = $this->transformer->transform($object, $this->schema, $config);

        $this->assertSame('TRANSPARENT', $result['objects'][0]['TRANSP'][0]);
    }

    public function testUrlGeneration(): void
    {
        $object = $this->createObjectEntity([
            'startdatum' => '2026-03-25',
            'naam'       => 'Test',
        ], 'abc-123', 5);

        $config = [
            'enabled'       => true,
            'dtstart'       => 'startdatum',
            'titleTemplate' => '{naam}',
        ];

        $result = $this->transformer->transform($object, $this->schema, $config);

        $this->assertSame('/apps/openregister/#/objects/5/12/abc-123', $result['objects'][0]['URL'][0]);
    }

    public function testCategoriesIncludeOpenRegisterAndSchemaName(): void
    {
        $object = $this->createObjectEntity([
            'startdatum' => '2026-03-25',
            'naam'       => 'Test',
        ]);

        $config = [
            'enabled'       => true,
            'dtstart'       => 'startdatum',
            'titleTemplate' => '{naam}',
        ];

        $result = $this->transformer->transform($object, $this->schema, $config);

        $categories = $result['objects'][0]['CATEGORIES'][0];
        $this->assertSame(['OpenRegister', 'Zaken'], $categories);
    }

    public function testUidStability(): void
    {
        $object = $this->createObjectEntity([
            'startdatum' => '2026-03-25',
            'naam'       => 'Test',
        ], 'stable-uuid-123');

        $config = [
            'enabled'       => true,
            'dtstart'       => 'startdatum',
            'titleTemplate' => '{naam}',
        ];

        $result1 = $this->transformer->transform($object, $this->schema, $config);
        $result2 = $this->transformer->transform($object, $this->schema, $config);

        $this->assertSame($result1['id'], $result2['id']);
        $this->assertSame('openregister-12-stable-uuid-123', $result1['id']);
    }

    public function testAutoDetectionOfAllDayFromPropertyFormat(): void
    {
        // Schema has 'startdatum' with format 'date' -> should be all-day.
        $config = [
            'enabled'       => true,
            'dtstart'       => 'startdatum',
            'titleTemplate' => '{naam}',
            // No explicit allDay setting.
        ];

        $isAllDay = $this->transformer->determineAllDay($config, $this->schema, 'startdatum');

        $this->assertTrue($isAllDay);
    }

    public function testExplicitAllDayOverride(): void
    {
        // Even though property format is 'date', explicit allDay=false overrides.
        $config = [
            'enabled'       => true,
            'dtstart'       => 'startdatum',
            'titleTemplate' => '{naam}',
            'allDay'        => false,
        ];

        $isAllDay = $this->transformer->determineAllDay($config, $this->schema, 'startdatum');

        $this->assertFalse($isAllDay);
    }

    public function testReturnsNullWhenDtstartFieldEmpty(): void
    {
        $object = $this->createObjectEntity([
            'naam' => 'Test',
            // No startdatum.
        ]);

        $config = [
            'enabled'       => true,
            'dtstart'       => 'startdatum',
            'titleTemplate' => '{naam}',
        ];

        $result = $this->transformer->transform($object, $this->schema, $config);

        $this->assertNull($result);
    }

    public function testDefaultDtendForAllDayEvent(): void
    {
        $object = $this->createObjectEntity([
            'startdatum' => '2026-03-25',
            'naam'       => 'Test',
        ]);

        $config = [
            'enabled'       => true,
            'dtstart'       => 'startdatum',
            'titleTemplate' => '{naam}',
            'allDay'        => true,
        ];

        $result = $this->transformer->transform($object, $this->schema, $config);

        // Default DTEND should be start + 1 day.
        $this->assertSame('20260326', $result['objects'][0]['DTEND'][0]);
        $this->assertSame('DATE', $result['objects'][0]['DTEND'][1]['VALUE']);
    }

    public function testConfiguredDtendField(): void
    {
        $object = $this->createObjectEntity([
            'startdatum' => '2026-03-25',
            'einddatum'  => '2026-04-10',
            'naam'       => 'Test',
        ]);

        $config = [
            'enabled'       => true,
            'dtstart'       => 'startdatum',
            'dtend'         => 'einddatum',
            'titleTemplate' => '{naam}',
            'allDay'        => true,
        ];

        $result = $this->transformer->transform($object, $this->schema, $config);

        $this->assertSame('20260410', $result['objects'][0]['DTEND'][0]);
    }
}
