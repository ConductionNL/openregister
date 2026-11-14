<?php

/**
 * Holds an object back from destruction while a declared ancestor is kept permanently
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/appraisal-inherited-from-a-parent/specs/archivering-vernietiging/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

use OCA\OpenRegister\Db\MagicMapper\MagicObjectFinderInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Follows a schema's `x-openregister-retention.inheritAppraisalFrom` references.
 *
 * Breadth-first, at most five levels, each object once, read as the system so
 * a permission gap can never make an ancestor invisible. An ancestor that
 * cannot be read holds the object back as firmly as a permanent one: deleting
 * a record because its parent could not be checked is the one mistake this
 * rule exists to prevent.
 *
 * @spec openspec/changes/appraisal-inherited-from-a-parent/specs/archivering-vernietiging/spec.md
 */
class InheritedAppraisal {

	/**
	 * The schema annotation the declaration lives in.
	 *
	 * @var string
	 */
	public const ANNOTATION = 'x-openregister-retention';

	/**
	 * The key of the declared properties inside the annotation.
	 *
	 * @var string
	 */
	public const KEY = 'inheritAppraisalFrom';

	/**
	 * How many levels of ancestors are followed.
	 *
	 * @var int
	 */
	public const MAX_DEPTH = 5;

	/**
	 * The reason prefix of an object held back by a permanent ancestor; the ancestor's uuid follows.
	 *
	 * @var string
	 */
	public const REASON_PERMANENT_ANCESTOR = 'inherited_permanent_appraisal';

	/**
	 * The reason prefix of an object held back by an unreadable ancestor; the property and reference follow.
	 *
	 * @var string
	 */
	public const REASON_UNRESOLVED_ANCESTOR = 'unresolved_ancestor';

	/**
	 * Schemas read so far, by id; null when the schema could not be read.
	 *
	 * @var array<string, Schema|null>
	 */
	private array $schemas = [];

	/**
	 * Constructor.
	 *
	 * @param MagicObjectFinderInterface $objects Reads the referenced objects.
	 * @param SchemaMapperInterface      $schemaMapper Reads the schemas' declarations.
	 * @param LoggerInterface            $logger  The logger.
	 */
	public function __construct(
		private readonly MagicObjectFinderInterface $objects,
		private readonly SchemaMapperInterface $schemaMapper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Why an object is held back by an ancestor, or null when no ancestor holds it.
	 *
	 * @param ObjectEntity $object The object judged for destruction.
	 *
	 * @return string|null `inherited_permanent_appraisal:<ancestor uuid>`,
	 *                     `unresolved_ancestor:<property>:<reference>`, or null.
	 *
	 * @spec openspec/changes/appraisal-inherited-from-a-parent/specs/archivering-vernietiging/spec.md
	 */
	public function holdBack(ObjectEntity $object): ?string {
		$visited  = [(string) $object->getUuid() => true];
		$frontier = [$object];
		for ($depth = 1; $depth <= self::MAX_DEPTH && $frontier !== []; $depth++) {
			$next = [];
			foreach ($frontier as $node) {
				foreach ($this->references(object: $node) as [$property, $reference]) {
					if (isset($visited[$reference]) === true) {
						continue;
					}

					$visited[$reference] = true;
					$ancestor = $this->read(reference: $reference);
					if ($ancestor === null) {
						return self::REASON_UNRESOLVED_ANCESTOR.':'.$property.':'.$reference;
					}

					if (self::isKeptPermanently(object: $ancestor) === true) {
						return self::REASON_PERMANENT_ANCESTOR.':'.(string) $ancestor->getUuid();
					}

					$next[] = $ancestor;
				}
			}

			$frontier = $next;
		}//end for

		return null;
	}//end holdBack()

	/**
	 * The properties a schema declares, from its configuration.
	 *
	 * @param array<string, mixed>|null $configuration The schema configuration.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/changes/appraisal-inherited-from-a-parent/specs/archivering-vernietiging/spec.md
	 */
	public static function declaredProperties(?array $configuration): array {
		$declared = (($configuration[self::ANNOTATION] ?? [])[self::KEY] ?? []);
		if (is_array($declared) === false) {
			return [];
		}

		return array_values(array_filter($declared, static fn ($property): bool => is_string($property) === true && $property !== ''));
	}//end declaredProperties()

	/**
	 * The references an object's declared properties hold, as [property, reference] pairs.
	 *
	 * A property holds a uuid, a list of uuids, or objects carrying `id`, `uuid` or `@self.id`.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return list<array{0: string, 1: string}>
	 */
	private function references(ObjectEntity $object): array {
		$schema = $this->schema(id: (string) $object->getSchema());
		if ($schema === null) {
			return [];
		}

		$data  = $object->getObject();
		$pairs = [];
		foreach (self::declaredProperties(configuration: $schema->getConfiguration()) as $property) {
			$values = ($data[$property] ?? null);
			if (is_array($values) === false || array_is_list($values) === false) {
				$values = [$values];
			}

			foreach ($values as $value) {
				$reference = self::referenceOf(value: $value);
				if ($reference !== '') {
					$pairs[] = [$property, $reference];
				}
			}
		}

		return $pairs;
	}//end references()

	/**
	 * The reference one value holds, or ''.
	 *
	 * @param mixed $value A uuid or an embedded object.
	 *
	 * @return string
	 */
	private static function referenceOf(mixed $value): string {
		if (is_string($value) === true) {
			return trim($value);
		}

		if (is_array($value) === true) {
			return trim((string) ($value['id'] ?? $value['uuid'] ?? ($value['@self']['id'] ?? '')));
		}

		return '';
	}//end referenceOf()

	/**
	 * Whether an object's own appraisal keeps it permanently.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return bool
	 */
	private static function isKeptPermanently(ObjectEntity $object): bool {
		$appraisal = (($object->getRetention() ?? [])['archiefnominatie'] ?? '');

		return in_array($appraisal, AppraisalInterface::RETAIN_PERMANENTLY_ALIASES, true);
	}//end isKeptPermanently()

	/**
	 * One referenced object, read as the system, or null when it cannot be read.
	 *
	 * @param string $reference The uuid.
	 *
	 * @return ObjectEntity|null
	 */
	private function read(string $reference): ?ObjectEntity {
		try {
			return $this->objects->findAcrossAllSources(identifier: $reference, includeDeleted: false, _rbac: false, _multitenancy: false)['object'];
		} catch (Throwable $failure) {
			$this->logger->info(
				'[InheritedAppraisal] An ancestor could not be read; the object is held back',
				['reference' => $reference, 'exception' => $failure->getMessage()]
			);
			return null;
		}
	}//end read()

	/**
	 * A schema by id, read once, as the system.
	 *
	 * @param string $id The schema id.
	 *
	 * @return Schema|null
	 */
	private function schema(string $id): ?Schema {
		if ($id === '') {
			return null;
		}

		if (array_key_exists($id, $this->schemas) === false) {
			try {
				$this->schemas[$id] = $this->schemaMapper->find(id: $id, _rbac: false, _multitenancy: false);
			} catch (Throwable $failure) {
				$this->schemas[$id] = null;
			}
		}

		return $this->schemas[$id];
	}//end schema()
}//end class
