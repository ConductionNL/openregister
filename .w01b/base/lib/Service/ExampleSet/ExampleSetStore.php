<?php

/**
 * OpenRegister Example Set Store
 *
 * Reads, validates, writes and deletes rows of any register for an example
 * set. Every call runs without access rules: the caller is `occ`, so there is
 * no user whose rights could be asked. The services are fetched from the
 * container on first use, so the occ command keeps a light DI graph.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-check-validates-every-object-against-its-real-schema-without-writing-req-exs-004
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Lifecycle\LifecycleActionContext;
use OCA\OpenRegister\Service\Object\ValidateObject;
use OCA\OpenRegister\Service\Rbac\ObjectOwnershipService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Register access for the example-set loader.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The one adapter between the loader and ObjectService/ValidateObject:
 *                                                   every OpenRegister failure becomes a refusal or a reason here.
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)     The loader's whole register surface (read, match, write, delete,
 *                                                   ids) in one adapter, so the loader never touches ObjectService.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-check-validates-every-object-against-its-real-schema-without-writing-req-exs-004
 */
class ExampleSetStore {
	/**
	 * The most rows one read returns.
	 */
	private const PAGE_SIZE = 500;

	/**
	 * The most pages one read asks for, so a read always ends.
	 */
	private const MAX_PAGES = 20;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Hands out ObjectService and ValidateObject.
	 * @param LoggerInterface    $logger    Logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether this instance has the register and the register carries the schema.
	 *
	 * @param string $register The register slug.
	 * @param string $schema   The schema slug.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-check-validates-every-object-against-its-real-schema-without-writing-req-exs-004
	 */
	public function offers(string $register, string $schema): bool {
		return $this->schemaId(register: $register, schema: $schema) !== null;
	}//end offers()

	/**
	 * What the schema's validator says about an object, without writing it.
	 *
	 * The validator runs whether or not the schema has hard validation on,
	 * because a save only validates when it has.
	 *
	 * An account id in $plannedUsers passes a property of format `user`
	 * although the account does not exist yet; every other id is still
	 * asked of Nextcloud's user manager.
	 *
	 * @param string               $register     The register slug.
	 * @param string               $schema       The schema slug.
	 * @param array<string, mixed> $data         The object.
	 * @param array<int, string>   $plannedUsers Account ids the set creates before it writes objects.
	 *
	 * @return array<int, string> The refusals; empty when the schema accepts the object.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-check-validates-every-object-against-its-real-schema-without-writing-req-exs-004
	 */
	public function validate(string $register, string $schema, array $data, array $plannedUsers=[]): array {
		$schemaId = $this->schemaId(register: $register, schema: $schema);
		if ($schemaId === null) {
			return ['this instance has no schema "'.$schema.'" in a register "'.$register.'"'];
		}

		try {
			$validator = $this->container->get(ValidateObject::class);
			$result    = $validator->validateObjectWithPlannedUsers(object: $data, schema: $schemaId, plannedUsers: $plannedUsers);
			if ($result->isValid() === true) {
				return [];
			}

			return [$validator->generateErrorMessage(result: $result)];
		} catch (Throwable $e) {
			return ['the validator could not run: '.$e->getMessage()];
		}
	}//end validate()

	/**
	 * The rows of one schema that match the filters, as plain arrays.
	 *
	 * @param string               $register The register slug.
	 * @param string               $schema   The schema slug.
	 * @param array<string, mixed> $filters  Property filters; empty reads every row.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-every-placeholder-resolves-or-the-set-is-refused-req-exs-002
	 */
	public function find(string $register, string $schema, array $filters): array {
		$service = $this->objectService();
		if ($service === null) {
			return [];
		}

		$plain = [];
		for ($page = 0; $page < self::MAX_PAGES; $page++) {
			try {
				$service->setRegister(register: $register);
				$service->setSchema(schema: $schema);
				$rows = (array) $service->findAll(
					config: ['filters' => $filters, 'limit' => self::PAGE_SIZE, 'offset' => ($page * self::PAGE_SIZE)],
					_rbac: false,
					_multitenancy: false
				);
			} catch (Throwable $e) {
				$this->logger->warning('OpenRegister: example set read failed', ['schema' => $schema, 'reason' => $e->getMessage()]);
				return $plain;
			}

			foreach ($rows as $row) {
				$row = $this->plain(row: $row);
				if ($row !== null) {
					$plain[] = $row;
				}
			}

			if (count($rows) < self::PAGE_SIZE) {
				break;
			}
		}//end for

		return $plain;
	}//end find()

