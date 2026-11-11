<?php

/**
 * OpenRegister FacetableFieldDiscovery
 *
 * The fields a caller may facet and search on, read from schema properties.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/faceting-configuration/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;


use Generator;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Search\Service\Search\PropertySearchProfile;
use OCA\OpenRegister\Service\PropertyRbacHandlerInterface;
use OCA\OpenRegister\Service\Rbac\AggregateVisibility;
use Psr\Log\LoggerInterface;

/**
 * Reads the pre-computed facet and search declarations of schema properties, withholding every
 * property the caller may not summarise.
 *
 * @spec openspec/specs/faceting-configuration/spec.md#requirement-facet-discovery-via-facetable-parameter
 */
final class FacetableFieldDiscovery {

	/**
	 * The metadata fields every query can facet on.
	 *
	 * @var array<string, array<string, string>>
	 */
	private const METADATA_FACETS = [
		'register' => ['type' => 'terms'],
		'schema' => ['type' => 'terms'],
		'created' => ['type' => 'date_histogram', 'interval' => 'month'],
		'updated' => ['type' => 'date_histogram', 'interval' => 'month'],
		'owner' => ['type' => 'terms'],
	];

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface          $logger       The logger.
	 * @param PropertyRbacHandlerInterface|null $propertyRbac Decides governed properties; absent, they are withheld.
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-facet-discovery-via-facetable-parameter
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly ?PropertyRbacHandlerInterface $propertyRbac,
	) {
	}//end __construct()

	/**
	 * The facetable and searchable fields of the schemas; entries that are not schemas are skipped.
	 *
	 * `searchable_fields` holds every property a list surface can search, with the match type it
	 * asked for and the control it wants rendered. It is separate from object_fields because a
	 * property can be searchable without being facetable.
	 *
	 * @param array<int, mixed> $schemas The schema entries.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-facet-discovery-via-facetable-parameter
	 */
	public function fieldsOf(array $schemas): array {
		$fields = [
			'@self' => self::METADATA_FACETS,
			'object_fields' => [],
			'non_aggregated_fields' => [],
			'searchable_fields' => [],
		];
		foreach (array_filter($schemas, fn ($schema): bool => $schema instanceof Schema) as $schema) {
			$fields = $this->collectFields(schema: $schema, fields: $fields);
		}

		return $fields;
	}//end fieldsOf()

	/**
	 * Add one schema's fields; a schema that cannot be read is logged, keeping what was collected.
	 *
	 * @param Schema               $schema The schema.
	 * @param array<string, mixed> $fields The fields so far.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-facet-discovery-via-facetable-parameter
	 */
	private function collectFields(Schema $schema, array $fields): array {
		try {
			$schemaId = $schema->getId();
			foreach ($this->summarisableProperties(schema: $schema) as $propertyKey => $property) {
				$facet = $this->describeProperty(property: $property);
				$fields['searchable_fields'][$propertyKey] = $facet['searchable'];
				$fields = $this->addField(fields: $fields, facet: $facet + ['field' => $propertyKey, 'schemaId' => $schemaId]);
			}
		} catch (\Exception $e) {
			$this->logFailure(schema: $schema, error: $e);
		}

		return $fields;
	}//end collectFields()

	/**
	 * The schema's properties that may be advertised, one at a time.
	 *
	 * Advertising a facetable field is the first half of a leak: naming a governed property
	 * tells a caller the field exists and invites them to ask for its buckets, so it is withheld
	 * here at the source. Encrypted properties are never facetable, even when a schema author
	 * also sets `facetable: true`: the magic-table value is ciphertext, or not a real column, so
	 * grouping by it would be meaningless at best and a ciphertext leak into option labels at worst.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return Generator<int|string, array<string, mixed>>
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-facet-discovery-via-facetable-parameter
	 */
	private function summarisableProperties(Schema $schema): Generator {
		foreach ($schema->getProperties() ?? [] as $propertyKey => $property) {
			$visibility = new AggregateVisibility(rbac: $this->propertyRbac, logger: $this->logger);
			if ($visibility->maySummarise(schema: $schema, property: (string)$propertyKey) === false
				|| ($property['x-openregister-encrypted'] ?? false) === true
			) {
				continue;
			}

			yield $propertyKey => $property;
		}
	}//end summarisableProperties()

	/**
	 * What a property declares about searching and faceting.
	 *
	 * @param array<string, mixed> $property The property.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-facet-discovery-via-facetable-parameter
	 */
	private function describeProperty(array $property): array {
		$searchable = (new PropertySearchProfile())->declaredBy(property: $property);
		$title = $property['title'] ?? null;
		return [
			'searchable' => $searchable + ['title' => $title],
			'facetConfig' => $this->normalizeConfig(facetable: ($property['facetable'] ?? false)),
			'facetType' => $this->facetTypeOf(property: $property),
			'title' => $title,
			'matchType' => $searchable['matchType'],
			'inputControl' => $searchable['inputControl'],
		];
	}//end describeProperty()

	/**
	 * File a described property under the aggregated or non-aggregated fields; one that is not facetable is left out.
	 *
	 * @param array<string, mixed> $fields The fields so far.
	 * @param array<string, mixed> $facet  The described property with its field and schema id.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
	 */
	private function addField(array $fields, array $facet): array {
		if ($facet['facetConfig'] === null) {
			return $fields;
		}

		$shared = ['facetConfig' => $facet['facetConfig'], 'title' => $facet['title']];
		$control = ['matchType' => $facet['matchType'], 'inputControl' => $facet['inputControl']];
		if ($facet['facetConfig']['aggregated'] === false) {
			$identity = ['field' => $facet['field'], 'schemaId' => $facet['schemaId'], 'facetType' => $facet['facetType']];
			$fields['non_aggregated_fields'][] = $identity + $shared + $control;
			return $fields;
		}

		$fields['object_fields'][$facet['field']] = ['type' => $facet['facetType'], 'title' => $facet['title']] + $shared + $control;
		return $fields;
	}//end addField()

	/**
	 * A facetable value as a standard configuration: `true` and a non-empty object give one, anything else null.
	 *
	 * @param mixed $facetable The facetable value of a schema property.
	 *
	 * @return array{aggregated: mixed, title: mixed, description: mixed, order: mixed}|null
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md
	 */
	private function normalizeConfig(mixed $facetable): ?array {
		if ($facetable === true) {
			return $this->standardConfig(settings: []);
		}

		if (is_array($facetable) === true && empty($facetable) === false) {
			return $this->standardConfig(settings: $facetable);
		}

		return null;
	}//end normalizeConfig()

	/**
	 * The standard configuration of the given settings; a facet is aggregated unless it says otherwise.
	 *
	 * @param array<string, mixed> $settings The declared settings.
	 *
	 * @return array{aggregated: mixed, title: mixed, description: mixed, order: mixed}
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md
	 */
	private function standardConfig(array $settings): array {
		return [
			'aggregated' => $settings['aggregated'] ?? true,
			'title' => $settings['title'] ?? null,
			'description' => $settings['description'] ?? null,
			'order' => $settings['order'] ?? null,
		];
	}//end standardConfig()

	/**
	 * The facet type of a property: date and date-time use a histogram, everything else terms.
	 *
	 * @param array<string, mixed> $property The property.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-facet-type-auto-detection-from-property-definitions
	 */
	private function facetTypeOf(array $property): string {
		$type = $property['type'] ?? 'string';
		$format = $property['format'] ?? null;
		if ($format === 'date' || $format === 'date-time' || $type === 'date') {
			return 'date_histogram';
		}

		return 'terms';
	}//end facetTypeOf()

	/**
	 * Log a schema whose properties could not be read.
	 *
	 * @param Schema     $schema The schema.
	 * @param \Exception $error  What went wrong.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-facet-discovery-via-facetable-parameter
	 */
	private function logFailure(Schema $schema, \Exception $error): void {
		$schemaId = 'unknown';
		if (method_exists($schema, 'getId') === true) {
			$schemaId = $schema->getId();
		}

		$this->logger->error(
			message: '[FacetHandler] Failed to get facetable fields from schema properties',
			context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $error->getMessage(), 'schemaId' => $schemaId]
		);
	}//end logFailure()
}//end class
