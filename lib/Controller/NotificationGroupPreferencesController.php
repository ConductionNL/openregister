<?php

/**
 * NotificationGroupPreferencesController.
 *
 * The team's half of the notification preferences. A group default sits between
 * the schema default and each member's own value, so a team that all misses the
 * same deadline warning is one setting to fix rather than five.
 *
 *   GET /api/notification-group-preferences?group=<gid>[&scope=<scope>]
 *       → the defaults this group holds, per (schema, notification).
 *
 *   PUT /api/notification-group-preferences
 *       body: { group, schema, notification, enabled?, channels?, scope?, reset? }
 *       → record (or, with `reset: true`, clear) one group default.
 *
 * Writing is restricted to someone who administers that group: a Nextcloud
 * administrator, or a sub-administrator of the group itself. Reading is open to
 * members, because a member is entitled to know why their team's default is
 * what it is — that is the whole point of naming the deciding layer.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Notification\NotificationPreferenceService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Group\ISubAdmin;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

class NotificationGroupPreferencesController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName App name.
	 * @param IRequest $request Request.
	 * @param NotificationPreferenceService $preferenceService The three-layer preference resolver.
	 * @param SchemaMapper $schemaMapper Mapper used to enumerate the notifications a group may hold defaults for.
	 * @param IGroupManager $groupManager Group resolver, and the authority on who is a Nextcloud administrator.
	 * @param ISubAdmin $subAdmin The authority on who administers one particular group.
	 * @param IUserSession $userSession Current-user session.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) One dependency per authority the endpoint consults.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly NotificationPreferenceService $preferenceService,
		private readonly SchemaMapper $schemaMapper,
		private readonly IGroupManager $groupManager,
		private readonly ISubAdmin $subAdmin,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * List the defaults one group holds.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/notification-routing-per-group-and-scope/specs/notificatie-engine/spec.md#requirement-the-effective-preference-merges-schema-group-and-user-and-names-the-layer-req-nrg-002
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function index(): JSONResponse {
		$userId = $this->resolveUserId();
		if ($userId === null) {
			return new JSONResponse(data: ['error' => 'Authentication required'], statusCode: 401);
		}

		$groupId = $this->nonEmptyString(value: $this->request->getParam('group'));
		if ($groupId === null) {
			return new JSONResponse(data: ['error' => 'A "group" is required'], statusCode: 422);
		}

		$group = $this->groupManager->get($groupId);
		if ($group === null) {
			return new JSONResponse(data: ['error' => 'Unknown group'], statusCode: 404);
		}

		// A member may read their own team's defaults; anyone else may not,
		// because a group's list is a statement about that group.
		if ($group->inGroup($this->userSession->getUser()) === false
			&& $this->administersGroup(userId: $userId, groupId: $groupId) === false
		) {
			return new JSONResponse(data: ['error' => 'Not a member of that group'], statusCode: 403);
		}

		$scope = $this->nonEmptyString(value: $this->request->getParam('scope'));
		$items = [];
		foreach ($this->declaredNotifications() as $entry) {
			$stored = $this->preferenceService->getGroupDefault(
				groupId: $groupId,
				schemaSlug: $entry['schema'],
				notificationKey: $entry['notification'],
				scope: $scope
			);
			if ($stored === null) {
				continue;
			}

			$items[] = [
				'group' => $groupId,
				'schema' => $entry['schema'],
				'schemaTitle' => $entry['schemaTitle'],
				'notification' => $entry['notification'],
				'scope' => ($scope ?? 'global'),
				'enabled' => (bool)($stored['enabled'] ?? true),
				'channels' => ($stored['channels'] ?? null),
			];
		}

		return new JSONResponse(data: ['results' => $items, 'total' => count($items)]);
	}//end index()

	/**
	 * Record or clear one group default.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/notification-routing-per-group-and-scope/specs/notificatie-engine/spec.md#requirement-the-effective-preference-merges-schema-group-and-user-and-names-the-layer-req-nrg-002
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function update(): JSONResponse {
		$params = $this->request->getParams();
		$groupId = $this->nonEmptyString(value: ($params['group'] ?? null));
		$schema = $this->nonEmptyString(value: ($params['schema'] ?? null));
		$notification = $this->nonEmptyString(value: ($params['notification'] ?? null));

		$refusal = $this->refuseWrite(groupId: $groupId, schema: $schema, notification: $notification);
		if ($refusal !== null) {
			return $refusal;
		}

		$scope = $this->nonEmptyString(value: ($params['scope'] ?? null));
		$default = null;
		if (($params['reset'] ?? false) !== true && ($params['reset'] ?? null) !== 'true') {
			$default = ['enabled' => (bool)($params['enabled'] ?? true)];
			if (isset($params['channels']) === true && is_array($params['channels']) === true) {
				$default['channels'] = $params['channels'];
			}
		}

		$this->preferenceService->setGroupDefault(
			groupId: (string)$groupId,
			schemaSlug: (string)$schema,
			notificationKey: (string)$notification,
			default: $default,
			scope: $scope
		);

		return new JSONResponse(
			data: [
				'group' => $groupId,
				'schema' => $schema,
				'notification' => $notification,
				'scope' => ($scope ?? 'global'),
				'default' => $this->preferenceService->getGroupDefault(
					groupId: (string)$groupId,
					schemaSlug: (string)$schema,
					notificationKey: (string)$notification,
					scope: $scope
				),
			]
		);
	}//end update()

	/**
	 * Refuse a write that is unauthenticated, incomplete, or not this caller's
	 * to make.
	 *
	 * Lifted out of {@see update()} so the three refusals sit together: they are
	 * one decision, "may this write happen at all", and reading them in one
	 * place is what makes it obvious that administering the group is checked on
	 * the WRITER and never inferred from membership.
	 *
	 * @param string|null $groupId The group named in the request, or null when absent.
	 * @param string|null $schema The schema named in the request, or null when absent.
	 * @param string|null $notification The notification named in the request, or null when absent.
	 *
	 * @return JSONResponse|null The refusal, or null when the write may proceed.
	 *
	 * @spec openspec/changes/notification-routing-per-group-and-scope/specs/notificatie-engine/spec.md#requirement-the-effective-preference-merges-schema-group-and-user-and-names-the-layer-req-nrg-002
	 */
	private function refuseWrite(?string $groupId, ?string $schema, ?string $notification): ?JSONResponse {
		$userId = $this->resolveUserId();
		if ($userId === null) {
			return new JSONResponse(data: ['error' => 'Authentication required'], statusCode: 401);
		}

		if ($groupId === null || $schema === null || $notification === null) {
			return new JSONResponse(
				data: ['error' => 'A "group", a "schema" and a "notification" are required'],
				statusCode: 422
			);
		}

		if ($this->groupManager->get($groupId) === null) {
			return new JSONResponse(data: ['error' => 'Unknown group'], statusCode: 404);
		}

		// Setting a team's default is an act of administration over that team.
		// Checked here on the writer, never inferred from membership.
		if ($this->administersGroup(userId: $userId, groupId: $groupId) === false) {
			return new JSONResponse(data: ['error' => 'You do not administer that group'], statusCode: 403);
		}

		return null;
	}//end refuseWrite()

	/**
	 * Whether this user may set defaults for this group.
	 *
	 * A Nextcloud administrator, or a sub-administrator of the group itself.
	 * Nothing else: membership is not administration, and a member who could
	 * set the team's default would be setting everyone else's preference.
	 *
	 * @param string $userId The caller's uid.
	 * @param string $groupId The group.
	 *
	 * @return boolean True when the caller administers the group.
	 *
	 * @spec openspec/changes/notification-routing-per-group-and-scope/specs/notificatie-engine/spec.md#requirement-the-effective-preference-merges-schema-group-and-user-and-names-the-layer-req-nrg-002
	 */
	private function administersGroup(string $userId, string $groupId): bool {
		if ($this->groupManager->isAdmin($userId) === true) {
			return true;
		}

		$user = $this->userSession->getUser();
		$group = $this->groupManager->get($groupId);
		if ($user === null || $group === null) {
			return false;
		}

		try {
			return $this->subAdmin->isSubAdminOfGroup($user, $group);
		} catch (\Throwable $e) {
			// Fail CLOSED: an unreadable sub-admin relation is not permission.
			return false;
		}
	}//end administersGroup()

	/**
	 * Every (schema, notification) pair the instance declares.
	 *
	 * @return array<int, array{schema: string, schemaTitle: string, notification: string}>
	 */
	private function declaredNotifications(): array {
		try {
			$schemas = $this->schemaMapper->findAll();
		} catch (\Throwable $e) {
			return [];
		}

		$entries = [];
		foreach ($schemas as $schema) {
			$config = ($schema->getConfiguration() ?? []);
			$notifications = ($config['x-openregister-notifications'] ?? null);
			if (is_array($notifications) === false) {
				continue;
			}

			$slug = (string)($schema->getSlug() ?? $schema->getId());
			foreach (array_keys($notifications) as $key) {
				$entries[] = [
					'schema' => $slug,
					'schemaTitle' => (string)($schema->getTitle() ?? $slug),
					'notification' => (string)$key,
				];
			}
		}

		return $entries;
	}//end declaredNotifications()

	/**
	 * Resolve the current user's UID, or null when anonymous.
	 *
	 * @return string|null
	 */
	private function resolveUserId(): ?string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return null;
		}

		return $user->getUID();
	}//end resolveUserId()

	/**
	 * Coerce a request value to a non-empty string, or null.
	 *
	 * @param mixed $value Input.
	 *
	 * @return string|null
	 */
	private function nonEmptyString(mixed $value): ?string {
		if (is_string($value) === false || $value === '') {
			return null;
		}

		return $value;
	}//end nonEmptyString()
}//end class