	/**
	 * Every row of one schema that holds all the asked values, each once.
	 *
	 * Asked with the filter first, then without: OpenRegister does not filter
	 * on every kind of property, so a register that ignores the filter hands
	 * over every row, and the match is checked here either way. Reads across
	 * organisations, so a business key two tenants share yields both rows.
	 *
	 * @param string               $register The register slug.
	 * @param string               $schema   The schema slug.
	 * @param array<string, mixed> $where    The asked values, by field.
	 *
	 * @return array<int, array<string, mixed>> The matching rows; empty when none matches.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-every-placeholder-resolves-or-the-set-is-refused-req-exs-002
	 */
	public function matching(string $register, string $schema, array $where): array {
		foreach ([$where, []] as $filters) {
			$found = [];
			foreach ($this->find(register: $register, schema: $schema, filters: $filters) as $row) {
				if ($this->rowMatches(row: $row, where: $where) === false) {
					continue;
				}

				$id = $this->idOf(row: $row);
				if ($id === '') {
					$id = 'row-'.count($found);
				}

				$found[$id] = $row;
			}

			if ($found !== []) {
				return array_values($found);
			}
		}

		return [];
	}//end matching()

	/**
	 * Whether a row holds every asked value.
	 *
	 * @param array<string, mixed> $row   The row.
	 * @param array<string, mixed> $where The asked values, by field.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-every-placeholder-resolves-or-the-set-is-refused-req-exs-002
	 */
	public function rowMatches(array $row, array $where): bool {
		foreach ($where as $field => $value) {
			$held = ($row[$field] ?? null);
			if ($held === $value) {
				continue;
			}

			if (is_scalar($held) === false || is_scalar($value) === false || (string) $held !== (string) $value) {
				return false;
			}
		}

		return true;
	}//end rowMatches()

	/**
	 * One row by its id, or null when it is not there.
	 *
	 * Null is also what a read that failed gives; a caller that must tell
	 * the two apart uses {@see self::read()}.
	 *
	 * @param string $register The register slug.
	 * @param string $schema   The schema slug.
	 * @param string $id       The row's id.
	 *
	 * @return array<string, mixed>|null The row, with `id` set.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-an-install-proves-what-arrived-req-exs-006
	 */
	public function get(string $register, string $schema, string $id): ?array {
		return $this->read(register: $register, schema: $schema, id: $id)['row'];
	}//end get()

	/**
	 * One row by its id, and why it could not be read when that is not simply "it is not there".
	 *
	 * Only a row OpenRegister says does not exist (a miss, or null) is
	 * absent. A database error, a magic-table error or a schema that cannot
	 * be resolved says nothing about the row, so it comes back as an error:
	 * a caller must not read it as "the instance lost the object".
	 *
	 * @param string $register The register slug.
	 * @param string $schema   The schema slug.
	 * @param string $id       The row's id.
	 *
	 * @return array{row: array<string, mixed>|null, error: string} The row with `id` set, or null with '' when it is
	 *                                                              not there, or null with why the read failed.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-second-install-writes-nothing-req-exs-005
	 */
	public function read(string $register, string $schema, string $id): array {
		$service = $this->objectService();
		if ($service === null) {
			return ['row' => null, 'error' => 'OpenRegister\'s object service is not available'];
		}

		if ($id === '') {
			return ['row' => null, 'error' => ''];
		}

		try {
			$row = $service->find(id: $id, register: $register, schema: $schema, _rbac: false, _multitenancy: false);
		} catch (DoesNotExistException) {
			return ['row' => null, 'error' => ''];
		} catch (Throwable $e) {
			return ['row' => null, 'error' => $e->getMessage()];
		}

		$row = $this->plain(row: $row);
		if ($row === null || $this->idOf(row: $row) !== $id) {
			return ['row' => null, 'error' => ''];
		}

		return ['row' => (['id' => $id] + $row), 'error' => ''];
	}//end read()

