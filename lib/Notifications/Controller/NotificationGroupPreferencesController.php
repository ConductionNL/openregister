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

namespace OCA\OpenRegister\Notifications\Controller;

use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\NotificationPreferencesInterface;
use OCA\OpenRegister\Kernel\Contracts\PreferenceAddress;
use OCA\OpenRegister\Kernel\Contracts\PreferenceWrite;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Group\ISubAdmin;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use OCA\OpenRegister\Notifications\Controller\Support\NotificationGroupPreferencesControllerServices;
use OCA\OpenRegister\Notifications\Controller\Support\NotificationRequestTrait;

class NotificationGroupPreferencesController extends Controller {
	use NotificationRequestTrait;

	/**
	 * The group manager.
	 *
	 * @var IGroupManager
	 */
	private readonly IGroupManager $groupManager;

	/**
	 * The user session.
	 *
	 * @var IUserSession
	 */
	private readonly IUserSession $userSession;

	/**
	 * The three-layer preference resolver.
	 *
	 * @var NotificationPreferencesInterface
	 */
	private readonly NotificationPreferencesInterface $preferenceService;

	/**
	 * Mapper used to enumerate the notifications a group may hold defaults for.
	 *
	 * @var SchemaMapperInterface
	 */
	private readonly SchemaMapperInterface $schemaMapper;

	/**
	 * Constructor.
	 *
	 * @param IRequest                                       $request      Request.
	 * @param NotificationGroupPreferencesControllerServices $services     The preference resolver, the schema mapper and the session.
	 * @param IGroupManager                                  $groupManager The authority on who is an administrator.
	 * @param ISubAdmin                                      $subAdmin     The authority on who administers one particular group.
	 */
	public function __construct(
		IRequest $request,
		NotificationGroupPreferencesControllerServices $services,
		IGroupManager $groupManager,
		private readonly ISubAdmin $subAdmin,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

		$this->preferenceService = $services->preferenceService;
		$this->schemaMapper      = $services->schemaMapper;
		$this->userSession       = $services->userSession;
		$this->groupManager      = $groupManager;
	}//end __construct()

	/**
	 * List the defaults one group holds.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-the-effective-preference-merges-schema-group-and-user-and-names-the-layer-req-nrg-002
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function index(): JSONResponse {
		$userId = $this->resolveUserId();
		if ($userId === null) {
			return $this->authenticationRequired();
		}

		$groupId = $this->nonEmptyString(value: $this->request->getParam('group'));
		$refusal = $this->refuseRead(userId: $userId, groupId: $groupId);
		if ($refusal !== null) {
			return $refusal;
		}

		$scope = $this->nonEmptyString(value: $this->request->getParam('scope'));
		$items = $this->groupDefaults(groupId: (string)$groupId, scope: $scope);

		return new JSONResponse(data: ['results' => $items, 'total' => count($items)]);
	}//end index()

	/**
	 * Record or clear one group default.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-the-effective-preference-merges-schema-group-and-user-and-names-the-layer-req-nrg-002
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function update(): JSONResponse {
		$userId = $this->resolveUserId();
		if ($userId === null) {
			return $this->authenticationRequired();
		}

		$params = $this->request->getParams();
		$target = $this->writeTarget(params: $params);
		$refusal = $this->refuseWrite(userId: $userId, target: $target);
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->writeGroupDefault(target: $target, default: $this->requestedSetting(params: $params));
	}//end update()

	/**
	 * What a write is for, read from the payload.
	 *
	 * @param array<string, mixed> $params The request payload.
	 *
	 * @return array{group: ?string, schema: ?string, notification: ?string, scope: ?string} The target.
	 */
	private function writeTarget(array $params): array {
		return [
			'group' => $this->stringParam(params: $params, key: 'group'),
			'schema' => $this->stringParam(params: $params, key: 'schema'),
			'notification' => $this->stringParam(params: $params, key: 'notification'),
			'scope' => $this->stringParam(params: $params, key: 'scope'),
		];
	}//end writeTarget()

	/**
	 * Refuse a read of a group's defaults the caller may not make.
	 *
	 * Members of the group read its defaults, and so do the administrators and
	 * sub-admins who set them.
	 *
	 * @param string      $userId  The caller.
	 * @param string|null $groupId The group asked for.
	 *
	 * @return JSONResponse|null The refusal, or null when the read may proceed.
	 */
	private function refuseRead(string $userId, ?string $groupId): ?JSONResponse {
		if ($groupId === null) {
			return new JSONResponse(data: ['error' => 'A "group" is required'], statusCode: 422);
		}

		$group = $this->groupManager->get($groupId);
		if ($group === null) {
			return new JSONResponse(data: ['error' => 'Unknown group'], statusCode: 404);
		}

		if ($this->mayReadGroup(group: $group, groupId: $groupId) === false) {
			return new JSONResponse(data: ['error' => 'Not a member of that group'], statusCode: 403);
		}

		return null;
	}//end refuseRead()

	/**
	 * Whether the caller is in the group or administers it.
	 *
	 * @param IGroup $group   The group.
	 * @param string $groupId The group's id.
	 *
	 * @return boolean True when the caller may read the group's defaults.
	 */
	private function mayReadGroup(IGroup $group, string $groupId): bool {
		if ($group->inGroup($this->userSession->getUser()) === true) {
			return true;
		}

		// Only a signed-in caller reaches refuseRead(), so the session names one.
		return $this->administersGroup(userId: (string)$this->resolveUserId(), groupId: $groupId);
	}//end mayReadGroup()

