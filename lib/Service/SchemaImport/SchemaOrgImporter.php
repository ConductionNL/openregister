<?php

/**
 * OpenRegister Schema Import — SchemaOrgImporter.
 *
 * Maps a Schema.org type (resolved from a bundled, versioned snapshot) into an
 * OpenRegister register schema: properties derived from the type's declared
 * properties, Schema.org datatypes mapped to JSON Schema types/formats, the
 * `configuration.jsonld` vocabulary block pre-filled (so json-ld-output emits
 * Schema.org-conformant objects with zero manual mapping), and a
 * `configuration.importSource` provenance block recording the baseline.
 *
 * Pure mapping over the injected {@see SchemaOrgSnapshot} — no Nextcloud or DB
 * dependencies — so the full mapping is unit-testable.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\SchemaImport
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\SchemaImport;

use OCA\OpenRegister\Exception\SchemaImportException;

/**
 * Imports Schema.org types as register schemas.
 *
 * @spec openspec/changes/schema-import-standards/specs/schema-import/spec.md
 */
class SchemaOrgImporter implements SchemaDialectImporter
{

    /**
     * The Schema.org `@vocab` base IRI written into the jsonld block.
     *
     * @var string
     */
    private const VOCAB = 'https://schema.org/';

    /**
     * Permissiveness rank for collapsing a multi-type range to one JSON type.
     * Higher = more permissive; string wins over everything.
     *
     * @var array<string, int>
     */
    private const PERMISSIVENESS = [
        'string'  => 5,
        'number'  => 4,
        'integer' => 3,
        'boolean' => 2,
    ];

    /**
     * Constructor.
     *
     * @param SchemaOrgSnapshot $snapshot The indexed Schema.org vocabulary snapshot.
     */
    public function __construct(private readonly SchemaOrgSnapshot $snapshot)
    {
    }//end __construct()

    /**
     * {@inheritDoc}
     *
     * @spec openspec/changes/schema-import-standards/specs/schema-import/spec.md
     *
     * @return string The dialect key.
     */
    public function dialect(): string
    {
        return DialectDetector::DIALECT_SCHEMA_ORG;
    }//end dialect()

    /**
     * {@inheritDoc}
     *
     * @spec openspec/changes/schema-import-standards/specs/schema-import/spec.md
     *
     * @return string The snapshot version.
     */
    public function snapshotVersion(): string
    {
        return $this->snapshot->version();
    }//end snapshotVersion()

    /**
     * {@inheritDoc}
     *
     * @param string $query The search term.
     *
     * @return array<int, array<string, mixed>> Candidate type records.
     *
     * @spec openspec/changes/schema-import-standards/specs/schema-import/spec.md
     */
    public function discover(string $query): array
    {
        $version = $this->snapshot->version();
        $results = [];
        foreach ($this->snapshot->searchClasses($query) as $class) {
            $parent = null;
            if (is_string($class['parent']) === true && $class['parent'] !== '') {
                $parent = $this->snapshot->bareName($class['parent']);
            }

            $results[] = [
                'id'              => $class['iri'],
                'label'           => $class['label'],
                'description'     => $class['comment'],
                'parent'          => $parent,
                'snapshotVersion' => $version,
            ];
        }

        return $results;
    }//end discover()

    /**
     * {@inheritDoc}
     *
     * @param string        $reference The type IRI or bare name.
     * @param ImportOptions $options   Import options.
     *
     * @return ImportedSchema The mapped schema.
     *
     * @throws SchemaImportException When the type is unknown to the snapshot.
     *
     * @spec openspec/changes/schema-import-standards/specs/schema-import/spec.md
     */
    public function import(string $reference, ImportOptions $options): ImportedSchema
    {
        $class = $this->snapshot->findClass($reference);
        if ($class === null) {
            throw SchemaImportException::unknownReference($reference, $this->dialect());
        }

        $className = $this->snapshot->bareName($class['iri']);

        if ($options->includeAncestors === true) {
            $sourceProperties = $this->snapshot->propertiesWithAncestors($className);
        } else {
            $sourceProperties = $this->snapshot->directPropertiesOf($className);
        }

        // Apply an explicit property subset, tracking unknown requests.
        $unknownRequested = [];
        if ($options->hasSubset() === true) {
            $selected = [];
            foreach ($options->propertySubset as $name) {
                if (isset($sourceProperties[$name]) === true) {
                    $selected[$name] = $sourceProperties[$name];
                } else {
                    $unknownRequested[] = $name;
                }
            }

            $sourceProperties = $selected;
        }

        $properties = [];
        $termMap    = [];
        foreach ($sourceProperties as $name => $property) {
            $properties[$name] = $this->mapProperty(property: $property);
            $termMap[$name]    = $property['iri'];
        }

        $jsonld = [
            '@vocab'     => self::VOCAB,
            'type'       => $class['iri'],
            'properties' => $termMap,
        ];

        $importSource = [
            'dialect'         => $this->dialect(),
            'reference'       => $class['iri'],
            'snapshotVersion' => $this->snapshot->version(),
            'importedAt'      => gmdate('c'),
            'baseline'        => $properties,
        ];

        return new ImportedSchema(
            title: $class['label'],
            description: $class['comment'],
            properties: $properties,
            jsonld: $jsonld,
            importSource: $importSource,
            unknownRequested: $unknownRequested
        );
    }//end import()

