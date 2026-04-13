<?php

/**
 * LinkedEntityPropertyHandler
 *
 * This file is part of the OpenRegister app for Nextcloud.
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link     https://github.com/ConductionNL/openregister
 */

namespace OCA\OpenRegister\Service\Object\SaveObject;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use Psr\Log\LoggerInterface;

/**
 * Extracts Nc* property values and populates corresponding _ metadata columns.
 *
 * When an object has properties with Nc* types (NcMail, NcContact, etc.), this handler
 * extracts the `id` field from each reference envelope and appends it to the corresponding
 * metadata column (_mail, _contacts, etc.). Ad-hoc links (created via sidebar) are preserved.
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link     https://github.com/ConductionNL/openregister
 */
class LinkedEntityPropertyHandler
{
    /**
     * Map of Nc* type names to their metadata column setter methods.
     */
    private const NC_TYPE_TO_SETTER = [
        'NcMail'          => 'setMail',
        'NcContact'       => 'setContacts',
        'NcNote'          => 'setNotes',
        'NcTodo'          => 'setTodos',
        'NcCalendarEvent' => 'setCalendar',
        'NcTalk'          => 'setTalk',
        'NcDeck'          => 'setDeck',
        'NcFile'          => 'setFiles',
    ];

    /**
     * Map of Nc* type names to their metadata column getter methods.
     */
    private const NC_TYPE_TO_GETTER = [
        'NcMail'          => 'getMail',
        'NcContact'       => 'getContacts',
        'NcNote'          => 'getNotes',
        'NcTodo'          => 'getTodos',
        'NcCalendarEvent' => 'getCalendar',
        'NcTalk'          => 'getTalk',
        'NcDeck'          => 'getDeck',
        'NcFile'          => 'getFiles',
    ];

    /**
     * Constructor for LinkedEntityPropertyHandler.
     *
     * @param LoggerInterface $logger Logger for logging operations
     */
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Extract Nc* property references and populate metadata columns.
     *
     * Scans the schema properties for Nc* types, extracts the `id` from each
     * reference envelope in the object data, and merges them into the corresponding
     * metadata column. Existing ad-hoc links (from sidebar) are preserved.
     *
     * @param ObjectEntity $object The object entity to update
     * @param Schema       $schema The schema definition
     * @param array        $data   The object data being saved
     *
     * @return ObjectEntity The updated object entity
     */
    public function extractAndPopulate(ObjectEntity $object, Schema $schema, array $data): ObjectEntity
    {
        $properties = $schema->getProperties();
        if (is_array($properties) === false || empty($properties) === true) {
            return $object;
        }

        // Collect extracted IDs grouped by Nc* type.
        $extractedIds = [];

        foreach ($properties as $propertyName => $propertyConfig) {
            if (is_array($propertyConfig) === false) {
                continue;
            }

            $this->extractFromProperty(
                $propertyName,
                $propertyConfig,
                $data,
                $extractedIds
            );
        }

        // Merge extracted IDs into metadata columns, preserving ad-hoc links.
        foreach ($extractedIds as $ncType => $ids) {
            $this->mergeIntoMetadataColumn($object, $ncType, $ids);
        }

        return $object;
    }//end extractAndPopulate()

    /**
     * Extract IDs from a single property based on its Nc* type.
     *
     * @param string $propertyName   The property name
     * @param array  $propertyConfig The property configuration from the schema
     * @param array  $data           The object data
     * @param array  $extractedIds   Reference to the collected IDs (grouped by type)
     *
     * @return void
     */
    private function extractFromProperty(
        string $propertyName,
        array $propertyConfig,
        array $data,
        array &$extractedIds
    ): void {
        $type = $propertyConfig['type'] ?? null;

        // Direct Nc* type property.
        if ($type !== null && isset(self::NC_TYPE_TO_SETTER[$type]) === true) {
            $value = $data[$propertyName] ?? null;
            $id    = $this->extractIdFromEnvelope($value);
            if ($id !== null) {
                $extractedIds[$type][] = $id;
            }

            return;
        }

        // Array of Nc* type items.
        if ($type === 'array') {
            $itemsType = $propertyConfig['items']['type'] ?? null;
            if ($itemsType !== null && isset(self::NC_TYPE_TO_SETTER[$itemsType]) === true) {
                $values = $data[$propertyName] ?? [];
                if (is_array($values) === true) {
                    foreach ($values as $value) {
                        $id = $this->extractIdFromEnvelope($value);
                        if ($id !== null) {
                            $extractedIds[$itemsType][] = $id;
                        }
                    }
                }
            }
        }
    }//end extractFromProperty()

    /**
     * Extract the `id` field from a reference envelope.
     *
     * Accepts both full envelope format `{ "type": "NcMail", "id": "1/6" }`
     * and plain string IDs.
     *
     * @param mixed $value The property value
     *
     * @return string|null The extracted ID or null
     */
    private function extractIdFromEnvelope(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        // Full envelope: { "type": "NcMail", "id": "1/6", "label": "..." }
        if (is_array($value) === true && isset($value['id']) === true) {
            return (string) $value['id'];
        }

        // Plain string ID (for simple references).
        if (is_string($value) === true && $value !== '') {
            return $value;
        }

        return null;
    }//end extractIdFromEnvelope()

    /**
     * Merge extracted IDs into the object's metadata column, preserving existing ad-hoc links.
     *
     * @param ObjectEntity $object The object entity
     * @param string       $ncType The Nc* type name
     * @param array        $newIds The newly extracted IDs
     *
     * @return void
     */
    private function mergeIntoMetadataColumn(ObjectEntity $object, string $ncType, array $newIds): void
    {
        $getter = self::NC_TYPE_TO_GETTER[$ncType] ?? null;
        $setter = self::NC_TYPE_TO_SETTER[$ncType] ?? null;

        if ($getter === null || $setter === null) {
            return;
        }

        // Get existing IDs (includes ad-hoc links from sidebar).
        $existingIds = $object->$getter() ?? [];

        // Merge and deduplicate.
        $mergedIds = array_values(array_unique(array_merge($existingIds, $newIds)));

        $object->$setter($mergedIds);

        $this->logger->debug(
            '[LinkedEntityPropertyHandler] Merged IDs into metadata column',
            [
                'ncType'   => $ncType,
                'newIds'   => $newIds,
                'existing' => count($existingIds),
                'merged'   => count($mergedIds),
            ]
        );
    }//end mergeIntoMetadataColumn()
}//end class
