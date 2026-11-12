<?php

/**
 * Mapper for `oc_openregister_verwerkingsactiviteiten`.
 *
 * Standard `QBMapper` round-trip plus three lookup helpers used by
 * the audit-trail trigger contract:
 *   - `findByUuid()` — direct UUID lookup, returns null on miss.
 *   - `findByCode()` — short readable key lookup.
 *   - `resolveReference()` — unified `code` -> `uuid` resolver used
 *      when reading the schema/register annotation
 *      `x-openregister-processing-activity` so operators can write
 *      either form.
 *
 * Per-tenant isolation is applied to listing queries via the optional
 * `$organisationId` argument; single-row finds bypass the filter so
 * audit hooks can resolve activities defined outside the current
 * tenant when an explicit reference is supplied.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use InvalidArgumentException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * Mapper class for Verwerkingsactiviteit rows.
 *
 * @template-extends QBMapper<Verwerkingsactiviteit>
 */
class VerwerkingsactiviteitMapper extends QBMapper implements VerwerkingsactiviteitMapperInterface {
	use SelectByColumnTrait;

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(
			db: $db,
			tableName: 'openregister_verwerkingsactiviteiten',
			entityClass: Verwerkingsactiviteit::class
		);

	}//end __construct()

	/**
	 * Find by primary key.
	 *
	 * @param int $id Primary key.
	 *
	 * @return Verwerkingsactiviteit
	 *
	 * @throws DoesNotExistException When no row matches the id.
	 */
	public function find(int $id): Verwerkingsactiviteit {
		return $this->findEntity(query: $this->selectWhere(column: new LinkColumn(name: 'id', value: $id)));
	}//end find()

	/**
	 * Find by uuid.
	 *
	 * @param string $uuid The activity uuid.
	 *
	 * @return Verwerkingsactiviteit|null Null when no row matches.
	 */
	public function findByUuid(string $uuid): ?Verwerkingsactiviteit {
		$found = $this->findOneByFilledColumn(name: 'uuid', value: $uuid);
		if ($found instanceof Verwerkingsactiviteit) {
			return $found;
		}

		return null;
	}//end findByUuid()

	/**
	 * Find by short readable code.
	 *
	 * @param string $code The activity code.
	 *
	 * @return Verwerkingsactiviteit|null Null when no row matches.
	 */
	public function findByCode(string $code): ?Verwerkingsactiviteit {
		$found = $this->findOneByFilledColumn(name: 'code', value: $code);
		if ($found instanceof Verwerkingsactiviteit) {
			return $found;
		}

		return null;
	}//end findByCode()

	/**
	 * Resolve a `code|uuid` reference to a concrete entity.
	 *
	 * Tries `code` first, falls back to `uuid`. Used by the audit-trail
	 * trigger contract to honour `x-openregister-processing-activity`
	 * annotations regardless of which form operators chose to write.
	 *
	 * @param string $reference The reference string (code or uuid).
	 *
	 * @return Verwerkingsactiviteit|null
	 */
	public function resolveReference(string $reference): ?Verwerkingsactiviteit {
		if ($reference === '') {
			return null;
		}

		$byCode = $this->findByCode(code: $reference);
		if ($byCode !== null) {
			return $byCode;
		}

		return $this->findByUuid(uuid: $reference);
	}//end resolveReference()

	/**
	 * List all activities, optionally filtered by tenant or status.
	 *
	 * @param string|null $organisationId Optional multi-tenant filter.
	 * @param string|null $status Optional status filter.
	 *
	 * @return Verwerkingsactiviteit[]
	 */
	public function findAll(?string $organisationId = null, ?string $status = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName());

		if ($organisationId !== null && $organisationId !== '') {
			$qb->andWhere(
				$qb->expr()->eq('organisation_id', $qb->createNamedParameter($organisationId))
			);
		}

		if ($status !== null && $status !== '') {
			$qb->andWhere(
				$qb->expr()->eq('status', $qb->createNamedParameter($status))
			);
		}

		$qb->orderBy('name', 'ASC');

		return $this->findEntities(query: $qb);
	}//end findAll()

	/**
	 * Insert with vocabulary validation.
	 *
	 * Auto-fills `uuid`, `created`, `updated`, and `status` when blank.
	 *
	 * @param Verwerkingsactiviteit $entity Entity to insert.
	 *
	 * @return Verwerkingsactiviteit Persisted entity with id populated.
	 *
	 * @throws InvalidArgumentException When `legalBasis` is unset/invalid
	 *                                  or `name`/`purpose` are blank.
	 */
	public function insert($entity): Verwerkingsactiviteit {
		$this->validate(entity: $entity);

		$stamps = new NewRowStamps();
		$stamps->fillBlankUuid(entity: $entity);

		if ($entity->getStatus() === null || $entity->getStatus() === '') {
			$entity->setStatus('concept');
		}

		$stamps->stampCreatedAndUpdated(entity: $entity);

		return parent::insert(entity: $entity);
	}//end insert()

	/**
	 * Update with vocabulary validation. Bumps `updated`.
	 *
	 * @param Verwerkingsactiviteit $entity Entity to update.
	 *
	 * @return Verwerkingsactiviteit
	 */
	public function update($entity): Verwerkingsactiviteit {
		$this->validate(entity: $entity);
		$entity->setUpdated(new DateTime());
		return parent::update(entity: $entity);
	}//end update()

	/**
	 * Validate the entity's required fields + controlled vocabularies.
	 *
	 * @param Verwerkingsactiviteit $entity Entity to validate.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When validation fails.
	 */
	private function validate(Verwerkingsactiviteit $entity): void {
		$this->requireText(value: $entity->getName(), refusal: 'Verwerkingsactiviteit MUST have a name (AVG Art 30 §1(a))');
		$this->requireText(value: $entity->getPurpose(), refusal: 'Verwerkingsactiviteit MUST have a purpose (AVG Art 30 §1(b))');
		$this->requireKnownVocabulary(entity: $entity);
	}//end validate()

	/**
	 * Refuse a required text field that is unset or blank.
	 *
	 * @param mixed  $value   The field value.
	 * @param string $refusal The refusal message.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the value is null or only whitespace.
	 */
	private function requireText(mixed $value, string $refusal): void {
		if ($value === null || trim((string)$value) === '') {
			throw new InvalidArgumentException($refusal);
		}
	}//end requireText()

	/**
	 * Refuse a legal basis outside the AVG Art 6 vocabulary (an unset one included),
	 * then a set status outside the lifecycle vocabulary (an unset or empty status passes).
	 *
	 * @param Verwerkingsactiviteit $entity Entity to validate.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the legal basis or the status is not in its vocabulary.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	private function requireKnownVocabulary(Verwerkingsactiviteit $entity): void {
		if (Verwerkingsactiviteit::isValidLegalBasis($entity->getLegalBasis()) === false) {
			throw new InvalidArgumentException($this->legalBasisRefusal(entity: $entity));
		}

		if ($entity->getStatus() !== null
			&& $entity->getStatus() !== ''
			&& Verwerkingsactiviteit::isValidStatus($entity->getStatus()) === false
		) {
			throw new InvalidArgumentException($this->statusRefusal(entity: $entity));
		}
	}//end requireKnownVocabulary()

	/**
	 * The refusal of a legal basis outside the AVG Art 6 vocabulary.
	 *
	 * @param Verwerkingsactiviteit $entity The refused entity.
	 *
	 * @return string The message.
	 */
	private function legalBasisRefusal(Verwerkingsactiviteit $entity): string {
		return sprintf(
			'Invalid legalBasis "%s"; expected one of: %s (AVG Art 6)',
			(string)$entity->getLegalBasis(),
			implode(', ', Verwerkingsactiviteit::LEGAL_BASIS_VOCABULARY)
		);
	}//end legalBasisRefusal()

	/**
	 * The refusal of a status outside the lifecycle vocabulary.
	 *
	 * @param Verwerkingsactiviteit $entity The refused entity.
	 *
	 * @return string The message.
	 */
	private function statusRefusal(Verwerkingsactiviteit $entity): string {
		return sprintf(
			'Invalid status "%s"; expected one of: %s',
			(string)$entity->getStatus(),
			implode(', ', Verwerkingsactiviteit::STATUS_VOCABULARY)
		);
	}//end statusRefusal()
}//end class
