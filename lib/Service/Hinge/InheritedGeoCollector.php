<?php

/**
 * InheritedGeoCollector — map features carried up from what a record points at.
 *
 * A case is about an address, and the address is where the point lives. Rather
 * than copying the coordinates onto the case, the case declares which of its
 * references carry geography and the features are collected at read. Each one
 * names the relation it arrived through, because a map with a pin and no
 * explanation is a map somebody argues with (D-5). A feature the record holds
 * itself outranks an inherited one, so a corrected location is never overwritten
 * by the registry it came from.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Hinge
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Hinge;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Collects a record's own and inherited map features, each with its provenance.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Hinge
 */
class InheritedGeoCollector {

	/**
	 * GeoJSON geometry types a feature may carry.
	 *
	 * @var array<int, string>
	 */
	private const GEOMETRY_TYPES = [
		'Point',
		'MultiPoint',
		'LineString',
		'MultiLineString',
		'Polygon',
		'MultiPolygon',
		'GeometryCollection',
	];

	/**
	 * Wire the object lookup the collector reads referenced records through.
	 *
	 * @param MagicMapper     $magicMapper Object storage.
	 * @param LoggerInterface $logger      PSR logger for unreadable references.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/objects-as-the-hinge-between-cases/specs/linked-entity-types/spec.md
	 */
	public function __construct(
		private readonly MagicMapper $magicMapper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Collect one record's map features, its own first and its inherited after.
	 *
	 * A schema declaring no inheritance gets its own features and nothing else,
	 * which is what it gets today.
	 *
	 * @param ObjectEntity $object The record whose features are read.
	 * @param Schema|null  $schema Its schema, holding the inheritance declaration.
	 * @param bool         $_rbac  Apply the caller's access to referenced records.
	 *
	 * @return array{type: string, features: array<int, array>} A GeoJSON FeatureCollection.
	 *
	 * @spec openspec/changes/objects-as-the-hinge-between-cases/specs/linked-entity-types/spec.md
	 */
	public function collect(ObjectEntity $object, ?Schema $schema, bool $_rbac = true): array {
		$own = $this->featuresOf(source: $object->getGeo());
		foreach ($own as $index => $feature) {
			$own[$index]['properties']['_source'] = 'own';
			$own[$index]['properties']['_superseded'] = false;
		}

		$inherited = [];
		if ($schema !== null) {
			$inherited = $this->inheritedFeatures(
				object: $object,
				sources: $schema->getGeoInheritance(),
				_rbac: $_rbac
			);
		}

		// A feature the record holds itself outranks an inherited one for the
		// same purpose. The inherited one stays in the answer and is marked,
		// because "where did the other pin go" is a question a caseworker asks
		// out loud, and silence is the worst answer available.
		$ownPurposes = array_column(array_column($own, 'properties'), '_purpose');
		foreach ($inherited as $index => $feature) {
			$superseded = in_array($feature['properties']['_purpose'], $ownPurposes, true);
			$inherited[$index]['properties']['_superseded'] = $superseded;
		}

		return [
			'type' => 'FeatureCollection',
			'features' => array_merge($own, $inherited),
		];
	}//end collect()

	/**
	 * Collect the features of every declared reference.
	 *
	 * @param ObjectEntity $object  The record doing the inheriting.
	 * @param array        $sources The declared reference properties.
	 * @param bool         $_rbac   Apply the caller's access to referenced records.
	 *
	 * @return array<int, array> The inherited features, each naming its relation.
	 */
	private function inheritedFeatures(ObjectEntity $object, array $sources, bool $_rbac): array {
		if ($sources === []) {
			return [];
		}

		$data = ($object->getObject() ?? []);
		$features = [];

		foreach ($sources as $source) {
			$through = $source['through'];
			foreach ($this->identifiers(value: ($data[$through] ?? null)) as $identifier) {
				$referenced = $this->read(identifier: $identifier, _rbac: $_rbac);
				if ($referenced === null) {
					continue;
				}

				foreach ($this->featuresOf(source: $referenced->getGeo()) as $feature) {
					$feature['properties']['_source'] = 'inherited';
					$feature['properties']['_through'] = $through;
					$feature['properties']['_fromObject'] = (string)$referenced->getUuid();
					if (isset($source['label']) === true) {
						$feature['properties']['_relationLabel'] = $source['label'];
					}

					$features[] = $feature;
				}
			}
		}//end foreach

		return $features;
	}//end inheritedFeatures()

	/**
	 * Read a referenced record, or nothing when the caller may not.
	 *
	 * The access filter is applied by the read itself here, not afterwards: an
	 * inherited feature is the referenced record's data, so a record the caller
	 * cannot read contributes nothing rather than a withheld placeholder.
	 *
	 * @param string $identifier The referenced record's identifier.
	 * @param bool   $_rbac      Apply the caller's access.
	 *
	 * @return ObjectEntity|null The referenced record, or null.
	 */
	private function read(string $identifier, bool $_rbac): ?ObjectEntity {
		try {
			return $this->magicMapper->find(
				identifier: $identifier,
				_rbac: $_rbac,
				_multitenancy: $_rbac
			);
		} catch (Throwable $e) {
			$this->logger->debug(
				sprintf('[InheritedGeoCollector] Referenced object "%s" contributed no features: %s', $identifier, $e->getMessage())
			);
			return null;
		}
	}//end read()

	/**
	 * The identifiers a reference property holds, one or many.
	 *
	 * @param mixed $value The reference property's value.
	 *
	 * @return array<int, string> The identifiers.
	 */
	private function identifiers(mixed $value): array {
		if (is_string($value) === true && $value !== '') {
			$segments = explode('/', rtrim($value, '/'));
			return [end($segments)];
		}

		if (is_array($value) === false) {
			return [];
		}

		$single = ($value['id'] ?? ($value['uuid'] ?? null));
		if (is_string($single) === true && $single !== '') {
			return [$single];
		}

		$identifiers = [];
		foreach ($value as $entry) {
			$identifiers = array_merge($identifiers, $this->identifiers(value: $entry));
		}

		return $identifiers;
	}//end identifiers()

	/**
	 * Read whatever shape `@self.geo` holds as a list of GeoJSON features.
	 *
	 * A geo block in the wild is a bare geometry, one Feature, a
	 * FeatureCollection, or a list of any of those. All four are accepted,
	 * because rejecting three of them would make the inheritance look broken on
	 * data that renders correctly on a map today.
	 *
	 * @param mixed $source The `@self.geo` value.
	 *
	 * @return array<int, array> The features, each with a `_purpose`.
	 */
	private function featuresOf(mixed $source): array {
		if (is_array($source) === false || $source === []) {
			return [];
		}

		$type = ($source['type'] ?? null);

		if (is_string($type) === true && in_array($type, self::GEOMETRY_TYPES, true) === true) {
			return [$this->feature(geometry: $source, properties: [])];
		}

		if ($type === 'Feature') {
			$geometry = ($source['geometry'] ?? null);
			if (is_array($geometry) === false) {
				return [];
			}

			$properties = ($source['properties'] ?? []);
			if (is_array($properties) === false) {
				$properties = [];
			}

			return [$this->feature(geometry: $geometry, properties: $properties)];
		}

		$candidates = ($source['features'] ?? null);
		if (is_array($candidates) === false) {
			$candidates = $source;
		}

		if (is_array($candidates) === false || array_is_list($candidates) === false) {
			return [];
		}

		$features = [];
		foreach ($candidates as $candidate) {
			$features = array_merge($features, $this->featuresOf(source: $candidate));
		}

		return $features;
	}//end featuresOf()

	/**
	 * Shape one feature and stamp the purpose precedence is decided on.
	 *
	 * A feature that names its own `purpose` keeps it; otherwise the geometry
	 * type stands in, so two points compete and a point and a polygon do not.
	 *
	 * @param array $geometry   The GeoJSON geometry.
	 * @param array $properties The feature's properties.
	 *
	 * @return array The feature.
	 */
	private function feature(array $geometry, array $properties): array {
		$purpose = ($properties['purpose'] ?? null);
		if (is_string($purpose) === false || $purpose === '') {
			$purpose = (string)($geometry['type'] ?? 'Geometry');
		}

		$properties['_purpose'] = $purpose;

		return [
			'type' => 'Feature',
			'geometry' => $geometry,
			'properties' => $properties,
		];
	}//end feature()
}//end class
