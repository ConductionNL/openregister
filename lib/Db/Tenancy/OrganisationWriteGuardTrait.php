<?php

/**
 * OrganisationWriteGuardTrait: the organisation and owner a write stamps, and the refusal of a cross-tenant write.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Trait
 * @package  OCA\OpenRegister\Db\Tenancy
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Db\Tenancy;

use Exception;
use OCA\OpenRegister\Exception\SharedMasterDataWriteException;
use OCA\OpenRegister\Service\SharedMasterDataService;
use OCP\AppFramework\Db\Entity;
use OCP\IAppConfig;
use Symfony\Component\HttpFoundation\Response;

/**
 * The write half of MultiTenancyTrait.
 *
 * Every probe of an entity column here is property_exists(), NOT
 * method_exists(): Nextcloud's Entity serves getters and setters through
 * __call, declaring them only as `@method`, and method_exists() is FALSE for
 * those. Every entity in lib/Db declares its accessors that way, so a
 * method_exists() guard returned early for every entity and silently turned
 * the guard it protected into a no-op (openregister#2833).
 *
 * Composed only by MultiTenancyTrait; a mapper uses that trait, never this one.
 * Deliberately without strict_types, like the trait it was split from, so the
 * moved code coerces exactly as it always did.
 *
 * @package OCA\OpenRegister\Db\Tenancy
 */
trait OrganisationWriteGuardTrait {

	/**
	 * Set organisation on entity during creation.
	 *
	 * SECURITY: Always uses the active organisation from the session and ignores
	 * frontend input; falls back to the configured default organisation only
	 * when there is no active organisation and the entity carries none.
	 *
	 * @param Entity $entity The entity being created
	 *
	 * @return void
	 */
	protected function setOrganisationOnCreate(Entity $entity): void {
		if (property_exists($entity, 'organisation') === false) {
			return;
		}

		$activeOrgUuid = $this->getActiveOrganisationUuid();
		if ($activeOrgUuid !== null) {
			$entity->setOrganisation($activeOrgUuid);
			return;
		}

		if ($entity->getOrganisation() === null && isset($this->appConfig) === true) {
			$this->setDefaultOrganisation(entity: $entity, appConfig: $this->appConfig);
		}
	}//end setOrganisationOnCreate()

	/**
	 * Stamp the configured default organisation, when one is configured.
	 *
	 * @param Entity     $entity    The entity being created
	 * @param IAppConfig $appConfig The host's app config.
	 *
	 * @return void
	 */
	private function setDefaultOrganisation(Entity $entity, IAppConfig $appConfig): void {
		$defaultOrgUuid = $appConfig->getValueString('openregister', 'defaultOrganisation', '');
		if (empty($defaultOrgUuid) === false) {
			$entity->setOrganisation($defaultOrgUuid);
		}
	}//end setDefaultOrganisation()

	/**
	 * Set the owner on an entity during creation, unless one is already set.
	 *
	 * Services that care set the owner explicitly (SaveObject, FlowService,
	 * ViewService, OrganisationService, …); this covers anything created
	 * straight through a mapper — repair steps, imports, new code — which would
	 * otherwise get a NULL owner in the one field half the private-scope
	 * predicate is built on (owner OR admin OR granted).
	 *
	 * @param Entity $entity The entity being created
	 *
	 * @return void
	 */
	protected function setOwnerOnCreate(Entity $entity): void {
		if (property_exists($entity, 'owner') === false || $this->hasOwner(entity: $entity) === true) {
			return;
		}

		// Get current user from session.
		if (isset($this->userSession) === false) {
			return;
		}

		$user = $this->userSession->getUser();
		if ($user !== null) {
			// @phpstan-ignore-next-line Entity::setOwner() is dispatched via __call.
			$entity->setOwner($user->getUID());
		}
	}//end setOwnerOnCreate()

	/**
	 * Whether the entity already names an owner (an explicit assignment wins).
	 *
	 * The parameter is typed `Entity`, whose owner accessors exist only as
	 * `@method` on the subclasses, so static analysis cannot see them; the
	 * caller's property_exists() guard is what makes the call safe at runtime.
	 *
	 * @param Entity $entity The entity being created
	 *
	 * @return bool
	 */
	private function hasOwner(Entity $entity): bool {
		// @phpstan-ignore-next-line Entity::getOwner() is dispatched via __call.
		return $entity->getOwner() !== null && $entity->getOwner() !== '';
	}//end hasOwner()

	/**
	 * Verify that an entity belongs to the active organisation.
	 *
	 * Throws an exception if the entity's organisation doesn't match
	 * the active organisation. This applies to ALL users including admins.
	 *
	 * @param Entity $entity The entity to verify
	 *
	 * @return void
	 *
	 * @throws \Exception If organisation doesn't match
	 */
	protected function verifyOrganisationAccess(Entity $entity): void {
		if (property_exists($entity, 'organisation') === false) {
			return;
		}

		// @phpstan-ignore-next-line Entity::getOrganisation() is dispatched via __call.
		$entityOrgUuid = $entity->getOrganisation();
		$activeOrgUuid = $this->getActiveOrganisationUuid();

		// An entity without an organisation is allowed; a match is allowed for everyone, admins included.
		if ($entityOrgUuid === null || $entityOrgUuid === $activeOrgUuid) {
			return;
		}

		// Shared master data (REQ-SLE-001, design D-2): a narrowing of this same
		// refusal, never a widening. It throws a refusal that NAMES THE HOLDER,
		// or returns, and then the generic violation below still fires.
		$this->refuseSharedMasterDataWrite(entity: $entity);
		$this->logCrossTenantDenial(entity: $entity, activeOrgUuid: $activeOrgUuid, entityOrgUuid: $entityOrgUuid);

		$message = 'Security violation: You do not have permission to access this resource from a different organisation.';
		throw new Exception($message, Response::HTTP_FORBIDDEN);
	}//end verifyOrganisationAccess()

