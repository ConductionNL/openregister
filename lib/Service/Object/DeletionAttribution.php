<?php

/**
 * OpenRegister DeletionAttribution
 *
 * Who deletes an object, on behalf of which organisation, and the deleted metadata that records it.
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
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */

namespace OCA\OpenRegister\Service\Object;

use DateTime;
use DateTimeImmutable;
use OCA\OpenRegister\Db\OrganisationMapperInterface;
use OCA\OpenRegister\Service\Deletion\DeletionWindowService;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Resolves the deleting user and organisation, and stamps the deleted metadata of a soft delete.
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */
final class DeletionAttribution {

	/**
	 * Constructor.
	 *
	 * @param IUserSession                $userSession        The session of the deleting user.
	 * @param OrganisationMapperInterface $organisationMapper Resolves the user's active organisation.
	 * @param LoggerInterface             $logger             Records an organisation lookup that failed.
	 * @param DeletionWindowService|null  $windowService      Resolves and states the recovery window.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly OrganisationMapperInterface $organisationMapper,
		private readonly LoggerInterface $logger,
		private readonly ?DeletionWindowService $windowService = null,
	) {
	}//end __construct()

	/**
	 * The deleted metadata of a soft delete: the session user, the moment, the user's active
	 * organisation and, when a window service is wired, the stated recovery window.
	 *
	 * @param DeletionTarget $target The object being deleted.
	 *
	 * @return array<string, mixed> The deleted metadata.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function deletedMetadata(DeletionTarget $target): array {
		$user = $this->userSession->getUser();
		$userId = 'system';
		if ($user !== null) {
			$userId = $user->getUID();
		}

		// The active organisation of the deleting user. Error is caught too, so a missing
		// database never aborts the delete.
		$activeOrganisation = null;
		if ($user !== null) {
			try {
				$activeOrganisation = $this->organisationMapper->getActiveOrganisationWithFallback($user->getUID());
			} catch (\Throwable $e) {
				$this->logger->warning(
					message: '[DeleteObject] Failed to get active organisation during delete',
					context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
				);
			}
		}

		\OCA\OpenRegister\Service\WritePhaseProbe::stamp('del.org');

		$deletedAt = new DateTime();
		$deletionData = [
			'deletedBy' => $userId,
			'deletedAt' => $deletedAt->format(DateTime::ATOM),
			'objectId' => $target->object->getUuid(),
			'organisation' => $activeOrganisation,
		];

		if ($this->windowService === null) {
			return $deletionData;
		}

		return array_merge(
			$deletionData,
			$this->windowService->openWindow(
				schema: $target->schema,
				deletedAt: DateTimeImmutable::createFromMutable($deletedAt)
			)
		);
	}//end deletedMetadata()

	/**
	 * The current user id ('system' without a session user) and the user's active organisation.
	 *
	 * @return array{0: string, 1: mixed} [userId, activeOrganisation]
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function actingUser(): array {
		$user = $this->userSession->getUser();
		$userId = 'system';
		$org = null;

		if ($user !== null) {
			$userId = $user->getUID();
			try {
				$org = $this->organisationMapper->getActiveOrganisationWithFallback($user->getUID());
			} catch (\Exception $e) {
				$org = null;
			}
		}

		return [$userId, $org];
	}//end actingUser()
}//end class
