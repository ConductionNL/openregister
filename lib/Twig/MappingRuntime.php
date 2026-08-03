<?php

/**
 * OpenRegister Mapping Runtime
 *
 * Twig runtime extension providing mapping functions and filters
 * for use within Twig mapping templates.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Twig
 * @package  OCA\OpenRegister\Twig
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Twig;

use OCA\OpenRegister\Db\Mapping;
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Service\MappingService;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV4;
use Twig\Extension\RuntimeExtensionInterface;

/**
 * MappingRuntime provides Twig functions and filters for mapping templates.
 *
 * This runtime is loaded by MappingRuntimeLoader and provides:
 * - executeMapping(): Execute a sub-mapping within a template
 * - generateUuid(): Generate a UUID v4
 * - b64enc/b64dec: Base64 encoding/decoding
 * - json_decode: JSON to array conversion
 * - zgwEnum/zgwEnumReverse: ZGW value mapping for enum translation
 * - zgwExtractUuid: Extract UUID from a ZGW URL reference
 *
 * @category Twig
 * @package  OCA\OpenRegister\Twig
 *
 * A Twig runtime's public methods ARE the vocabulary mapping templates may call,
 * so their count is the size of that vocabulary rather than a design choice.
 * Splitting it would mean two runtimes and a rule about which functions live
 * where — a distinction template authors would have to know and could not see.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
 * @SuppressWarnings(PHPMD.StaticAccess)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class MappingRuntime implements RuntimeExtensionInterface
{
    /**
     * MappingRuntime constructor
     *
     * @param MappingService $mappingService The mapping service for executing mappings
     * @param MappingMapper  $mappingMapper  The mapping mapper for finding mappings
     *
     * @spec openspec/specs/object-lifecycle/spec.md
     */
    public function __construct(
        private readonly MappingService $mappingService,
        private readonly MappingMapper $mappingMapper,
    ) {
    }//end __construct()

    /**
     * Decode a JSON string, under the name OpenConnector's templates call.
     *
     * The snake_case name is deliberate and cannot be corrected: it is the
     * identifier stored mapping templates already contain. `jsonDecode` below is
     * the same function under OpenRegister's own spelling — both exist because a
     * mapping is authored data, so renaming what it calls breaks it at
     * evaluation with nothing at author time to warn you.
     *
     * @param string $input The JSON to decode.
     *
     * @return array The decoded structure.
     *
     * @SuppressWarnings(PHPMD.CamelCaseMethodName)
     *
     * @spec openspec/changes/flow-parity-mapping-and-webhooks/specs/flow-mapping/spec.md
     */
    public function json_decode(string $input): array
    {
        return (array) json_decode(json: $input, associative: true);

    }//end json_decode()

    /**
     * URL-friendly slug from arbitrary text.
     *
     * Ported verbatim from OpenConnector's runtime when mapping consolidated
     * into OpenRegister. Byte-for-byte behaviour matters here: slugs authored by
     * existing mappings are persisted as object identifiers, so any change to
     * the transformation silently orphans previously-written objects.
     *
     * @param string $text The text to slugify.
     *
     * @return string The slug.
     *
     * @spec openspec/changes/flow-parity-mapping-and-webhooks/specs/flow-mapping/spec.md
     */
    public function createSlug(string $text): string
    {
        // Convert to lowercase.
        $slug = strtolower($text);

        // Replace spaces and underscores with hyphens.
        $slug = str_replace([' ', '_'], '-', $slug);

        // Remove all characters that are not a-z, 0-9, or hyphen.
        $slug = preg_replace('/[^a-z0-9\-]/', '', $slug);

        // Replace multiple consecutive hyphens with single hyphen.
        $slug = preg_replace('/-+/', '-', $slug);

        // Trim hyphens from start and end.
        $slug = trim($slug, '-');

        return $slug;

    }//end createSlug()

    /**
     * Encodes a string to base64.
     *
     * @param string $input The unencoded input.
     *
     * @return string The encoded output.
     *
     * @spec openspec/specs/object-lifecycle/spec.md
     */
    public function b64enc(string $input): string
    {
        return base64_encode(string: $input);
    }//end b64enc()

    /**
     * Decodes a base64 encoded string.
     *
     * @param string $input The encoded input.
     *
     * @return string The decoded output.
     *
     * @spec openspec/specs/object-lifecycle/spec.md
     */
    public function b64dec(string $input): string
    {
        return base64_decode(string: $input);
    }//end b64dec()

    /**
     * Decodes a JSON string to an array.
     *
     * @param string $input The JSON string input.
     *
     * @return array The decoded array.
     *
     * @psalm-suppress MixedReturnStatement
     *
     * @spec openspec/specs/object-lifecycle/spec.md
     */
    public function jsonDecode(string $input): array
    {
        return json_decode(json: $input, associative: true) ?? [];
    }//end jsonDecode()

    /**
     * Execute a mapping with given parameters.
     *
     * Accepts a Mapping object, array (hydrated into Mapping), or string/int reference.
     *
     * @param Mapping|array|string|int $mapping The mapping to execute
     * @param array                    $input   The input to run the mapping on
     * @param bool                     $list    Whether the mapping runs on multiple instances
     *
     * @return array The mapped output
     *
     * @spec openspec/specs/object-lifecycle/spec.md
     */
    public function executeMapping(Mapping|array|string|int $mapping, array $input, bool $list=false): array
    {
        if (is_array($mapping) === true) {
            $mappingObject = new Mapping();
            $mappingObject->hydrate($mapping);
            $mapping = $mappingObject;
        } else if (is_string($mapping) === true || is_int($mapping) === true) {
            if (is_string($mapping) !== true || str_starts_with($mapping, 'http') !== true) {
                $mapping = $this->mappingMapper->find($mapping, true);
            }

            if (is_string($mapping) === true && str_starts_with($mapping, 'http') === true) {
                $results = $this->mappingMapper->findByRef($mapping);
                $mapping = $results[0];
            }
        }

        return $this->mappingService->executeMapping(
            mapping: $mapping,
            input: $input,
            list: $list
        );
    }//end executeMapping()

    /**
     * Generate a UUID v4.
     *
     * @return UuidV4 A new UUID v4 instance
     *
     * @spec openspec/specs/object-lifecycle/spec.md
     */
    public function generateUuid(): UuidV4
    {
        return Uuid::v4();
    }//end generateUuid()

    /**
     * Translates an enum value using the value mapping table (outbound: English to Dutch).
     *
     * Usage in Twig: {{ value | zgw_enum('fieldName', valueMappings) }}
     *
     * @param string $value         The value to translate
     * @param string $fieldName     The field name key in the value mapping table
     * @param array  $valueMappings The full value mapping configuration
     *
     * @return string The translated value, or original if no mapping found
     *
     * @spec openspec/specs/object-lifecycle/spec.md
     */
    public function zgwEnum(string $value, string $fieldName, array $valueMappings=[]): string
    {
        if (isset($valueMappings[$fieldName][$value]) === true) {
            return $valueMappings[$fieldName][$value];
        }

        return $value;
    }//end zgwEnum()

    /**
     * Reverse enum lookup for inbound mapping (Dutch to English).
     *
     * Usage in Twig: {{ value | zgw_enum_reverse('fieldName', valueMappings) }}
     *
     * @param string $value         The Dutch value to translate back
     * @param string $fieldName     The field name key in the value mapping table
     * @param array  $valueMappings The full value mapping configuration
     *
     * @return string The English value, or original if no mapping found
     *
     * @spec openspec/specs/object-lifecycle/spec.md
     */
    public function zgwEnumReverse(string $value, string $fieldName, array $valueMappings=[]): string
    {
        $flipped = array_flip($valueMappings[$fieldName] ?? []);

        return $flipped[$value] ?? $value;
    }//end zgwEnumReverse()

    /**
     * Extracts a UUID from a ZGW URL reference.
     *
     * Given a URL like "https://example.com/api/zgw/catalogi/v1/zaaktypen/uuid-123",
     * returns "uuid-123".
     *
     * Usage in Twig: {{ url | zgw_extract_uuid }}
     *
     * @param string $url The ZGW URL to extract the UUID from
     *
     * @return string The extracted UUID
     *
     * @spec openspec/specs/object-lifecycle/spec.md
     */
    public function zgwExtractUuid(?string $url=null): string
    {
        if ($url === null || $url === '') {
            return '';
        }

        $parts = explode('/', rtrim($url, '/'));

        return end($parts);
    }//end zgwExtractUuid()
}//end class