	/**
	 * Audit-log a refused cross-tenant write.
	 *
	 * REQ-SLE-003: the organisation UUID and a pseudonymous actor reference.
	 * The refusal is the fact worth recording; the person's login name is not,
	 * and on a shared back office it is a disclosure to the other legal entity.
	 *
	 * @param Entity      $entity        The entity being written.
	 * @param string|null $activeOrgUuid The caller's organisation.
	 * @param string      $entityOrgUuid The entity's organisation.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/several-legal-entities-in-one-instance/specs/tenant-isolation-audit/spec.md#requirement-a-log-line-names-the-tenant-pseudonymously-and-never-carries-a-secret-req-sle-003
	 */
	private function logCrossTenantDenial(Entity $entity, ?string $activeOrgUuid, string $entityOrgUuid): void {
		if (isset($this->logger) === false) {
			return;
		}

		$context = [
			'type' => 'cross_tenant_access_denied',
			'actor' => $this->getCurrentUserId(),
			'sourceOrganisation' => $activeOrgUuid,
			'targetOrganisation' => $entityOrgUuid,
			'entityType' => get_class($entity),
			'entityId' => $entity->getId(),
		];
		$this->logRedacted(level: 'warning', message: '[MultiTenancyTrait] Cross-tenant access denied', context: $context);
	}//end logCrossTenantDenial()

	/**
	 * Refuse, naming the holder, a consumer's write to shared master data.
	 *
	 * Reached only from verifyOrganisationAccess() once the organisations
	 * already differ; for a row the caller does not consume it returns and the
	 * caller meets the generic cross-tenant violation. The write about to happen
	 * may BE a declaration change (`shared_with` lives on the same row), so the
	 * resolver's per-request cache is dropped once the write is let through.
	 *
	 * @param Entity $entity The register or schema being written.
	 *
	 * @return void
	 *
	 * @throws SharedMasterDataWriteException When the caller is a declared consumer.
	 *
	 * @spec openspec/changes/several-legal-entities-in-one-instance/specs/saas-multi-tenant/spec.md#requirement-a-register-or-schema-may-be-shared-master-data-across-organisations-req-sle-001
	 */
	private function refuseSharedMasterDataWrite(Entity $entity): void {
		$table = $this->getTableName();
		if ($this->isSharedMasterDataTable(table: $table) === false) {
			return;
		}

		$resolver = $this->sharedMasterData();
		$activeOrgUuids = $this->getActiveOrganisationUuids();
		$entityId = $entity->getId();
		if (is_int($entityId) === false) {
			return;
		}

		$this->assertSharedMasterDataWritable(resolver: $resolver, table: $table, entityId: $entityId, activeOrgUuids: $activeOrgUuids);
		$resolver->clearCache();
	}//end refuseSharedMasterDataWrite()

	/**
	 * Let the resolver refuse the write, logging the refusal before it propagates.
	 *
	 * @param SharedMasterDataService $resolver       The resolver.
	 * @param string                  $table          The register or schema table.
	 * @param int                     $entityId       The row being written.
	 * @param array                   $activeOrgUuids The caller's organisation and its parents.
	 *
	 * @return void
	 *
	 * @throws SharedMasterDataWriteException When the caller is a declared consumer.
	 */
	private function assertSharedMasterDataWritable(
		SharedMasterDataService $resolver,
		string $table,
		int $entityId,
		array $activeOrgUuids,
	): void {
		try {
			$resolver->assertWritable(table: $table, id: $entityId, activeOrgUuids: $activeOrgUuids);
		} catch (SharedMasterDataWriteException $refusal) {
			$this->logSharedMasterDataRefusal(refusal: $refusal, entityId: $entityId);
			throw $refusal;
		}
	}//end assertSharedMasterDataWritable()

	/**
	 * Log a refused shared master data write, pseudonymously (REQ-SLE-003).
	 *
	 * @param SharedMasterDataWriteException $refusal  The refusal.
	 * @param int                            $entityId The row being written.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/several-legal-entities-in-one-instance/specs/tenant-isolation-audit/spec.md#requirement-a-log-line-names-the-tenant-pseudonymously-and-never-carries-a-secret-req-sle-003
	 */
	private function logSharedMasterDataRefusal(SharedMasterDataWriteException $refusal, int $entityId): void {
		if (isset($this->logger) === false) {
			return;
		}

		$this->logRedacted(level: 'warning', message: '[MultiTenancyTrait] Shared master data is read-only to a consumer', context: [
			'type' => 'shared_master_data_write_denied',
			'sourceOrganisation' => $this->getActiveOrganisationUuid(),
			'holderOrganisation' => $refusal->getHolderUuid(),
			'resourceType' => $refusal->getResourceType(),
			'entityId' => $entityId,
			'actor' => $this->getCurrentUserId(),
			'file' => __FILE__,
			'line' => __LINE__,
		]);
	}//end logSharedMasterDataRefusal()
}//end trait
