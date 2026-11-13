<?php

/**
 * ExpiredObjectStore: the database side of the expired-object sweep.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Retention
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/expired-object-sweep/specs/expired-object-sweep/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Retention;

use DateTimeImmutable;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectDeletion;
use OCA\OpenRegister\Service\FileService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Finds expired objects in the magic tables of opted-in schemas, deletes or
 * anonymises them, and writes their audit entries.
 */
class ExpiredObjectStore implements ExpiredObjectStoreInterface {

	/**
	 * The audit actor of a sweep write.
	 */
	private const ACTOR = 'system';

	/**
	 * Constructor.
	 *
	 * @param ExpiredObjectSources      $sources The mappers and the connection.
	 * @param ObjectOperationsInterface $objects The object service.
	 * @param FileService               $files   The file service.
	 * @param LoggerInterface           $logger  The logger.
	 */
	public function __construct(
		private readonly ExpiredObjectSources $sources,
		private readonly ObjectOperationsInterface $objects,
		private readonly FileService $files,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Every register and schema pair whose schema opted in to expiry and whose table exists.
	 *
	 * @return array<int, array{register: Register, schema: Schema}>
	 *
	 * @spec openspec/changes/expired-object-sweep/specs/expired-object-sweep/spec.md
	 */
	public function optedInTables(): array {
		$tables = [];
		foreach ($this->sources->registers->findAll(_rbac: false, _multitenancy: false) as $register) {
			if ($register instanceof Register === false) {
				continue;
			}

			foreach (($register->getSchemas() ?? []) as $schemaId) {
				$schema = $this->optedInSchema(register: $register, schemaId: $schemaId);
				if ($schema !== null) {
					$tables[] = ['register' => $register, 'schema' => $schema];
				}
			}
		}

		return $tables;
	}//end optedInTables()

	/**
	 * The objects of one table whose expiry is before $now, oldest expiry first, at most $limit.
	 *
	 * @param Register          $register The register.
	 * @param Schema            $schema   The schema.
	 * @param DateTimeImmutable $now      The moment of the run.
	 * @param int               $limit    The most objects to return.
	 *
	 * @return array<int, ObjectEntity>
	 *
	 * @spec openspec/changes/expired-object-sweep/specs/expired-object-sweep/spec.md
	 */
	public function expired(Register $register, Schema $schema, DateTimeImmutable $now, int $limit): array {
		$qb = $this->expiredQuery(register: $register, schema: $schema, now: $now);
		$qb->select('_uuid')->orderBy('_expires', 'ASC')->setMaxResults(max(1, $limit));

		$objects = [];
		$cursor  = $qb->executeQuery();
		while (($row = $cursor->fetch()) !== false) {
			$object = $this->load(register: $register, schema: $schema, uuid: (string)($row['_uuid'] ?? ''));
			if ($object !== null) {
				$objects[] = $object;
			}
		}

		$cursor->closeCursor();

		return $objects;
	}//end expired()

	/**
	 * How many objects of one table have an expiry before $now.
	 *
	 * @param Register          $register The register.
	 * @param Schema            $schema   The schema.
	 * @param DateTimeImmutable $now      The moment of the run.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/expired-object-sweep/specs/expired-object-sweep/spec.md
	 */
	public function countExpired(Register $register, Schema $schema, DateTimeImmutable $now): int {
		$qb = $this->expiredQuery(register: $register, schema: $schema, now: $now);
		$qb->select($qb->func()->count('*', 'expired'));
		$cursor = $qb->executeQuery();
		$count  = (int)$cursor->fetchOne();
		$cursor->closeCursor();

		return $count;
	}//end countExpired()

	/**
	 * Delete one object through the object service, without RBAC or multitenancy (the sweep is the system).
	 *
	 * @param Register     $register The register.
	 * @param Schema       $schema   The schema.
	 * @param ObjectEntity $object   The object.
	 *
	 * @return bool Whether it was deleted.
	 *
	 * @spec openspec/changes/expired-object-sweep/specs/expired-object-sweep/spec.md
	 */
	public function delete(Register $register, Schema $schema, ObjectEntity $object): bool {
		$this->objects->setRegister(register: $register);
		$this->objects->setSchema(schema: $schema);

		return $this->objects->deleteObject(deletion: new ObjectDeletion(uuid: (string)$object->getUuid()), _rbac: false, _multitenancy: false);
	}//end delete()

	/**
	 * Replace one object's data, remove its files and clear its expiry.
	 *
	 * The write goes straight to the mapper, so the schema's validation does
	 * not run: the answers an anonymisation empties are often required.
	 *
	 * @param Register             $register The register.
	 * @param Schema               $schema   The schema.
	 * @param ObjectEntity         $object   The object.
	 * @param array<string, mixed> $data     The anonymised data.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/expired-object-sweep/specs/expired-object-sweep/spec.md
	 */
	public function anonymise(Register $register, Schema $schema, ObjectEntity $object, array $data): void {
		$this->removeFiles(object: $object);

		$old = clone $object;
		$object->setObject($data);
		$object->setExpires(null);
		$this->sources->magic->update($object, $register, $schema, $old);
	}//end anonymise()

	/**
	 * Write one audit entry for an object, as the system.
	 *
	 * @param ObjectEntity         $object  The object.
	 * @param string               $action  The audit action.
	 * @param array<string, mixed> $context What to record with it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/expired-object-sweep/specs/expired-object-sweep/spec.md
	 */
	public function audit(ObjectEntity $object, string $action, array $context): void {
		$this->sources->audit->createAuditTrailEntry(object: $object, action: $action, context: $context, actorId: self::ACTOR, actorName: self::ACTOR);
	}//end audit()

	/**
	 * The schema behind a register's schema id when it opted in and its table exists, else null.
	 *
	 * @param Register $register The register.
	 * @param mixed    $schemaId The schema id.
	 *
	 * @return Schema|null
	 */
	private function optedInSchema(Register $register, mixed $schemaId): ?Schema {
		try {
			$schema = $this->sources->schemas->find((int)$schemaId);
		} catch (Throwable $error) {
			$this->logger->warning('[ExpiredObjectSweep] schema #'.(string)$schemaId.' not loadable: '.$error->getMessage(), ['app' => 'openregister']);
			return null;
		}

		if (ExpiryDeclaration::of(schema: $schema) === null) {
			return null;
		}

		if ($this->sources->magic->tableExistsForRegisterSchema(register: $register, schema: $schema) === false) {
			return null;
		}

		return $schema;
	}//end optedInSchema()

	/**
	 * The base query for a table's rows with an expiry before $now.
	 *
	 * @param Register          $register The register.
	 * @param Schema            $schema   The schema.
	 * @param DateTimeImmutable $now      The moment of the run.
	 *
	 * @return IQueryBuilder
	 */
	private function expiredQuery(Register $register, Schema $schema, DateTimeImmutable $now): IQueryBuilder {
		$qb = $this->sources->db->getQueryBuilder();
		$qb->from($this->sources->magic->getTableNameForRegisterSchema(register: $register, schema: $schema))
			->where($qb->expr()->isNotNull('_expires'))
			->andWhere($qb->expr()->lt('_expires', $qb->createNamedParameter($now->format('Y-m-d H:i:s'))));

		return $qb;
	}//end expiredQuery()

	/**
	 * One object of a table by uuid, or null when it cannot be read.
	 *
	 * @param Register $register The register.
	 * @param Schema   $schema   The schema.
	 * @param string   $uuid     The uuid.
	 *
	 * @return ObjectEntity|null
	 */
	private function load(Register $register, Schema $schema, string $uuid): ?ObjectEntity {
		if ($uuid === '') {
			return null;
		}

		try {
			return $this->sources->magic->find($uuid, $register, $schema, false, false, false);
		} catch (Throwable $error) {
			$this->logger->warning('[ExpiredObjectSweep] object '.$uuid.' not loadable: '.$error->getMessage(), ['app' => 'openregister']);
			return null;
		}
	}//end load()

	/**
	 * Remove every file attached to an object.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return void
	 */
	private function removeFiles(ObjectEntity $object): void {
		foreach ($this->files->getFiles(object: $object) as $file) {
			$this->files->deleteFile(file: $file, object: $object);
		}
	}//end removeFiles()
}//end class
