<?php

/**
 * The links a copy takes along: relation rows and incoming array references.
 *
 * 🔴 NOTHING THE CALLER COULD NOT DO BY HAND (D-4). Every link is written under
 * the caller's own rights. A link the caller may not write is reported as
 * `skipped` with the reason and does not stop the copy: the copy itself was
 * already allowed, and refusing it for one list the caller cannot edit would
 * make the whole act depend on somebody else's rights.
 *
 * 🔴 THE SOURCE IS NEVER CHANGED. Relation rows are written with the COPY as
 * their source; an incoming reference gains the copy BESIDE the source, it
 * never swaps one for the other. A single-valued reference is left alone and
 * reported, because the only way to point it at the copy is to take it away
 * from the source.
 *
 * Provenance rows (`split`, `derive`) and prose rows are not copied: they say
 * where the source came from or which text on the source names something, and
 * neither is true of the copy.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/records-copy-with-links/specs/objects-crud/spec.md#requirement-an-object-can-be-copied-with-the-links-the-caller-chooses
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectRelation;
use OCA\OpenRegister\Db\ObjectRelationMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Copies the links a caller chose onto a copy and reports each one.
 *
 * @spec openspec/changes/records-copy-with-links/specs/objects-crud/spec.md#requirement-an-object-can-be-copied-with-the-links-the-caller-chooses
 */
class CopyObject {

	/**
	 * The link kinds this service copies.
	 *
	 * @var array<int, string>
	 */
	public const LINK_KINDS = ['relationRows', 'incoming'];

	/**
	 * Every link kind a request may name.
	 *
	 * @var array<int, string>
	 */
	public const INCLUDE_KINDS = ['relationRows', 'incoming', 'files'];

	/**
	 * The most referring objects one copy updates.
	 */
	public const MAX_REFERRERS = 500;

	/**
	 * The outcome of a link written.
	 */
	public const COPIED = 'copied';

	/**
	 * The outcome of a link left out.
	 */
	public const SKIPPED = 'skipped';

	/**
	 * The fields of a relation row that describe the link, carried onto the copy's row.
	 *
	 * @var array<int, string>
	 */
	private const ROW_FIELDS = [
		'targetUuid',
		'targetRegister',
		'targetSchema',
		'targetUrl',
		'targetTitle',
		'kind',
		'relationType',
		'label',
		'inverseLabel',
		'symmetric',
		'origin',
	];

