<?php

/**
 * OpenRegister AppHost — Store install action authorizer.
 *
 * Resolves an ADR-023 action check against the LEAF app that declared it, so a
 * store may declare `"installAuth": "action:catalog.instantiate"` and get
 * integriq's own authorization matrix without OpenRegister depending on
 * integriq.
 *
 * Also answers who may PUBLISH through the plane. That is the consuming app's
 * decision: it names the groups on its descriptor. The plane only enforces
 * that at least one group is named.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Store
 * @package  OCA\OpenRegister\AppHost\Store
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost\Store;

use OCA\OpenRegister\AppHost\Service\GenericActionAuthService;
use OCA\OpenRegister\AppHost\Service\StoreDescriptor;
use OCP\IGroupManager;
use OCP\IUser;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Asks a leaf app whether a user may perform a named action.
 *
 * 🔴 AN UNRESOLVABLE AUTHORIZER REFUSES. IT NEVER PERMITS.
 *
 * This is a duck-typed lookup by convention: `OCA\<Studly>\Service\
 * ActionAuthService::can()`. The fleet has been bitten repeatedly by exactly
 * this shape — `isInstalled('docudesk')`, `class_exists('OCA\DocuDesk\…')` —
 * where pointing a runtime lookup at a name nothing answers to makes the
 * integration a SILENT NO-OP rather than an error.
 *
 * A no-op here would be an install that skipped its authorization check and
 * reported success, so every failure to resolve — class absent, not
 * constructible, no `can()` method, `can()` throwing — is a refusal, and each
 * one is logged with the name that could not be resolved.
 *
 * @spec openspec/changes/store-plane-action-auth/specs/apphost-store-plane/spec.md#requirement-an-action-posture-must-resolve-against-the-declaring-app
 */
