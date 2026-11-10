<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The signed-in user who grants a federated share: who they are, which organisation they act for,
 * and whether they hold the rights the share hands out.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Federation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/federation-scope-enforcement/spec.md#requirement-a-share-never-grants-more-than-its-creator-holds-req-fse-005
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Federation;

use OCA\OpenRegister\Db\FederatedShare;
use OCA\OpenRegister\Service\OrganisationServiceInterface;
use OCA\OpenRegister\Service\Rbac\SchemaManageGate;
use OCP\IUserSession;

/**
 * A token is served without access control, so it may only hand out what its creator holds.
 *
 * - An object-scope read share needs the creator's own read of that object.
 * - Every other share (a whole register, schema or query, or any read-write share) needs the
 *   right to manage the schema, or the register for a register share. Reading a schema is not
 *   enough: conditional rules and per-object rules limit a reader record by record, and a token
 *   serves every record of its scope.
 *
 * @spec openspec/specs/federation-scope-enforcement/spec.md#requirement-a-share-never-grants-more-than-its-creator-holds-req-fse-005
 */
class ShareGrantor implements ShareGrantorInterface {

	/**
	 * Constructor.
	 *
	 * @param IUserSession        $userSession         The caller.
	 * @param OrganisationServiceInterface $organisationService The caller's active organisation.
	 * @param SchemaManageGate    $manageGate          Who may manage a schema or register.
	 * @param ShareTargets        $targets             The share's targets, as serving resolves them.
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly OrganisationServiceInterface $organisationService,
		private readonly SchemaManageGate $manageGate,
		private readonly ShareTargets $targets,
	) {
	}//end __construct()

	/**
	 * The signed-in user's id, or null without a session.
	 *
	 * @return string|null The user id.
	 *
	 * @spec openspec/specs/federation-scope-enforcement/spec.md#requirement-a-share-never-grants-more-than-its-creator-holds-req-fse-005
	 */
	public function userId(): ?string {
		return $this->userSession->getUser()?->getUID();
	}//end userId()

	/**
	 * The uuid of the organisation the caller acts for, or null when there is none.
	 *
	 * @return string|null The organisation uuid.
	 *
	 * @spec openspec/specs/federation-scope-enforcement/spec.md#requirement-a-share-never-grants-more-than-its-creator-holds-req-fse-005
	 */
	public function organisation(): ?string {
		return $this->organisationService->getActiveOrganisation()?->getUuid();
	}//end organisation()

	/**
	 * Why the caller may not grant this share, or null when they hold every right it hands out.
	 *
	 * @param FederatedShare $share The share to grant, or one already granted.
	 *
	 * @return string|null The refusal, naming the right that is missing.
	 *
	 * @spec openspec/specs/federation-scope-enforcement/spec.md#requirement-a-share-never-grants-more-than-its-creator-holds-req-fse-005
	 */
	public function refusal(FederatedShare $share): ?string {
		if ($this->userId() === null) {
			return 'Sharing needs a signed-in user';
		}

		if ($share->getScope() === 'register') {
			return $this->registerRefusal(share: $share);
		}

		if ($share->getScope() === 'object' && $share->getPermissions() === 'read') {
			return $this->objectReadRefusal(share: $share);
		}

		$schema = $this->targets->schema(share: $share);
		if ($schema === null || $this->manageGate->mayManage(schema: $schema) === false) {
			return 'This share needs the right to manage its schema';
		}

		return null;
	}//end refusal()

	/**
	 * Why the caller may not change an existing share, or null when they may.
	 *
	 * An administrator always may, so a share whose register or schema has since gone can still be revoked.
	 *
	 * @param FederatedShare $share The stored share.
	 *
	 * @return string|null The refusal.
	 *
	 * @spec openspec/specs/federation-scope-enforcement/spec.md#requirement-a-share-never-grants-more-than-its-creator-holds-req-fse-005
	 */
	public function changeRefusal(FederatedShare $share): ?string {
		if ($this->manageGate->callerIsAdministrator() === true) {
			return null;
		}

		return $this->refusal(share: $share);
	}//end changeRefusal()

	/**
	 * Why the caller may not share a whole register, or null when they manage it.
	 *
	 * @param FederatedShare $share The register share.
	 *
	 * @return string|null The refusal.
	 */
	private function registerRefusal(FederatedShare $share): ?string {
		$register = $this->targets->register(share: $share);
		if ($register === null || $this->manageGate->mayManageRegister(register: $register) === false) {
			return 'Sharing a whole register needs the right to manage that register';
		}

		return null;
	}//end registerRefusal()

	/**
	 * Why the caller may not share one object for reading, or null when they read it.
	 *
	 * @param FederatedShare $share The object read share.
	 *
	 * @return string|null The refusal.
	 */
	private function objectReadRefusal(FederatedShare $share): ?string {
		if ($this->targets->callerReadsObject(share: $share) === false) {
			return 'Sharing an object needs your own read of that object';
		}

		return null;
	}//end objectReadRefusal()
}//end class
