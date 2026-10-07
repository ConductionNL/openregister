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
	 * @param string $appId  The declaring leaf app id.
	 * @param string $action The dot-separated action name.
	 * @param IUser  $user   The signed-in user.
	 *
	 * @return bool True only when the leaf app's own matrix says yes.
	 */
	public function can(string $appId, string $action, IUser $user): bool {
		$class = 'OCA\\' . $this->studly(appId: $appId) . '\\Service\\ActionAuthService';

		try {
			$service = $this->container->get($class);
		} catch (Throwable $e) {
			$this->refuse(
				appId: $appId,
				action: $action,
				reason: sprintf('%s is not resolvable (%s)', $class, $e->getMessage())
			);
			return false;
		}

		if (method_exists($service, 'can') === false) {
			$this->refuse(
				appId: $appId,
				action: $action,
				reason: sprintf('%s has no can() method', $class)
			);
			return false;
		}

		try {
			return ($service->can($user, $action) === true);
		} catch (Throwable $e) {
			// A throwing matrix is a refusal, not a pass. ADR-023's own
			// requireAction() throws to deny, and a `can()` that propagates
			// anything must not be read as consent.
			$this->refuse(
				appId: $appId,
				action: $action,
				reason: sprintf('can() threw: %s', $e->getMessage())
			);
			return false;
		}
	}//end can()

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
			$this->refuse(
				appId: $descriptor->appId,
				action: 'publish',
				reason: 'the descriptor names no publish group, so the app has not decided who may publish',
				operation: 'publish'
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
				$this->refuse(
					appId: $descriptor->appId,
					action: 'publish',
					reason: sprintf('publish group "%s" does not exist on this server', $group),
					operation: 'publish'
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
	 * Log a refusal with the reason it could not be decided.
	 *
	 * Logged at ERROR, not WARNING: an app declared a posture this server then
	 * could not honour, which is a misconfiguration somebody has to fix rather
	 * than a user being told no.
	 *
	 * @param string $appId     The declaring app.
	 * @param string $action    The action name.
	 * @param string $reason    Why it could not be decided.
	 * @param string $operation The store operation refused (install or publish).
	 *
	 * @return void
	 */
	private function refuse(string $appId, string $action, string $reason, string $operation='install'): void {
		$this->logger->error(
			message: sprintf(
				'[AppHost\\Store] refusing %s for %s: action "%s" could not be authorised — %s',
				$operation,
				$appId,
				$action,
				$reason
			),
			context: ['file' => __FILE__, 'line' => __LINE__]
		);
	}//end refuse()

	/**
	 * StudlyCase an app id, matching Bootstrap's own convention.
	 *
	 * @param string $appId The app id.
	 *
	 * @return string
	 */
	private function studly(string $appId): string {
		$parts = preg_split(pattern: '/[_\-]+/', subject: $appId);
		if ($parts === false || count($parts) === 0) {
			$parts = [$appId];
		}

		$studly = '';
		foreach ($parts as $part) {
			$studly .= ucfirst($part);
		}

		return $studly;
	}//end studly()
}