	/**
	 * Write one row.
	 *
	 * @param string               $register The register slug.
	 * @param string               $schema   The schema slug.
	 * @param array<string, mixed> $data     The row.
	 * @param string|null          $owner    The account that owns the row; null for the system.
	 *
	 * @return array{id: string, error: string} The new id, or '' with why the write failed.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-second-install-writes-nothing-req-exs-005
	 */
	public function create(string $register, string $schema, array $data, ?string $owner=null): array {
		$service = $this->objectService();
		if ($service === null) {
			return ['id' => '', 'error' => 'OpenRegister\'s object service is not available'];
		}

		try {
			$saved = $service->runAsSystem(
				fn () => $service->saveObject(object: $data, register: $register, schema: $schema, _rbac: false, _multitenancy: false, _owner: $owner)
			);
		} catch (Throwable $e) {
			return ['id' => '', 'error' => $e->getMessage()];
		}

		$id = $this->idOf(row: (array) $this->plain(row: $saved));
		if ($id === '') {
			return ['id' => '', 'error' => 'OpenRegister answered the write without an id'];
		}

		return ['id' => $id, 'error' => ''];
	}//end create()

	/**
	 * Save an existing row with new data; '' when saved, otherwise why not.
	 *
	 * @param string               $register The register slug.
	 * @param string               $schema   The schema slug.
	 * @param string               $id       The row's id.
	 * @param array<string, mixed> $data     The whole row as it should be stored.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-set-may-change-an-object-it-did-not-create-and-undo-exactly-that-req-exs-011
	 */
	public function update(string $register, string $schema, string $id, array $data): string {
		$service = $this->objectService();
		if ($service === null) {
			return 'OpenRegister\'s object service is not available';
		}

		unset($data['@self'], $data['id']);
		$actions = $this->lifecycleActions();
		$actions?->enterDeclaredState(uuid: $id);
		try {
			$service->runAsSystem(
				fn () => $service->saveObject(object: $data, register: $register, schema: $schema, uuid: $id, _rbac: false, _multitenancy: false)
			);
		} catch (Throwable $e) {
			return $e->getMessage();
		} finally {
			$actions?->leaveDeclaredState(uuid: $id);
		}

		return '';
	}//end update()

	/**
	 * The owner of a stored object, or null when it cannot be read.
	 *
	 * @param string $register The register slug.
	 * @param string $schema   The schema slug.
	 * @param string $id       The object's id.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-declared-object-may-name-its-owner-from-the-cast
	 */
	public function ownerOf(string $register, string $schema, string $id): ?string {
		try {
			return $this->objectService()?->find(id: $id, register: $register, schema: $schema, _rbac: false, _multitenancy: false)?->getOwner();
		} catch (Throwable) {
			return null;
		}
	}//end ownerOf()

	/**
	 * Hand a stored object to an account, as the system, through OpenRegister's audited ownership change.
	 *
	 * @param string $id    The object's id; OpenRegister finds its register and schema.
	 * @param string $owner The account.
	 *
	 * @return string '' when handed over, otherwise why not.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-declared-object-may-name-its-owner-from-the-cast
	 */
	public function assignOwner(string $id, string $owner): string {
		$service = $this->objectService();
		if ($service === null) {
			return 'OpenRegister\'s object service is not available';
		}

		try {
			$outcome = $service->runAsSystem(
				fn () => $this->container->get(ObjectOwnershipService::class)->reassignMany(identifiers: [$id], newOwner: $owner)
			);
		} catch (Throwable $e) {
			return $e->getMessage();
		}

		return implode('; ', array_column((array) ($outcome['failed'] ?? []), 'reason'));
	}//end assignOwner()

