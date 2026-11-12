<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Whether a share's creator still holds the rights the share hands out.
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
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use RuntimeException;
use Throwable;

/**
 * Judges a share as its creator, on every use and not only when it was made.
 *
 * The creator is made the active user for the duration of the check only, with
 * `setVolatileActiveUser()`, so no session is written and the previous user is
 * restored. A share without a recorded creator, or whose creator no longer
 * exists, is refused.
 *
 * @spec openspec/specs/federation-scope-enforcement/spec.md#requirement-a-share-never-grants-more-than-its-creator-holds-req-fse-005
 */
class ShareCreatorCheck {

	/**
	 * Constructor.
	 *
	 * @param ShareGrantorInterface $grantor     Judges a share for the active user.
	 * @param IUserManager          $userManager Finds a share's creator.
	 * @param IUserSession          $userSession Makes the creator the active user while the share is judged.
	 */
	public function __construct(
		private readonly ShareGrantorInterface $grantor,
		private readonly IUserManager $userManager,
		private readonly IUserSession $userSession,
	) {
	}//end __construct()

	/**
	 * Why the share's creator may not hand out this share now, or null when they still may.
	 *
	 * @param FederatedShare $share The share.
	 *
	 * @return string|null The refusal.
	 *
	 * @spec openspec/specs/federation-scope-enforcement/spec.md#requirement-a-share-never-grants-more-than-its-creator-holds-req-fse-005
	 */
	public function refusal(FederatedShare $share): ?string {
		$owner = (string)$share->getOwner();
		if ($owner === '') {
			return 'creator not recorded (created before creators were recorded)';
		}

		$creator = $this->userManager->get($owner);
		if ($creator === null) {
			return 'creator no longer exists';
		}

		try {
			return $this->runAs(creator: $creator, check: fn (): ?string => $this->grantor->refusal(share: $share));
		} catch (Throwable $e) {
			return 'the creator\'s rights could not be checked';
		}
	}//end refusal()

	/**
	 * Run a check with the share's creator as the active user.
	 *
	 * @param FederatedShare $share The share.
	 * @param callable       $check The check, run without arguments.
	 *
	 * @return mixed What the check returns.
	 *
	 * @throws RuntimeException When the share has no creator that exists.
	 *
	 * @spec openspec/specs/federation-scope-enforcement/spec.md#requirement-a-share-never-grants-more-than-its-creator-holds-req-fse-005
	 */
	public function asCreator(FederatedShare $share, callable $check): mixed {
		$owner = (string)$share->getOwner();
		$creator = null;
		if ($owner !== '') {
			$creator = $this->userManager->get($owner);
		}

		if ($creator === null) {
			throw new RuntimeException('The share has no creator to judge it as');
		}

		return $this->runAs(creator: $creator, check: $check);
	}//end asCreator()

	/**
	 * Run a check as a user, restoring the active user afterwards.
	 *
	 * @param IUser    $creator The user.
	 * @param callable $check   The check.
	 *
	 * @return mixed What the check returns.
	 */
	private function runAs(IUser $creator, callable $check): mixed {
		$previous = $this->userSession->getUser();
		$this->userSession->setVolatileActiveUser($creator);
		try {
			return $check();
		} finally {
			$this->userSession->setVolatileActiveUser($previous);
		}
	}//end runAs()
}//end class