	/**
	 * The group's stored defaults for every declared notification that has one.
	 *
	 * @param string      $groupId The group.
	 * @param string|null $scope   The scope asked for, or null for global.
	 *
	 * @return array<int, array<string, mixed>> The stored defaults.
	 */
	private function groupDefaults(string $groupId, ?string $scope): array {
		$items = [];
		foreach ($this->declaredNotifications() as $entry) {
			$stored = $this->preferenceService->groupDefaultAt(
				groupId: $groupId,
				address: new PreferenceAddress(schemaSlug: $entry['schema'], notificationKey: $entry['notification'], scope: $scope)
			);
			if ($stored !== null) {
				$items[] = self::groupItem(entry: ['group' => $groupId, 'scope' => $scope] + $entry, stored: $stored);
			}
		}

		return $items;
	}//end groupDefaults()

	/**
	 * One stored group default as the list answers it.
	 *
	 * @param array<string, mixed> $entry  The declared notification (schema, schemaTitle,
	 *                                     notification), with the group and scope asked for.
	 * @param array<string, mixed> $stored The stored default.
	 *
	 * @return array<string, mixed> The list item.
	 */
	private static function groupItem(array $entry, array $stored): array {
		return [
			'group' => $entry['group'],
			'schema' => $entry['schema'],
			'schemaTitle' => $entry['schemaTitle'],
			'notification' => $entry['notification'],
			'scope' => ($entry['scope'] ?? 'global'),
			'enabled' => (bool)($stored['enabled'] ?? true),
			'channels' => ($stored['channels'] ?? null),
		];
	}//end groupItem()

	/**
	 * Store the group default, then answer with what is stored now.
	 *
	 * A null `default` clears the group's value, so the schema default applies.
	 *
	 * @param array{group: ?string, schema: ?string, notification: ?string, scope: ?string} $target  What the default is for.
	 * @param array<string, mixed>|null                                                    $default The default, or null to clear it.
	 *
	 * @return JSONResponse The stored default.
	 */
	private function writeGroupDefault(array $target, ?array $default): JSONResponse {
		$groupId = (string)$target['group'];
		$address = new PreferenceAddress(
			schemaSlug: (string)$target['schema'],
			notificationKey: (string)$target['notification'],
			scope: $target['scope']
		);
		$this->preferenceService->storeGroupDefault(write: new PreferenceWrite(party: $groupId, address: $address, value: $default));

		$stored = $this->preferenceService->groupDefaultAt(groupId: $groupId, address: $address);
		return $this->settingResponse(target: $target, stored: ['default' => $stored]);
	}//end writeGroupDefault()

	/**
	 * Refuse a write that is unauthenticated, incomplete, or not this caller's
	 * to make.
	 *
	 * Lifted out of {@see update()} so the three refusals sit together: they are
	 * one decision, "may this write happen at all", and reading them in one
	 * place is what makes it obvious that administering the group is checked on
	 * the WRITER and never inferred from membership.
	 *
	 * @param string                    $userId The caller.
	 * @param array<string, string|null> $target What the write is for (group, schema, notification,
	 *                                           scope); null where the request names nothing.
	 *
	 * @return JSONResponse|null The refusal, or null when the write may proceed.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-the-effective-preference-merges-schema-group-and-user-and-names-the-layer-req-nrg-002
	 */
	private function refuseWrite(string $userId, array $target): ?JSONResponse {
		$groupId = $target['group'];
		if (in_array(null, [$groupId, $target['schema'], $target['notification']], true) === true) {
			$error = 'A "group", a "schema" and a "notification" are required';
			return new JSONResponse(data: ['error' => $error], statusCode: 422);
		}

		if ($this->groupManager->get((string)$groupId) === null) {
			return new JSONResponse(data: ['error' => 'Unknown group'], statusCode: 404);
		}

		if ($this->administersGroup(userId: $userId, groupId: (string)$groupId) === false) {
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
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-the-effective-preference-merges-schema-group-and-user-and-names-the-layer-req-nrg-002
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
			$entries = array_merge($entries, $this->declaredBy(schema: $schema));
		}

		return $entries;
	}//end declaredNotifications()

	/**
	 * The notifications one schema declares, as list entries.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return array<int, array{schema: string, schemaTitle: string, notification: string}> The entries.
	 */
	private function declaredBy(Schema $schema): array {
		$slug = (string)($schema->getSlug() ?? $schema->getId());
		$title = (string)($schema->getTitle() ?? $slug);

		return array_map(
			static fn (int|string $key): array => ['schema' => $slug, 'schemaTitle' => $title, 'notification' => (string)$key],
			$this->notificationKeys(schema: $schema)
		);
	}//end declaredBy()

	/**
	 * The keys of the notifications a schema declares, or none.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return array<int, int|string> The declared keys.
	 */
	private function notificationKeys(Schema $schema): array {
		$notifications = (($schema->getConfiguration() ?? [])['x-openregister-notifications'] ?? null);
		if (is_array($notifications) === false) {
			return [];
		}

		return array_keys($notifications);
	}//end notificationKeys()
}//end class