class StoreActionAuthorizer {
	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container    Server container, for the leaf service.
	 * @param LoggerInterface    $logger       PSR logger, server-side only.
	 * @param IGroupManager      $groupManager Group membership, for the publish check.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly IGroupManager $groupManager,
	) {
	}//end __construct()

	/**
	 * Whether the user may perform the action the store declared.
	 *
	 * @param StoreActionCheck $check The declaring leaf app id, the action and the signed-in user.
	 *
	 * @return bool True only when the leaf app's own matrix says yes.
	 *
	 * @spec openspec/changes/store-plane-action-auth/specs/apphost-store-plane/spec.md#requirement-an-action-posture-must-resolve-against-the-declaring-app
	 */
	public function permits(StoreActionCheck $check): bool {
		$service = $this->leafService(check: $check);

		return $service !== null && $this->leafPermits(service: $service, check: $check);
	}//end permits()

	/**
	 * The leaf app's action service, or null (logged) when it cannot answer.
	 *
	 * @param StoreActionCheck $check The action to authorise.
	 *
	 * @return mixed The service with a can() method, or null.
	 */
	private function leafService(StoreActionCheck $check): mixed {
		$class = $check->leafClass();
		try {
			$service = $this->container->get($class);
		} catch (Throwable $e) {
			$this->refuseInstall(check: $check, reason: sprintf('%s is not resolvable (%s)', $class, $e->getMessage()));
			return null;
		}

		if (method_exists($service, 'can') === false) {
			$this->refuseInstall(check: $check, reason: sprintf('%s has no can() method', $class));
			return null;
		}

		return $service;
	}//end leafService()

	/**
	 * Whether the leaf app's own matrix says yes.
	 *
	 * @param mixed            $service The leaf action service.
	 * @param StoreActionCheck $check   The action to authorise.
	 *
	 * @return bool True only when can() returns exactly true.
	 */
	private function leafPermits(mixed $service, StoreActionCheck $check): bool {
		try {
			return ($service->can($check->user, $check->action) === true);
		} catch (Throwable $e) {
			// A throwing matrix is a refusal, not a pass. ADR-023's own
			// requireAction() throws to deny, and a `can()` that propagates
			// anything must not be read as consent.
			$this->refuseInstall(check: $check, reason: sprintf('can() threw: %s', $e->getMessage()));
			return false;
		}
	}//end leafPermits()

	/**
	 * Whether the user may publish through this descriptor.
	 *
	 * 🔴 NO NAMED GROUP REFUSES EVERYBODY, ADMINISTRATORS INCLUDED.
	 *
	 * An empty list means the app never made the decision the plane leaves to
	 * it, so there is nothing to defer to. Once a group is named, matching
	 * mirrors GenericActionAuthService::requireAction(): an administrator
	 * passes, the `@authenticated` entry (ADR-023 EVERYONE) admits any
	 * signed-in user, otherwise the user must be in a named group.
	 * Mirroring it keeps this check and the leaf app's own
	 * `requireAction()` from disagreeing when the app passes its matrix's
	 * groups (`getAllowedGroups()`), which is the intended use.
	 *
	 * @param StoreDescriptor $descriptor The consuming app's store descriptor.
	 * @param IUser           $user       The signed-in user.
	 *
	 * @return bool True only when a group is named and it admits the user.
	 *
	 * @spec openspec/specs/apphost-store-plane/spec.md#requirement-only-a-user-the-apps-named-groups-admit-may-publish
	 */
	public function canPublish(StoreDescriptor $descriptor, IUser $user): bool {
		$groups = $descriptor->namedPublishGroups();
		if ($groups === []) {
			$this->refusePublish(
				appId: $descriptor->appId,
				reason: 'the descriptor names no publish group, so the app has not decided who may publish'
			);
			return false;
		}

		$uid = $user->getUID();
		if ($this->groupManager->isAdmin($uid) === true
			|| in_array(GenericActionAuthService::EVERYONE, $groups, true) === true
		) {
			return true;
		}

		foreach ($groups as $group) {
			if ($this->groupManager->groupExists($group) === false) {
				// A group nothing answers to admits nobody. Logged, so "nobody
				// may publish" has a trace of why.
				$this->refusePublish(
					appId: $descriptor->appId,
					reason: sprintf('publish group "%s" does not exist on this server', $group)
				);
				continue;
			}

			if ($this->groupManager->isInGroup($uid, $group) === true) {
				return true;
			}
		}

		return false;
	}//end canPublish()

	/**
	 * Log a refused install action with the reason it could not be decided.
	 *
	 * @param StoreActionCheck $check  The action that was refused.
	 * @param string           $reason Why it could not be decided.
	 *
	 * @return void
	 */
	private function refuseInstall(StoreActionCheck $check, string $reason): void {
		$this->refuse(what: sprintf('install for %s: action "%s"', $check->appId, $check->action), reason: $reason);
	}//end refuseInstall()

	/**
	 * Log a refused publish with the reason it could not be decided.
	 *
	 * @param string $appId  The declaring app.
	 * @param string $reason Why it could not be decided.
	 *
	 * @return void
	 */
	private function refusePublish(string $appId, string $reason): void {
		$this->refuse(what: sprintf('publish for %s: action "publish"', $appId), reason: $reason);
	}//end refusePublish()

	/**
	 * Log a refusal with the reason it could not be decided.
	 *
	 * Logged at ERROR, not WARNING: an app declared a posture this server then
	 * could not honour, which is a misconfiguration somebody has to fix rather
	 * than a user being told no.
	 *
	 * @param string $what   The refused operation, app and action.
	 * @param string $reason Why it could not be decided.
	 *
	 * @return void
	 */
	private function refuse(string $what, string $reason): void {
		$this->logger->error(
			message: sprintf('[AppHost\\Store] refusing %s could not be authorised — %s', $what, $reason),
			context: ['file' => __FILE__, 'line' => __LINE__]
		);
	}//end refuse()
}
