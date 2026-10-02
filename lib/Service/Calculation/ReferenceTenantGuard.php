<?php

/**
 * OpenRegister ReferenceTenantGuard
 *
 * Decides whether an object a calculation reference resolved to may feed the
 * calculation of the object being saved. Reference reads run without the
 * saver's RBAC and multitenancy scope (a calculation must not depend on WHO
 * saves), so this guard is what keeps one tenant's data out of another
 * tenant's calculated values.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Calculation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Calculation;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Service\SharedMasterDataService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The organisation boundary for calculation references.
 *
 * The rule mirrors what a member of the SAVING object's organisation could
 * read under MagicOrganizationHandler::resolveOrganizationScope(), with the
 * org-less widening of admitOrganisationless():
 *
 * 1. A referenced object with no organisation belongs to no tenant and is admitted.
 * 2. A referenced object in the saving object's own organisation is admitted.
 * 3. A referenced object in one of that organisation's parent organisations is admitted
 *    (OrganisationMapper::findParentChain(), the chain getUserActiveOrganisations() adds).
 * 4. A referenced object whose register or schema is shared master data, held by its
 *    organisation and shared with the saving organisation (or a parent), is admitted
 *    (SharedMasterDataService::holdersForResource(), REQ-SLE-001).
 * 5. Everything else is refused, and so is every organisation-owned object when the
 *    saving object has no organisation of its own: an org-less object has no tenant
 *    from which to claim another tenant's rows.
 *
 * @spec openspec/changes/calculations-resolve-references-regardless-of-saver/specs/computed-fields/spec.md
 */
class ReferenceTenantGuard {
	/**
	 * Wire the organisation hierarchy and the shared-master-data resolver.
	 *
	 * @param OrganisationMapper $organisations Reads an organisation's parent chain.
	 * @param SharedMasterDataService $sharedMasterData Reads declared master-data shares.
	 * @param LoggerInterface $logger PSR logger for refused references.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/calculations-resolve-references-regardless-of-saver/specs/computed-fields/spec.md
	 */
	public function __construct(
		private readonly OrganisationMapper $organisations,
		private readonly SharedMasterDataService $sharedMasterData,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether the referenced object may feed a calculation of an object in `$savingOrganisation`.
	 *
	 * @param string|null $savingOrganisation Organisation UUID of the object being saved.
	 * @param ObjectEntity $referenced The object the reference resolved to.
	 *
	 * @return bool True when the referenced object is inside the saving object's tenant scope.
	 *
	 * @spec openspec/changes/calculations-resolve-references-regardless-of-saver/specs/computed-fields/spec.md
	 */
	public function admits(?string $savingOrganisation, ObjectEntity $referenced): bool {
		$referencedOrganisation = $referenced->getOrganisation();
		if ($referencedOrganisation === null || $referencedOrganisation === '') {
			return true;
		}

		if ($savingOrganisation === null || $savingOrganisation === '') {
			return $this->refuse(savingOrganisation: '', referenced: $referenced);
		}

		if ($referencedOrganisation === $savingOrganisation) {
			return true;
		}

		$scope = $this->scopeOf(organisation: $savingOrganisation);
		if (in_array($referencedOrganisation, $scope, true) === true) {
			return true;
		}

		$holders = $this->sharedMasterData->holdersForResource(
			registerId: $this->numericId(value: $referenced->getRegister()),
			schemaId: $this->numericId(value: $referenced->getSchema()),
			consumerOrgUuids: $scope
		);
		if (in_array($referencedOrganisation, $holders, true) === true) {
			return true;
		}

		return $this->refuse(savingOrganisation: $savingOrganisation, referenced: $referenced);
	}//end admits()

	/**
	 * The saving organisation followed by its parent chain.
	 *
	 * A chain that cannot be read narrows the scope to the organisation
	 * itself: a lookup failure may cost a reference, never widen one.
	 *
	 * @param string $organisation The saving object's organisation UUID.
	 *
	 * @return array<int, string> The organisation and its parents.
	 */
	private function scopeOf(string $organisation): array {
		try {
			$parents = $this->organisations->findParentChain(organisationUuid: $organisation);
		} catch (Throwable $e) {
			$this->logger->warning(
				sprintf('Calculation reference guard: parent chain of "%s" unreadable: %s', $organisation, $e->getMessage())
			);
			$parents = [];
		}

		return array_values(array_filter(array_merge([$organisation], $parents), 'is_string'));
	}//end scopeOf()

	/**
	 * Log a refused reference and answer false.
	 *
	 * @param string $savingOrganisation The saving object's organisation ('' when it has none).
	 * @param ObjectEntity $referenced The refused object.
	 *
	 * @return bool Always false.
	 */
	private function refuse(string $savingOrganisation, ObjectEntity $referenced): bool {
		$this->logger->info(
			sprintf(
				'Calculation reference to %s (organisation "%s") refused for an object in organisation "%s".',
				(string)$referenced->getUuid(),
				(string)$referenced->getOrganisation(),
				$savingOrganisation
			)
		);

		return false;
	}//end refuse()

	/**
	 * Read a numeric register/schema id off an entity reference.
	 *
	 * @param mixed $value The stored register or schema reference.
	 *
	 * @return int|null The id, or null when the reference is not numeric.
	 */
	private function numericId(mixed $value): ?int {
		if (is_int($value) === true) {
			return $value;
		}

		if (is_string($value) === true && ctype_digit($value) === true) {
			return (int)$value;
		}

		return null;
	}//end numericId()
}//end class
