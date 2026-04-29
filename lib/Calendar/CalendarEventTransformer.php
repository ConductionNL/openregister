<?php

/**
 * OpenRegister Calendar Event Transformer
 *
 * Transforms ObjectEntity data into VEVENT-compatible arrays
 * for the Nextcloud Calendar app.
 *
 * @category Calendar
 * @package  OCA\OpenRegister\Calendar
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Calendar;

use DateTime;
use DateInterval;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;

/**
 * Transforms OpenRegister objects into VEVENT-compatible arrays
 *
 * This class converts ObjectEntity instances into the array format expected
 * by Nextcloud's ICalendar::search() return type, following RFC 5545 VEVENT
 * conventions.
 *
 * @package OCA\OpenRegister\Calendar
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class CalendarEventTransformer
{

    /**
     * Default calendar color when not configured
     *
     * @var string
     */
    public const DEFAULT_COLOR = '#0082C9';

    /**
     * Transform an ObjectEntity into a VEVENT-compatible array
     *
     * @param ObjectEntity $object         The object to transform
     * @param Schema       $schema         The schema this object belongs to
     * @param array        $calendarConfig The calendar provider configuration
     *
     * @return array|null The VEVENT array, or null if the object lacks required date data
     */
    public function transform(
        ObjectEntity $object,
        Schema $schema,
        array $calendarConfig
    ): ?array {
        $objectData   = $object->getObject();
        $dtstartField = $calendarConfig['dtstart'] ?? null;

        if ($dtstartField === null) {
            return null;
        }

        $dtstartValue = $objectData[$dtstartField] ?? null;

        if (empty($dtstartValue) === true) {
            return null;
        }

        $schemaId    = $schema->getId();
        $objectUuid  = $object->getUuid();
        $uid         = 'openregister-'.$schemaId.'-'.$objectUuid;
        $calendarKey = 'openregister-schema-'.$schemaId;

        // Determine allDay mode.
        $allDay = $this->determineAllDay(calendarConfig: $calendarConfig, schema: $schema, dtstartField: $dtstartField);

        // Build DTSTART.
        $dtstart = $this->formatDateValue(value: $dtstartValue, allDay: $allDay);

        // Build DTEND.
        $dtend = $this->buildDtend(objectData: $objectData, calendarConfig: $calendarConfig, dtstartValue: $dtstartValue, allDay: $allDay);

        // Interpolate title.
        $summary = $this->interpolateTemplate(
            template: $calendarConfig['titleTemplate'] ?? $objectUuid,
            objectData: $objectData
        );

        // Build the VEVENT objects array.
        $veventProperties = [
            'UID'        => [$uid, []],
            'SUMMARY'    => [$summary, []],
            'DTSTART'    => $dtstart,
            'DTEND'      => $dtend,
            'STATUS'     => [$this->resolveStatus(objectData: $objectData, calendarConfig: $calendarConfig), []],
            'TRANSP'     => ['TRANSPARENT', []],
            'CATEGORIES' => [['OpenRegister', $schema->getTitle() ?? 'Schema'], []],
        ];

        // Optional description.
        if (empty($calendarConfig['descriptionTemplate']) === false) {
            $description = $this->interpolateTemplate(
                template: $calendarConfig['descriptionTemplate'],
                objectData: $objectData
            );
            $veventProperties['DESCRIPTION'] = [$description, []];
        }

        // Optional location.
        if (empty($calendarConfig['locationField']) === false) {
            $locationValue = $objectData[$calendarConfig['locationField']] ?? null;
            if (empty($locationValue) === false) {
                $veventProperties['LOCATION'] = [$locationValue, []];
            }
        }

        // URL to OpenRegister object.
        $register = $object->getRegister();
        $url      = '/apps/openregister/#/objects/'.$register.'/'.$schemaId.'/'.$objectUuid;
        $veventProperties['URL'] = [$url, []];

        return [
            'id'           => $uid,
            'type'         => 'VEVENT',
            'calendar-key' => $calendarKey,
            'calendar-uri' => $calendarKey,
            'objects'      => [
                $veventProperties,
            ],
        ];
    }//end transform()

    /**
     * Determine if events should be all-day based on config and schema property format
     *
     * @param array  $calendarConfig The calendar configuration
     * @param Schema $schema         The schema entity
     * @param string $dtstartField   The dtstart field name
     *
     * @return bool True if events should be all-day
     */
    public function determineAllDay(array $calendarConfig, Schema $schema, string $dtstartField): bool
    {
        // Explicit allDay setting takes precedence.
        if (isset($calendarConfig['allDay']) === true) {
            return (bool) $calendarConfig['allDay'];
        }

        // Auto-detect from schema property format.
        $properties = $schema->getProperties() ?? [];
        foreach ($properties as $propName => $propDef) {
            if (is_array($propDef) === true
                && ($propName === $dtstartField || ($propDef['title'] ?? null) === $dtstartField)
            ) {
                $format = $propDef['format'] ?? null;
                if ($format === 'date') {
                    return true;
                }

                if ($format === 'date-time') {
                    return false;
                }
            }
        }

        // Default: treat as all-day.
        return true;
    }//end determineAllDay()

    /**
     * Format a date value into iCalendar format
     *
     * @param string $value  The date/datetime string
     * @param bool   $allDay Whether this is an all-day event
     *
     * @return array The formatted [value, params] array
     */
    public function formatDateValue(string $value, bool $allDay): array
    {
        if ($allDay === true) {
            $date = new DateTime($value);
            return [$date->format('Ymd'), ['VALUE' => 'DATE']];
        }

        $date = new DateTime($value);
        return [$date->format('Ymd\THis\Z'), ['VALUE' => 'DATE-TIME']];
    }//end formatDateValue()

    /**
     * Build DTEND value from configuration
     *
     * @param array  $objectData     The object data
     * @param array  $calendarConfig The calendar configuration
     * @param string $dtstartValue   The DTSTART raw value
     * @param bool   $allDay         Whether this is an all-day event
     *
     * @return array The formatted [value, params] array for DTEND
     */
    private function buildDtend(
        array $objectData,
        array $calendarConfig,
        string $dtstartValue,
        bool $allDay
    ): array {
        // Check if dtend field is configured and has a value.
        if (empty($calendarConfig['dtend']) === false) {
            $dtendValue = $objectData[$calendarConfig['dtend']] ?? null;
            if (empty($dtendValue) === false) {
                return $this->formatDateValue(value: $dtendValue, allDay: $allDay);
            }
        }

        // Compute default DTEND from DTSTART.
        $date = new DateTime($dtstartValue);

        if ($allDay === true) {
            $date->add(new DateInterval('P1D'));
            return [$date->format('Ymd'), ['VALUE' => 'DATE']];
        }

        $date->add(new DateInterval('PT1H'));
        return [$date->format('Ymd\THis\Z'), ['VALUE' => 'DATE-TIME']];
    }//end buildDtend()

    /**
     * Interpolate a template string with object data
     *
     * Replaces {property} placeholders with values from object data.
     * Missing properties are replaced with empty strings.
     *
     * @param string $template   The template string with {property} placeholders
     * @param array  $objectData The object data array
     *
     * @return string The interpolated string
     */
    public function interpolateTemplate(string $template, array $objectData): string
    {
        // Match Mustache-style `{{ key }}` tokens, matching the convention
        // used by the notification dispatcher's interpolate() helper. The
        // previous single-brace `{key}` pattern silently corrupted any
        // template that used the standard `{{...}}` form: `{{title}}`
        // would render as `}` because the regex consumed the leading
        // `{{title}` greedily and left the trailing `}` behind.
        return preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}/',
            function ($matches) use ($objectData) {
                $key   = $matches[1];
                $value = $objectData[$key] ?? '';

                if (is_array($value) === true) {
                    return json_encode($value);
                }

                return (string) $value;
            },
            $template
        ) ?? $template;
    }//end interpolateTemplate()

    /**
     * Resolve the VEVENT STATUS from object data using status mapping
     *
     * @param array $objectData     The object data
     * @param array $calendarConfig The calendar configuration
     *
     * @return string The VEVENT STATUS value (CONFIRMED, CANCELLED, TENTATIVE)
     */
    private function resolveStatus(array $objectData, array $calendarConfig): string
    {
        if (empty($calendarConfig['statusMapping']) === true || empty($calendarConfig['statusField']) === true) {
            return 'CONFIRMED';
        }

        $statusValue = $objectData[$calendarConfig['statusField']] ?? null;

        if ($statusValue === null) {
            return 'CONFIRMED';
        }

        return $calendarConfig['statusMapping'][$statusValue] ?? 'CONFIRMED';
    }//end resolveStatus()
}//end class
