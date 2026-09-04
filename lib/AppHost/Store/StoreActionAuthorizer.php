<?php

/**
 * OpenRegister AppHost — Store install action authorizer.
 *
 * Resolves an ADR-023 action check against the LEAF app that declared it, so a
 * store may declare `"installAuth": "action:catalog.instantiate"` and get
 * integriq's own authorization matrix without OpenRegister depending on
 * integriq.
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
	 * @param ContainerInterface $container Server container, for the leaf service.
	 * @param LoggerInterface    $logger    PSR logger, server-side only.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
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
	 * Log a refusal with the reason it could not be decided.
	 *
	 * Logged at ERROR, not WARNING: an app declared a posture this server then
	 * could not honour, which is a misconfiguration somebody has to fix rather
	 * than a user being told no.
	 *
	 * @param string $appId  The declaring app.
	 * @param string $action The action name.
	 * @param string $reason Why it could not be decided.
	 *
	 * @return void
	 */
	private function refuse(string $appId, string $action, string $reason): void {
		$this->logger->error(
			message: sprintf(
				'[AppHost\\Store] refusing install for %s: action "%s" could not be authorised — %s',
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
