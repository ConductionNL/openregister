<?php

/**
 * OpenRegister DeleteRowRules
 *
 * The questions a direct delete asks of one stored row, for a delete that reaches a row
 * the caller did not name.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Service\DeclaredRow;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\AppendOnlyException;
use OCA\OpenRegister\Service\Archival\RecordState;
use OCA\OpenRegister\Service\OrganisationContext;
use OCA\OpenRegister\Service\OrganisationServiceInterface;
use OCA\OpenRegister\Service\SharedMasterDataService;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Container\ContainerInterface;

/**
 * Refuses deleting a row that was transferred, sits on an append-only schema, is shared
 * master data the caller's organisation only consumes, or that the caller may not delete.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Each refusal names its own exception and service.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class DeleteRowRules {

	/**
	 * What a refused delete of a transferred row says.
	 *
	 * @var string
	 */
	public const TRANSFERRED_MESSAGE = 'This object has been transferred to the e-Depot and is read-only.';

	/**
	 * Constructor.
	 *
	 * @param PermissionHandlerInterface $permissionHandler Answers the delete right.
	 * @param ContainerInterface         $container         Resolves the shared master data and organisation services on use.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		private readonly PermissionHandlerInterface $permissionHandler,
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * Refuse deleting this row the way a direct delete of it is refused.
	 *
	 * @param ObjectEntity   $row    The stored row.
	 * @param RegisterSchema $table  The register and schema the row lives in.
	 * @param DeleteCaller   $caller The acting user and the access flags.
	 *
	 * @return void
	 *
	 * @throws DoesNotExistException When the row was transferred.
	 * @throws AppendOnlyException When the schema is append-only.
	 * @throws \Exception When the row is consumed shared master data, or the caller may not delete it.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) OrganisationContext is the static scope of an acting organisation.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function assertDeletable(ObjectEntity $row, RegisterSchema $table, DeleteCaller $caller): void {
		if (self::isTransferred(object: $row) === true) {
			throw new DoesNotExistException('OBJECT_TRANSFERRED: '.self::TRANSFERRED_MESSAGE);
		}

		if ($table->schema->isAppendOnly() === true) {
			throw self::appendOnlyRefusal(schema: $table->schema);
		}

		if ($caller->rbac === true || OrganisationContext::multitenancy(requested: $caller->multitenancy) === true) {
			$this->assertNotConsumed(table: $table);
		}

		$this->permissionHandler->checkPermission(
			schema: $table->schema,
			action: 'delete',
			options: new PermissionCheckOptions(userId: $caller->userId, objectOwner: $row->getOwner(), object: $row),
			_rbac: $caller->rbac
		);
	}//end assertDeletable()

	/**
	 * Whether an object has been transferred to the e-Depot.
	 *
	 * Both vocabularies count: stored data is not migrated, so an install that
	 * transferred a record before GAP A4 holds `overgebracht`.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public static function isTransferred(ObjectEntity $object): bool {
		$retention = ($object->getRetention() ?? []);

		return in_array(($retention['archiefstatus'] ?? ''), RecordState::TRANSFERRED_ALIASES, true);
	}//end isTransferred()

	/**
	 * The refusal of a delete on an append-only schema.
	 *
	 * @param Schema $schema The append-only schema.
	 *
	 * @return AppendOnlyException
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public static function appendOnlyRefusal(Schema $schema): AppendOnlyException {
		return new AppendOnlyException(
			schemaIdentifier: ($schema->getSlug() ?? (string)$schema->getId()),
			operation: 'delete'
		);
	}//end appendOnlyRefusal()

	/**
	 * Refuse a row in a register or schema the caller's organisation only consumes.
	 *
	 * No resolvable organisation means no share can be consumed, as for a direct delete.
	 *
	 * @param RegisterSchema $table The row's register and schema.
	 *
	 * @return void
	 *
	 * @throws \OCA\OpenRegister\Exception\SharedMasterDataWriteException When either is only consumed.
	 */
	private function assertNotConsumed(RegisterSchema $table): void {
		try {
			$shared        = $this->container->get(SharedMasterDataService::class);
			$organisations = $this->container->get(OrganisationServiceInterface::class)->getUserActiveOrganisations();
		} catch (\Throwable $e) {
			return;
		}

		$registerRow = new DeclaredRow(table: SharedMasterDataService::REGISTERS, id: $table->register->getId());
		$schemaRow   = new DeclaredRow(table: SharedMasterDataService::SCHEMAS, id: $table->schema->getId());
		$shared->assertWritable(row: $registerRow, activeOrgUuids: $organisations);
		$shared->assertWritable(row: $schemaRow, activeOrgUuids: $organisations);
	}//end assertNotConsumed()
}//end class
