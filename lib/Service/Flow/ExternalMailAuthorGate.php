<?php

/**
 * OpenRegister ExternalMailAuthorGate
 *
 * Who may store a flow graph whose send-email step mails any address. Every
 * path that persists flow nodes asks this one gate, so a sibling write path
 * cannot store the graph FlowService::save() refuses.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-send-email-external-recipients/spec.md#requirement-a-send-email-step-reaches-an-address-only-as-far-as-the-step-allows
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\SystemOperationContext;
use OCP\IGroupManager;
use OCP\IUserSession;
use Throwable;

/**
 * Judges graphs that let a send-email step reach any address.
 *
 * @spec openspec/specs/flow-send-email-external-recipients/spec.md#requirement-a-send-email-step-reaches-an-address-only-as-far-as-the-step-allows
 */
final class ExternalMailAuthorGate {

	/**
	 * Constructor.
	 *
	 * @param IUserSession|null  $userSession  The signed-in user, if any.
	 * @param IGroupManager|null $groupManager Who is an administrator.
	 * @param bool               $trustCli     Whether CLI work without a user (install, `occ`) counts as the app itself.
	 */
	public function __construct(
		private readonly ?IUserSession $userSession,
		private readonly ?IGroupManager $groupManager,
		private readonly bool $trustCli = false,
	) {
	}//end __construct()

	/**
	 * Whether any step in the graph lets mail go to any address.
	 *
	 * The whole graph is walked, nodes and edges alike, so the check does not
	 * depend on which of the two carries a step's config.
	 *
	 * @param mixed $graph The nodes and edges.
	 *
	 * @return bool True when a config holds `externalRecipients: any`.
	 *
	 * @spec openspec/specs/flow-send-email-external-recipients/spec.md#requirement-a-send-email-step-reaches-an-address-only-as-far-as-the-step-allows
	 */
	public static function mailsAnyAddress(mixed $graph): bool {
		if (is_array($graph) === false) {
			return false;
		}

		$mode = ($graph['externalRecipients'] ?? null);
		if (is_string($mode) === true && strtolower(trim($mode)) === FlowMessagingService::EXTERNAL_ANY) {
			return true;
		}

		foreach ($graph as $value) {
			if (is_array($value) === true && self::mailsAnyAddress(graph: $value) === true) {
				return true;
			}
		}

		return false;
	}//end mailsAnyAddress()

	/**
	 * Refuse unless the caller is an administrator or the app itself.
	 *
	 * @return void
	 *
	 * @throws NotAuthorizedException When a signed-in non-administrator, or nobody, is saving.
	 *
	 * @spec openspec/specs/flow-send-email-external-recipients/spec.md#requirement-a-send-email-step-reaches-an-address-only-as-far-as-the-step-allows
	 */
	public function assertAdministratorAuthor(): void {
		if (SystemOperationContext::isActive() === true) {
			return;
		}

		$user = $this->userSession?->getUser();
		if ($user === null) {
			if ($this->trustCli === true && PHP_SAPI === 'cli') {
				return;
			}

			throw new NotAuthorizedException(message: 'Only an administrator may let a flow send email to any address');
		}

		try {
			$isAdmin = ($this->groupManager?->isAdmin($user->getUID()) === true);
		} catch (Throwable $e) {
			$isAdmin = false;
		}

		if ($isAdmin === true) {
			return;
		}

		throw new NotAuthorizedException(message: 'Only an administrator may let a flow send email to any address');
	}//end assertAdministratorAuthor()
}//end class
