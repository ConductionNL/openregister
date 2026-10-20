<?php

/**
 * OpenRegister RecordTemplateMapper
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md#requirement-a-user-can-save-and-share-a-named-record-template
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use InvalidArgumentException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Symfony\Component\Uid\Uuid;

/**
 * Stores record templates in `openregister_record_templates`.
 *
 * The mapper answers rows; WHO may use one is decided by
 * RecordTemplateService, the same split a saved view has between its mapper
 * and ViewShareResolver.
 *
 * @method RecordTemplate findEntity(IQueryBuilder $query)
 * @method list<RecordTemplate> findEntities(IQueryBuilder $query)
 *
 * @template-extends QBMapper<RecordTemplate>
 *
 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md#requirement-a-user-can-save-and-share-a-named-record-template
 */
class RecordTemplateMapper extends QBMapper {

	/**
	 * The table.
	 */
	public const TABLE = 'openregister_record_templates';

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 *
	 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md#requirement-a-user-can-save-and-share-a-named-record-template
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: self::TABLE, entityClass: RecordTemplate::class);
	}//end __construct()

	/**
	 * One template by its uuid.
	 *
	 * @param string $uuid The template uuid.
	 *
	 * @return RecordTemplate The template.
	 *
	 * @throws DoesNotExistException When no template has that uuid.
	 *
	 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md#requirement-a-user-can-save-and-share-a-named-record-template
	 */
	public function findByUuid(string $uuid): RecordTemplate {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid, IQueryBuilder::PARAM_STR)));

		return $this->findEntity(query: $qb);
	}//end findByUuid()

	/**
	 * The templates a caller might reach: their own, the public ones and every
	 * shared one, optionally for one register and schema.
	 *
	 * The SQL narrows; the share itself is judged in PHP by the caller's groups,
	 * as for saved views, because a template list is tens of rows, and one
	 * reading of `sharedWith` is safer than four JSON dialects agreeing on an
	 * authorization question.
	 *
	 * @param string      $userId   The caller.
	 * @param string|null $register Only templates for this register id.
	 * @param string|null $schema   Only templates for this schema id.
	 *
	 * @return RecordTemplate[] The candidates, newest first.
	 *
	 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md#requirement-a-user-can-save-and-share-a-named-record-template
	 */
	public function findCandidates(string $userId, ?string $register=null, ?string $schema=null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->orX(
					$qb->expr()->eq('owner', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)),
					$qb->expr()->eq('is_public', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)),
					$qb->expr()->isNotNull('shared_with')
				)
			)
			->orderBy('created', 'DESC');

		if ($register !== null && $register !== '') {
			$qb->andWhere($qb->expr()->eq('register', $qb->createNamedParameter($register, IQueryBuilder::PARAM_STR)));
		}

		if ($schema !== null && $schema !== '') {
			$qb->andWhere($qb->expr()->eq('schema', $qb->createNamedParameter($schema, IQueryBuilder::PARAM_STR)));
		}

		return $this->findEntities(query: $qb);
	}//end findCandidates()

	/**
	 * Store a new template with its uuid and timestamps.
	 *
	 * @param Entity $entity The template.
	 *
	 * @return RecordTemplate The stored template.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) Uuid::v4 is the standard Symfony UID pattern.
	 *
	 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md#requirement-a-user-can-save-and-share-a-named-record-template
	 */
	public function insert(Entity $entity): RecordTemplate {
		$entity = $this->template(entity: $entity);
		if (empty($entity->getUuid()) === true) {
			$entity->setUuid((string)Uuid::v4());
		}

		$entity->setCreated(new DateTime());
		$entity->setUpdated(new DateTime());

		return parent::insert(entity: $entity);
	}//end insert()

	/**
	 * Store a changed template with a new `updated`.
	 *
	 * @param Entity $entity The template.
	 *
	 * @return RecordTemplate The stored template.
	 *
	 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md#requirement-a-user-can-save-and-share-a-named-record-template
	 */
	public function update(Entity $entity): RecordTemplate {
		$entity = $this->template(entity: $entity);
		$entity->setUpdated(new DateTime());

		return parent::update(entity: $entity);
	}//end update()

	/**
	 * The entity as a template, or a refusal for anything else.
	 *
	 * @param Entity $entity The entity handed in.
	 *
	 * @return RecordTemplate The template.
	 *
	 * @throws InvalidArgumentException When it is not a record template.
	 */
	private function template(Entity $entity): RecordTemplate {
		if ($entity instanceof RecordTemplate === false) {
			throw new InvalidArgumentException('RecordTemplateMapper stores record templates only.');
		}

		return $entity;
	}//end template()
}//end class