	/**
	 * Constructor.
	 *
	 * @param ObjectRelationMapperInterface $relations   The relation rows.
	 * @param PermissionHandlerInterface    $permissions The caller's rights on a referring object.
	 * @param SchemaMapperInterface         $schemas     The schema of a referring object.
	 * @param LoggerInterface               $logger      Logger.
	 *
	 * @spec openspec/changes/records-copy-with-links/specs/objects-crud/spec.md#requirement-an-object-can-be-copied-with-the-links-the-caller-chooses
	 */
	public function __construct(
		private readonly ObjectRelationMapperInterface $relations,
		private readonly PermissionHandlerInterface $permissions,
		private readonly SchemaMapperInterface $schemas,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The link kinds of a request that this service does not know.
	 *
	 * @param array<int|string, mixed> $include The requested kinds.
	 *
	 * @return array<int, string> The unknown ones, empty when all are known.
	 *
	 * @spec openspec/changes/records-copy-with-links/specs/objects-crud/spec.md#requirement-an-object-can-be-copied-with-the-links-the-caller-chooses
	 */
	public static function unknownKinds(array $include): array {
		$unknown = [];
		foreach ($include as $kind) {
			if (is_string($kind) === true && in_array($kind, self::INCLUDE_KINDS, true) === true) {
				continue;
			}

			$unknown[] = gettype($kind);
			if (is_scalar($kind) === true) {
				$unknown[array_key_last($unknown)] = (string) $kind;
			}
		}

		return $unknown;
	}//end unknownKinds()

	/**
	 * Copy the chosen links onto the copy.
	 *
	 * @param ObjectEntity              $source  The object copied.
	 * @param ObjectEntity              $copy    The new object.
	 * @param array<int, string>        $include The link kinds to take along.
	 * @param ObjectOperationsInterface $objects The object service, used under the caller's rights.
	 *
	 * @return array{relationRows: list<array<string, mixed>>, incoming: list<array<string, mixed>>}
	 *
	 * @spec openspec/changes/records-copy-with-links/specs/objects-crud/spec.md#requirement-an-object-can-be-copied-with-the-links-the-caller-chooses
	 */
	public function links(ObjectEntity $source, ObjectEntity $copy, array $include, ObjectOperationsInterface $objects): array {
		$links = ['relationRows' => [], 'incoming' => []];
		if (in_array('relationRows', $include, true) === true) {
			$links['relationRows'] = $this->relationRows(source: $source, copy: $copy, objects: $objects);
		}

		if (in_array('incoming', $include, true) === true) {
			$links['incoming'] = $this->incoming(source: $source, copy: $copy, objects: $objects);
		}

		return $links;
	}//end links()

	/**
	 * Re-create the source's own relation rows with the copy as their source.
	 *
	 * A row to another object needs the caller to be able to read that object,
	 * as adding the link by hand does.
	 *
	 * @param ObjectEntity              $source  The object copied.
	 * @param ObjectEntity              $copy    The new object.
	 * @param ObjectOperationsInterface $objects The object service.
	 *
	 * @return list<array<string, mixed>> One outcome per row.
	 *
	 * @spec openspec/changes/records-copy-with-links/specs/objects-crud/spec.md#requirement-an-object-can-be-copied-with-the-links-the-caller-chooses
	 */
	public function relationRows(ObjectEntity $source, ObjectEntity $copy, ObjectOperationsInterface $objects): array {
		$outcomes = [];
		foreach ($this->relations->findBySource(sourceUuid: (string) $source->getUuid()) as $row) {
			$reason = $this->rowRefusal(row: $row, objects: $objects);
			if ($reason === null) {
				$this->relations->createFromArray($this->rowFor(row: $row, copy: $copy));
			}

			$outcomes[] = $this->outcome(
				id: (string) $row->getUuid(),
				title: (string) ($row->getTargetTitle() ?? ($row->getTargetUuid() ?? ($row->getTargetUrl() ?? ''))),
				reason: $reason
			);
		}

		return $outcomes;
	}//end relationRows()

	/**
	 * Add the copy beside the source in every array-valued reference to the source.
	 *
	 * Each referring object is read and updated under the caller's own rights.
	 *
	 * @param ObjectEntity              $source  The object copied.
	 * @param ObjectEntity              $copy    The new object.
	 * @param ObjectOperationsInterface $objects The object service.
	 *
	 * @return list<array<string, mixed>> One outcome per referring object.
	 *
	 * @spec openspec/changes/records-copy-with-links/specs/objects-crud/spec.md#requirement-an-object-can-be-copied-with-the-links-the-caller-chooses
	 */
	public function incoming(ObjectEntity $source, ObjectEntity $copy, ObjectOperationsInterface $objects): array {
		$sourceUuid = (string) $source->getUuid();
		$used       = $objects->getObjectUsedBy(objectId: $sourceUuid, query: ['_limit' => self::MAX_REFERRERS], _rbac: true, _multitenancy: true);

		$outcomes = [];
		foreach (($used['results'] ?? []) as $referrer) {
			if (is_array($referrer) === false) {
				continue;
			}

			$self = [];
			if (is_array($referrer['@self'] ?? null) === true) {
				$self = $referrer['@self'];
			}

			$uuid = (string) ($self['id'] ?? ($referrer['id'] ?? ''));
			if ($uuid === '' || $uuid === $sourceUuid) {
				continue;
			}

			$outcomes[] = $this->outcome(
				id: $uuid,
				title: (string) ($self['name'] ?? $uuid),
				reason: $this->addBeside(referrer: $referrer, self: $self, source: $sourceUuid, copy: (string) $copy->getUuid(), objects: $objects)
			);
		}//end foreach

		return $outcomes;
	}//end incoming()

	/**
	 * Add the copy to the referrer's array references to the source.
	 *
	 * @param array<string, mixed>      $referrer The referring object as `used` returns it.
	 * @param array<string, mixed>      $self     Its `@self` metadata.
	 * @param string                    $source   The source uuid.
	 * @param string                    $copy     The copy uuid.
	 * @param ObjectOperationsInterface $objects  The object service.
	 *
	 * @return string|null Why it was skipped; null when the copy was added.
	 */
	private function addBeside(array $referrer, array $self, string $source, string $copy, ObjectOperationsInterface $objects): ?string {
		if ($this->arrayReferences(data: $referrer, uuid: $source) === []) {
			return 'Only a single-valued reference points at the source; a copy does not take it over.';
		}

		try {
			$entity = $objects->find(
				id: (string) ($self['id'] ?? $referrer['id']),
				options: new ObjectFindOptions(register: ($self['register'] ?? null), schema: ($self['schema'] ?? null)),
				_rbac: true,
				_multitenancy: true
			);
		} catch (Throwable) {
			$entity = null;
		}

		if ($entity === null) {
			return 'You may not read this object.';
		}

		if ($this->mayUpdate(object: $entity) === false) {
			return 'You may not update this object.';
		}

		$data = $entity->getObject();
		foreach ($this->arrayReferences(data: $data, uuid: $source) as $property) {
			if (in_array($copy, $data[$property], true) === false) {
				$data[$property][] = $copy;
			}
		}

		try {
			$objects->saveObject(
				object: $data,
				options: new ObjectSaveOptions(
					target: new ObjectFindTarget(register: $entity->getRegister(), schema: $entity->getSchema()),
					uuid: (string) $entity->getUuid()
				),
				_rbac: true,
				_multitenancy: true
			);
		} catch (NotAuthorizedException) {
			return 'You may not update this object.';
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[CopyObject] A reference to the copy could not be added',
				context: ['file' => __FILE__, 'line' => __LINE__, 'object' => $entity->getUuid(), 'exception' => $e->getMessage()]
			);
			return 'The reference could not be added: '.$e->getMessage();
		}

		return null;
	}//end addBeside()

