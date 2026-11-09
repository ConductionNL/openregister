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

use OCA\OpenRegister\Db\MagicMapper\MagicObjectFinderInterface;
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
class InheritedGeoCollector implements InheritedGeoCollectorInterface {

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
	 * @param MagicObjectFinderInterface     $magicMapper Object storage.
	 * @param LoggerInterface $logger      PSR logger for unreadable references.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function __construct(
		private readonly MagicObjectFinderInterface $magicMapper,
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
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) `_rbac` is the established
	 *   access-flag convention across the object layer (MagicSearchHandler,
	 *   PermissionHandlerInterface, ObjectService all take it); splitting this one method
	 *   in two would make it the exception rather than the rule.
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
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
			$features = array_merge(
				$features,
				$this->featuresThrough(source: $source, value: ($data[$source['through']] ?? null), _rbac: $_rbac)
			);
		}

		return $features;
	}//end inheritedFeatures()

	/**
	 * Collect the features of every record one reference property points at.
	 *
	 * @param array $source The declared reference property, with its optional label.
	 * @param mixed $value  The reference property's value on the inheriting record.
	 * @param bool  $_rbac  Apply the caller's access to referenced records.
	 *
	 * @return array<int, array> The inherited features through this property.
	 */
	private function featuresThrough(array $source, mixed $value, bool $_rbac): array {
		$features = [];
		foreach ($this->identifiers(value: $value) as $identifier) {
			$referenced = $this->read(identifier: $identifier, _rbac: $_rbac);
			if ($referenced === null) {
				continue;
			}

			foreach ($this->featuresOf(source: $referenced->getGeo()) as $feature) {
				$features[] = $this->stampInherited(feature: $feature, source: $source, referenced: $referenced);
			}
		}

		return $features;
	}//end featuresThrough()

	/**
	 * Stamp an inherited feature with where it came from.
	 *
	 * @param array        $feature    The referenced record's feature.
	 * @param array        $source     The declared reference property, with its optional label.
	 * @param ObjectEntity $referenced The record the feature was read from.
	 *
	 * @return array The feature, carrying its provenance.
	 */
	private function stampInherited(array $feature, array $source, ObjectEntity $referenced): array {
		$feature['properties']['_source'] = 'inherited';
		$feature['properties']['_through'] = $source['through'];
		$feature['properties']['_fromObject'] = (string)$referenced->getUuid();
		if (isset($source['label']) === true) {
			$feature['properties']['_relationLabel'] = $source['label'];
		}

		return $feature;
	}//end stampInherited()

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
		if (is_string($value) === true) {
			return $this->stringIdentifiers(value: $value);
		}

		if (is_array($value) === false) {
			return [];
		}

		$single = ($value['id'] ?? ($value['uuid'] ?? null));
		if (is_string($single) === true && $single !== '') {
			return [$single];
		}

		return $this->listedIdentifiers(values: $value);
	}//end identifiers()

	/**
	 * The identifier a reference held as a string or URI holds: its last path segment.
	 *
	 * @param string $value The reference.
	 *
	 * @return array<int, string> The one identifier, or nothing for an empty string.
	 */
	private function stringIdentifiers(string $value): array {
		if ($value === '') {
			return [];
		}

		$segments = explode('/', rtrim($value, '/'));
		return [end($segments)];
	}//end stringIdentifiers()

	/**
	 * The identifiers held by every entry of a list of references.
	 *
	 * @param array $values The entries.
	 *
	 * @return array<int, string> The identifiers, in entry order.
	 */
	private function listedIdentifiers(array $values): array {
		$identifiers = [];
		foreach ($values as $entry) {
			$identifiers = array_merge($identifiers, $this->identifiers(value: $entry));
		}

		return $identifiers;
	}//end listedIdentifiers()

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

		if ($this->isGeometryType(type: $type) === true) {
			return [$this->feature(geometry: $source, properties: [])];
		}

		if ($type === 'Feature') {
			return $this->featureOf(source: $source);
		}

		return $this->collectionFeatures(source: $source);
	}//end featuresOf()

	/**
	 * Whether a `type` member names a GeoJSON geometry.
	 *
	 * @param mixed $type The `type` member.
	 *
	 * @return bool True for a geometry type.
	 */
	private function isGeometryType(mixed $type): bool {
		return is_string($type) === true && in_array($type, self::GEOMETRY_TYPES, true) === true;
	}//end isGeometryType()

	/**
	 * Read a FeatureCollection, or a bare list, as the features of its members.
	 *
	 * @param array $source The FeatureCollection or list.
	 *
	 * @return array<int, array> The members' features, or nothing for any other shape.
	 */
	private function collectionFeatures(array $source): array {
		$candidates = ($source['features'] ?? null);
		if (is_array($candidates) === false) {
			$candidates = $source;
		}

		if (array_is_list($candidates) === false) {
			return [];
		}

		$features = [];
		foreach ($candidates as $candidate) {
			$features = array_merge($features, $this->featuresOf(source: $candidate));
		}

		return $features;
	}//end collectionFeatures()

	/**
	 * Read one GeoJSON Feature, keeping the properties it already carries.
	 *
	 * A Feature without a geometry is not a pin on a map, so it contributes
	 * nothing rather than an empty marker.
	 *
	 * @param array $source The Feature.
	 *
	 * @return array<int, array> The one feature, or nothing.
	 */
	private function featureOf(array $source): array {
		$geometry = ($source['geometry'] ?? null);
		if (is_array($geometry) === false) {
			return [];
		}

		$properties = ($source['properties'] ?? []);
		if (is_array($properties) === false) {
			$properties = [];
		}

		return [$this->feature(geometry: $geometry, properties: $properties)];
	}//end featureOf()

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
