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
 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-check-validates-every-object-against-its-real-schema-without-writing-req-exs-004
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

use OCA\OpenRegister\Service\Object\ValidateObject;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Register access for the example-set loader.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The one adapter between the loader and ObjectService/ValidateObject:
 *                                                   every OpenRegister failure becomes a refusal or a reason here.
 *
 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-check-validates-every-object-against-its-real-schema-without-writing-req-exs-004
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
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-check-validates-every-object-against-its-real-schema-without-writing-req-exs-004
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
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-check-validates-every-object-against-its-real-schema-without-writing-req-exs-004
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
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-every-placeholder-resolves-or-the-set-is-refused-req-exs-002
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
	 * One row by its id, or null when it is not there.
	 *
	 * @param string $register The register slug.
	 * @param string $schema   The schema slug.
	 * @param string $id       The row's id.
	 *
	 * @return array<string, mixed>|null The row, with `id` set.
	 *
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-an-install-proves-what-arrived-req-exs-006
	 */
	public function get(string $register, string $schema, string $id): ?array {
		$service = $this->objectService();
		if ($service === null || $id === '') {
			return null;
		}

		try {
			$row = $service->find(id: $id, register: $register, schema: $schema, _rbac: false, _multitenancy: false);
		} catch (Throwable) {
			return null;
		}

		$row = $this->plain(row: $row);
		if ($row === null || $this->idOf(row: $row) !== $id) {
			return null;
		}

		return (['id' => $id] + $row);
	}//end get()

	/**
	 * Write one row.
	 *
	 * @param string               $register The register slug.
	 * @param string               $schema   The schema slug.
	 * @param array<string, mixed> $data     The row.
	 *
	 * @return array{id: string, error: string} The new id, or '' with why the write failed.
	 *
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-a-second-install-writes-nothing-req-exs-005
	 */
	public function create(string $register, string $schema, array $data): array {
		$service = $this->objectService();
		if ($service === null) {
			return ['id' => '', 'error' => 'OpenRegister\'s object service is not available'];
		}

		try {
			$saved = $service->saveObject(object: $data, register: $register, schema: $schema, _rbac: false, _multitenancy: false);
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
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-a-set-may-change-an-object-it-did-not-create-and-undo-exactly-that-req-exs-011
	 */
	public function update(string $register, string $schema, string $id, array $data): string {
		$service = $this->objectService();
		if ($service === null) {
			return 'OpenRegister\'s object service is not available';
		}

		unset($data['@self'], $data['id']);
		try {
			$service->saveObject(object: $data, register: $register, schema: $schema, uuid: $id, _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			return $e->getMessage();
		}

		return '';
	}//end update()

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
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-remove-deletes-exactly-what-the-install-made-req-exs-007
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-remove-deletes-what-the-set-wrote-even-in-an-archival-schema-and-says-why-anything-stays-req-exs-022
	 */
	public function deleteRecorded(string $register, string $schema, string $id): string {
		$service = $this->objectService();
		if ($service === null) {
			return 'OpenRegister\'s object service is not available';
		}

		// A row that cannot be found is gone, as the record's other steps read it.
		try {
			$row = $service->find(id: $id, register: $register, schema: $schema, _rbac: false, _multitenancy: false);
		} catch (Throwable) {
			$row = null;
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
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-every-placeholder-resolves-or-the-set-is-refused-req-exs-002
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
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-a-register-or-schema-is-named-by-slug-and-written-as-its-id-req-exs-021
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
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-a-register-or-schema-is-named-by-slug-and-written-as-its-id-req-exs-021
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