	/**
	 * The top-level properties a schema types as text only: a value of one that reads back as a structure (JSON text
	 * the register decodes on read) is to be written as text again.
	 *
	 * @param string $register The register slug.
	 * @param string $schema   The schema slug.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-change-sends-a-text-property-as-text
	 */
	public function textProperties(string $register, string $schema): array {
		$service = $this->objectService();
		if ($service === null) {
			return [];
		}

		try {
			$service->setRegister(register: $register);
			$service->setSchema(schema: $schema);
			$entity = $service->getCurrentSchemaEntity();
		} catch (Throwable) {
			return [];
		}

		$text = [];
		foreach (array_filter((array) $entity?->getProperties(), 'is_array') as $name => $definition) {
			$type = (array) ($definition['type'] ?? []);
			if (in_array('string', $type, true) === true && array_diff($type, ['string', 'null']) === []) {
				$text[] = (string) $name;
			}
		}

		return $text;
	}//end textProperties()

	/**
	 * What a schema lets an update change.
	 *
	 * @param string $register The register slug.
	 * @param string $schema   The schema slug.
	 *
	 * @return array{appendOnly: bool, kept: array<int, string>, lenses: array<int, string>} Whether rows are never
	 *         updated, the fields kept as first written (readOnly, immutable), and the lens fields (never stored).
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-refresh-sends-only-what-the-schema-lets-a-write-change
	 */
	public function writeRules(string $register, string $schema): array {
		$rules   = ['appendOnly' => false, 'kept' => [], 'lenses' => []];
		$service = $this->objectService();
		if ($service === null) {
			return $rules;
		}

		try {
			$service->setRegister(register: $register);
			$service->setSchema(schema: $schema);
			$entity = $service->getCurrentSchemaEntity();
		} catch (Throwable) {
			return $rules;
		}

		if ($entity === null) {
			return $rules;
		}

		foreach ((array) $entity->getProperties() as $name => $definition) {
			if (is_array($definition) === true
				&& (($definition['readOnly'] ?? false) === true || ($definition[Schema::IMMUTABLE_PROPERTY_KEYWORD] ?? false) === true)
			) {
				$rules['kept'][] = (string) $name;
			}
		}

		$rules['appendOnly'] = $entity->isAppendOnly();
		$rules['lenses']     = array_keys($entity->getLenses());

		return $rules;
	}//end writeRules()

	/**
	 * The lifecycle action context, which tells the lifecycle guard that a write sets declared state; null when absent.
	 *
	 * @return LifecycleActionContext|null
	 */
	private function lifecycleActions(): ?LifecycleActionContext {
		try {
			$actions = $this->container->get(LifecycleActionContext::class);
		} catch (Throwable) {
			return null;
		}

		if ($actions instanceof LifecycleActionContext) {
			return $actions;
		}

		return null;
	}//end lifecycleActions()