    /**
     * Map one Schema.org property record to a JSON Schema property definition.
     *
     * @param array<string, mixed> $property The snapshot property record.
     *
     * @return array<string, mixed> The JSON Schema property definition.
     */
    private function mapProperty(array $property): array
    {
        $definition = $this->mapRanges(ranges: $property['ranges']);
        $comment    = (string) ($property['comment'] ?? '');
        if ($comment !== '') {
            $definition['description'] = $comment;
        }

        return $definition;
    }//end mapProperty()

    /**
     * Map a Schema.org range list to a JSON Schema type/format.
     *
     * Datatype ranges map per the normative table; object-typed ranges (a
     * class) become a `string` with `format: uri` reference (never a recursive
     * import); a multi-type range collapses to the most permissive member.
     *
     * @param array<int, string> $ranges The rangeIncludes bare names.
     *
     * @return array<string, mixed> The JSON Schema type fragment.
     */
    private function mapRanges(array $ranges): array
    {
        $mapped = [];
        foreach ($ranges as $range) {
            $mapped[] = $this->mapSingleRange(range: $range);
        }

        if ($mapped === []) {
            // No declared range: default to a free-text string.
            return ['type' => 'string'];
        }

        if (count($mapped) === 1) {
            return $mapped[0];
        }

        return $this->collapseMostPermissive(fragments: $mapped);
    }//end mapRanges()

    /**
     * Map a single Schema.org range (datatype or class) to a JSON fragment.
     *
     * @param string $range The bare range name.
     *
     * @return array<string, mixed> The JSON Schema fragment.
     */
    private function mapSingleRange(string $range): array
    {
        switch ($range) {
            case 'Text':
            case 'PronounceableText':
            case 'CssSelectorType':
            case 'XPathType':
                return ['type' => 'string'];
            case 'Number':
            case 'Float':
                return ['type' => 'number'];
            case 'Integer':
                return ['type' => 'integer'];
            case 'Boolean':
                return ['type' => 'boolean'];
            case 'Date':
                return ['type' => 'string', 'format' => 'date'];
            case 'DateTime':
                return ['type' => 'string', 'format' => 'date-time'];
            case 'Time':
                return ['type' => 'string', 'format' => 'time'];
            case 'URL':
                return ['type' => 'string', 'format' => 'uri'];
            default:
                // Object-typed range: a reference to another type, never a
                // recursive import. Carry the target IRI for traceability.
                return [
                    'type'        => 'string',
                    'format'      => 'uri',
                    'description' => 'Reference to a '.$range.' ('.$this->snapshot->classIri($range).').',
                ];
        }//end switch
    }//end mapSingleRange()

    /**
     * Collapse a list of mapped fragments to the single most permissive one.
     *
     * @param array<int, array<string, mixed>> $fragments The mapped fragments.
     *
     * @return array<string, mixed> The most permissive fragment.
     */
    private function collapseMostPermissive(array $fragments): array
    {
        $best     = $fragments[0];
        $bestRank = (self::PERMISSIVENESS[$best['type']] ?? 0);
        foreach ($fragments as $fragment) {
            $rank = (self::PERMISSIVENESS[$fragment['type']] ?? 0);
            if ($rank > $bestRank) {
                $best     = $fragment;
                $bestRank = $rank;
            }
        }

        // When the most permissive choice is a plain string, drop any
        // format/description carried from a more specific sibling.
        if ($best['type'] === 'string' && ($best['format'] ?? null) === 'uri') {
            return ['type' => 'string'];
        }

        return ['type' => $best['type']];
    }//end collapseMostPermissive()
}//end class
