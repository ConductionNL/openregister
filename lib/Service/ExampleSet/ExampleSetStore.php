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
	 * @param string               $register The register slug.
	 * @param string               $schema   The schema slug.
	 * @param array<string, mixed> $data     The object.
	 *
	 * @return array<int, string> The refusals; empty when the schema accepts the object.
	 *
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-check-validates-every-object-against-its-real-schema-without-writing-req-exs-004
	 */
	public function validate(string $register, string $schema, array $data): array {
		$schemaId = $this->schemaId(register: $register, schema: $schema);
		if ($schemaId === null) {
			return ['this instance has no schema "'.$schema.'" in a register "'.$register.'"'];
		}

		try {
			$validator = $this->container->get(ValidateObject::class);
			$result    = $validator->validateObject(object: $data, schema: $schemaId);
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
	 * Delete one row; true when it is gone.
	 *
	 * @param string $register The register slug.
	 * @param string $schema   The schema slug.
	 * @param string $id       The row's id.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-remove-deletes-exactly-what-the-install-made-req-exs-007
	 */
	public function delete(string $register, string $schema, string $id): bool {
		$service = $this->objectService();
		if ($service === null || $id === '') {
			return false;
		}

		if ($this->get(register: $register, schema: $schema, id: $id) === null) {
			return true;
		}

		try {
			$deleted = $service->deleteObject(uuid: $id, register: $register, schema: $schema, _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			$this->logger->warning('OpenRegister: example set delete failed', ['schema' => $schema, 'reason' => $e->getMessage()]);
			return false;
		}

		return $deleted !== false;
	}//end delete()

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
	 * The database id of a schema inside a register, or null.
	 *
	 * @param string $register The register slug.
	 * @param string $schema   The schema slug.
	 *
	 * @return int|null
	 */
	private function schemaId(string $register, string $schema): ?int {
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