	/**
	 * The top-level properties whose value is a list holding the uuid.
	 *
	 * @param array<string, mixed> $data The object's data.
	 * @param string               $uuid The uuid looked for.
	 *
	 * @return array<int, string> The property names.
	 */
	private function arrayReferences(array $data, string $uuid): array {
		$properties = [];
		foreach ($data as $property => $value) {
			if ((string) $property === '@self' || is_array($value) === false || array_is_list($value) === false) {
				continue;
			}

			if (in_array($uuid, $value, true) === true) {
				$properties[] = (string) $property;
			}
		}

		return $properties;
	}//end arrayReferences()

	/**
	 * Whether the caller may update an object.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return bool
	 */
	private function mayUpdate(ObjectEntity $object): bool {
		$register = $object->getRegister();
		if ($register !== null) {
			$register = (string) $register;
		}

		try {
			$this->permissions->checkPermission(
				schema: $this->schemas->find(id: (string) $object->getSchema(), _rbac: false, _multitenancy: false),
				action: 'update',
				options: new PermissionCheckOptions(
					objectOwner: $object->getOwner(),
					object: $object,
					register: $register
				)
			);
		} catch (NotAuthorizedException) {
			return false;
		} catch (Throwable $e) {
			// Fail closed: a check that could not run is not a grant.
			$this->logger->warning(
				message: '[CopyObject] The update right on a referring object could not be checked',
				context: ['file' => __FILE__, 'line' => __LINE__, 'object' => $object->getUuid(), 'exception' => $e->getMessage()]
			);
			return false;
		}

		return true;
	}//end mayUpdate()

	/**
	 * Why a relation row is not copied, or null when it is.
	 *
	 * @param ObjectRelation            $row     The row.
	 * @param ObjectOperationsInterface $objects The object service.
	 *
	 * @return string|null
	 */
	private function rowRefusal(ObjectRelation $row, ObjectOperationsInterface $objects): ?string {
		$origin = $row->getOrigin();
		if ($origin === ObjectRelation::ORIGIN_SPLIT || $origin === ObjectRelation::ORIGIN_DERIVE) {
			return 'It records where the source came from, which is not true of the copy.';
		}

		if ($origin === ObjectRelation::ORIGIN_PROSE) {
			return 'It belongs to a text on the source.';
		}

		if ($row->getKind() !== ObjectRelation::KIND_OBJECT) {
			return null;
		}

		try {
			$target = $objects->find(id: (string) $row->getTargetUuid(), _rbac: true, _multitenancy: true);
		} catch (Throwable) {
			$target = null;
		}

		if ($target === null) {
			return 'You may not read the linked object.';
		}

		return null;
	}//end rowRefusal()

	/**
	 * The copy's relation row for a row of the source.
	 *
	 * @param ObjectRelation $row  The source's row.
	 * @param ObjectEntity   $copy The new object.
	 *
	 * @return array<string, mixed>
	 */
	private function rowFor(ObjectRelation $row, ObjectEntity $copy): array {
		$fields = [
			'sourceUuid' => (string) $copy->getUuid(),
			'sourceRegister' => $this->intOrNull(value: $copy->getRegister()),
			'sourceSchema' => $this->intOrNull(value: $copy->getSchema()),
		];
		foreach (self::ROW_FIELDS as $field) {
			$fields[$field] = $row->{'get'.ucfirst($field)}();
		}

		return $fields;
	}//end rowFor()

	/**
	 * A numeric id as an int; null otherwise.
	 *
	 * @param mixed $value The value.
	 *
	 * @return int|null
	 */
	private function intOrNull(mixed $value): ?int {
		if (is_numeric($value) === true) {
			return (int) $value;
		}

		return null;
	}//end intOrNull()

	/**
	 * One link's outcome.
	 *
	 * @param string      $id     The link's id.
	 * @param string      $title  What to call it.
	 * @param string|null $reason Why it was skipped; null when copied.
	 *
	 * @return array{id: string, title: string, outcome: string, reason: string|null}
	 */
	private function outcome(string $id, string $title, ?string $reason): array {
		$outcome = self::SKIPPED;
		if ($reason === null) {
			$outcome = self::COPIED;
		}

		return ['id' => $id, 'title' => $title, 'outcome' => $outcome, 'reason' => $reason];
	}//end outcome()
}//end class