	/**
	 * Delete one row the set's install record lists; '' when it is gone, otherwise why not.
	 *
	 * Removing an example set is an administrative act on data the set itself
	 * wrote, not a user deleting a record. So a row of a schema that declares
	 * `x-openregister-archival` is deleted through the system path the
	 * retention sweep uses (`_retentionSweep: true`), and, as on that path, a
	 * row under an active legal hold is never deleted. The remover is the only
	 * caller, and only with ids from the install record.
	 *
	 * @param string $register The register slug.
	 * @param string $schema   The schema slug.
	 * @param string $id       The row's id, as the install record holds it.
	 *
	 * @return string '' when the row is gone; otherwise the reason it stays.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-remove-deletes-exactly-what-the-install-made-req-exs-007
	 * @spec openspec/specs/example-sets/spec.md#requirement-remove-deletes-what-the-set-wrote-even-in-an-archival-schema-and-says-why-anything-stays-req-exs-022
	 */
	public function deleteRecorded(string $register, string $schema, string $id): string {
		$service = $this->objectService();
		if ($service === null) {
			return 'OpenRegister\'s object service is not available';
		}

		// Only a row OpenRegister says is not there is gone; a read that failed
		// proves nothing, so the row stays in the record and the reason is named.
		try {
			$row = $service->find(id: $id, register: $register, schema: $schema, _rbac: false, _multitenancy: false);
		} catch (DoesNotExistException) {
			$row = null;
		} catch (Throwable $e) {
			$this->logger->warning('OpenRegister: example set read before delete failed', ['schema' => $schema, 'id' => $id, 'reason' => $e->getMessage()]);
			return 'it could not be read: '.$e->getMessage();
		}

		if ($row?->hasActiveLegalHold() === true) {
			return 'it is under an active legal hold';
		}

		if ($row === null) {
			return '';
		}

		try {
			$deleted = $service->deleteObject(
				uuid: $id,
				register: $register,
				schema: $schema,
				_rbac: false,
				_multitenancy: false,
				_retentionSweep: true
			);
		} catch (Throwable $e) {
			$this->logger->warning('OpenRegister: example set delete failed', ['schema' => $schema, 'id' => $id, 'reason' => $e->getMessage()]);
			return $e->getMessage();
		}

		if ($deleted === false) {
			return 'OpenRegister did not delete it';
		}

		return '';
	}//end deleteRecorded()

	/**
	 * The id OpenRegister gave a row, wherever it put it.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string The id, or '' when the row names none.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-every-placeholder-resolves-or-the-set-is-refused-req-exs-002
	 */
	public function idOf(array $row): string {
		$self = [];
		if (is_array($row['@self'] ?? null) === true) {
			$self = $row['@self'];
		}

		foreach ([($self['id'] ?? null), ($self['uuid'] ?? null), ($row['id'] ?? null), ($row['uuid'] ?? null)] as $id) {
			if (is_string($id) === true && $id !== '') {
				return $id;
			}
		}

		return '';
	}//end idOf()

	/**
	 * The database id of a register, or null when the instance has none with that slug.
	 *
	 * @param string $register The register slug.
	 *
	 * @return int|null
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-register-or-schema-is-named-by-slug-and-written-as-its-id-req-exs-021
	 */
	public function registerId(string $register): ?int {
		$service = $this->objectService();
		if ($service === null) {
			return null;
		}

		try {
			$service->setRegister(register: $register);

			return $service->getRegister();
		} catch (Throwable) {
			return null;
		}
	}//end registerId()

	/**
	 * The database id of a schema inside a register, or null.
	 *
	 * The schema is looked for inside that register only, never across the instance.
	 *
	 * @param string $register The register slug.
	 * @param string $schema   The schema slug.
	 *
	 * @return int|null
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-register-or-schema-is-named-by-slug-and-written-as-its-id-req-exs-021
	 */
	public function schemaId(string $register, string $schema): ?int {
		$service = $this->objectService();
		if ($service === null) {
			return null;
		}

		try {
			$service->setRegister(register: $register);
			$service->setSchema(schema: $schema);

			return $service->getSchema();
		} catch (Throwable) {
			return null;
		}
	}//end schemaId()

	/**
	 * A row as a plain array, or null when it is not one.
	 *
	 * @param mixed $row What OpenRegister handed over.
	 *
	 * @return array<string, mixed>|null
	 */
	private function plain(mixed $row): ?array {
		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			$row = $row->jsonSerialize();
		}

		if (is_array($row) === true) {
			return $row;
		}

		return null;
	}//end plain()

	/**
	 * OpenRegister's ObjectService, or null when it cannot be built.
	 *
	 * @return ObjectService|null
	 */
	private function objectService(): ?ObjectService {
		try {
			$service = $this->container->get(ObjectService::class);
		} catch (Throwable) {
			return null;
		}

		if ($service instanceof ObjectService) {
			return $service;
		}

		return null;
	}//end objectService()
}//end class
