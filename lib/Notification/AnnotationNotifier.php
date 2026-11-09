<?php

/**
 * OpenRegister AnnotationNotifier
 *
 * Renders annotation-driven, object-lifecycle notifications fired by
 * AnnotationNotificationDispatcher. The dispatcher emits a canonical
 * subject (object_created / object_updated / object_transitioned), the
 * routing parameters for the object-detail action link (registerId,
 * schemaId, objectUuid, objectTitle), and — when the schema declared a
 * custom per-locale `subject` — the already-interpolated text under the
 * `_text` parameter.
 *
 * This notifier renders the recipient-localised subject (the schema's
 * custom `_text` wins; otherwise a canonical localised string from the
 * openregister l10n files), sets the OpenRegister icon, and adds a primary
 * "View" action deep-linking to the object. Subjects it does not own (no
 * `_text` and not a canonical object subject — e.g. configuration_update_available,
 * which lib/Notification/Notifier.php renders) raise UnknownNotificationException
 * so the manager passes the notification on to the next notifier untouched.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Notification
 * @package  OCA\OpenRegister\Notification
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

namespace OCA\OpenRegister\Notification;

use OCA\OpenRegister\Service\DeepLinkResolverInterface;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

class AnnotationNotifier implements INotifier {
	/**
	 * Canonical object-lifecycle subjects mapped to their English source
	 * string (Dutch comes from l10n/nl.json via IFactory). Used to render a
	 * localised subject when the schema declared no custom `subject`.
	 *
	 * @var array<string, string>
	 */
	private const SUBJECT_TEMPLATES = [
		'object_created' => 'Object "%1$s" created in register "%2$s"',
		'object_updated' => 'Object "%1$s" updated in register "%2$s"',
		'object_transitioned' => 'Object "%1$s" assigned to you in register "%2$s"',
	];

	/**
	 * The same subjects for a notification that carries no register name.
	 *
	 * Nothing fills `registerName` today, so the templates above printed the
	 * register id ("updated in register "20""). These name the object only.
	 *
	 * @var array<string, string>
	 */
	private const SUBJECT_TEMPLATES_WITHOUT_REGISTER = [
		'object_created' => 'Object "%1$s" created',
		'object_updated' => 'Object "%1$s" updated',
		'object_transitioned' => 'Object "%1$s" assigned to you',
	];

	/**
	 * Constructor.
	 *
	 * @param IFactory $factory L10N factory for localised subjects.
	 * @param IURLGenerator $urlGenerator URL generator for the icon and action link.
	 * @param DeepLinkResolverInterface|null $deepLinks The owning app's detail route per register and schema.
	 */
	public function __construct(
		private readonly IFactory $factory,
		private readonly IURLGenerator $urlGenerator,
		private readonly ?DeepLinkResolverInterface $deepLinks = null,
	) {
	}//end __construct()

	/**
	 * Return the unique identifier for this notifier.
	 *
	 * @return string Notifier identifier consumed by Nextcloud.
	 */
	public function getID(): string {
		return 'openregister';
	}//end getID()

	/**
	 * Return the human-readable notifier name.
	 *
	 * @return string Notifier display name.
	 */
	public function getName(): string {
		return 'OpenRegister';
	}//end getName()

	/**
	 * Render the notification subject and action for the given language.
	 *
	 * @param INotification $notification Notification to prepare.
	 * @param string $languageCode Active language code.
	 *
	 * @return INotification Prepared notification.
	 *
	 * @throws UnknownNotificationException When the notification is not an
	 *                                      annotation/object notification this
	 *                                      notifier owns.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 * @spec openspec/specs/activity-provider/spec.md#requirement-a-canonical-object-notification-does-not-print-a-register-id
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-an-object-notification-must-link-to-the-object
	 */
	public function prepare(INotification $notification, string $languageCode): INotification {
		$owned = $this->ownedSubject(notification: $notification);
		$l = $this->factory->get('openregister', $languageCode);

		$notification->setParsedSubject($this->parsedSubject(l: $l, owned: $owned));
		$this->setParsedBody(notification: $notification, params: $owned['params']);
		$notification->setIcon($this->iconUrl(params: $owned['params']));
		$this->addActions(
			notification: $notification,
			render: ['params' => $owned['params'], 'l' => $l, 'language' => $languageCode]
		);

		return $notification;
	}//end prepare()

	/**
	 * The subject and parameters of a notification this notifier owns.
	 *
	 * Subjects this notifier does not own (e.g. configuration_update_available,
	 * rendered by Notifier) are passed on untouched.
	 *
	 * @param INotification $notification Notification to prepare.
	 *
	 * @return array{subject: string, params: array} The subject and its parameters.
	 *
	 * @throws UnknownNotificationException When this notifier does not own it.
	 */
	private function ownedSubject(INotification $notification): array {
		if ($notification->getApp() !== 'openregister') {
			throw new UnknownNotificationException();
		}

		$subject = $notification->getSubject();
		$params = $notification->getSubjectParameters();
		if (array_key_exists($subject, self::SUBJECT_TEMPLATES) === false && self::customText(params: $params) === null) {
			throw new UnknownNotificationException();
		}

		return ['subject' => $subject, 'params' => $params];
	}//end ownedSubject()

	/**
	 * The dispatcher's pre-rendered `_text`, when it carries one.
	 *
	 * @param array $params Subject parameters.
	 *
	 * @return string|null The text, or null when absent or empty.
	 */
	private static function customText(array $params): ?string {
		$text = ($params['_text'] ?? null);
		if (is_string($text) === true && $text !== '') {
			return $text;
		}

		return null;
	}//end customText()

	/**
	 * The rendered subject line.
	 *
	 * The schema's custom per-locale subject (already interpolated by the
	 * dispatcher for this recipient) wins; otherwise the canonical localised
	 * string with the object title + register name substituted. Only a
	 * canonical object subject has a template; a custom subject (e.g. a flow
	 * send's `flow_message`) reaches this point purely on its `_text`.
	 *
	 * @param IL10N                                  $l     Localiser for the recipient.
	 * @param array{subject: string, params: array} $owned The subject and its parameters.
	 *
	 * @return string The parsed subject.
	 */
	private function parsedSubject(IL10N $l, array $owned): string {
		$subject = $owned['subject'];
		$params = $owned['params'];
		$text = self::customText(params: $params);
		if ($text !== null) {
			return $text;
		}

		$objectTitle = (string)($params['objectTitle'] ?? $l->t('object'));
		$registerName = trim((string)($params['registerName'] ?? ''));
		if ($registerName === '') {
			return $l->t(self::SUBJECT_TEMPLATES_WITHOUT_REGISTER[$subject], [$objectTitle]);
		}

		return $l->t(self::SUBJECT_TEMPLATES[$subject], [$objectTitle, $registerName]);
	}//end parsedSubject()

	/**
	 * Set the notification BODY (distinct from the title/subject).
	 *
	 * The dispatcher pre-resolves the recipient-localised body — the rule's
	 * `message` template, or an auto-derived "Open in {AppName}." when the rule
	 * has actions but no message. Left unset when empty (back-compat: rules
	 * with neither message nor actions render exactly as before).
	 *
	 * @param INotification $notification Notification to prepare.
	 * @param array         $params       Subject parameters.
	 *
	 * @return void
	 */
	private function setParsedBody(INotification $notification, array $params): void {
		$message = ($params['_message'] ?? null);
		if (is_string($message) === true && $message !== '') {
			$notification->setParsedMessage($message);
		}
	}//end setParsedBody()

	/**
	 * The absolute icon URL.
	 *
	 * When the rule resolved an originApp, the hex-composite raster endpoint
	 * (the originApp's white glyph on the cobalt hexagon) so the OS popup
	 * carries the originating app's identity; otherwise app.svg. setIcon()
	 * only accepts absolute http(s) URLs — a relative imagePath() throws
	 * InvalidValueException on every render.
	 *
	 * @param array $params Subject parameters.
	 *
	 * @return string The icon URL.
	 */
	private function iconUrl(array $params): string {
		$originApp = (string)($params['originApp'] ?? 'openregister');
		if ($originApp !== '' && $originApp !== 'openregister') {
			return $this->urlGenerator->linkToRouteAbsolute('openregister.webPush.hexIcon', ['app' => $originApp]);
		}

		return $this->urlGenerator->getAbsoluteURL(
			$this->urlGenerator->imagePath(appName: 'openregister', file: 'app.svg')
		);
	}//end iconUrl()

	/**
	 * Link the object and add the action buttons.
	 *
	 * Declared actions render when the rule provided any. The implicit "View"
	 * action is the fallback when no declared action rendered — none declared
	 * (back-compat) or every one resolved to an empty link — so a notification
	 * never ships with zero actions when an object-detail target exists.
	 *
	 * @param INotification                                  $notification Notification to prepare.
	 * @param array{params: array, l: IL10N, language: string} $render       Parameters, localiser and language code.
	 *
	 * @return void
	 */
	private function addActions(INotification $notification, array $render): void {
		$params = $render['params'];
		// Link to the object; without it a click went nowhere (cloud check, `link: ""`).
		$objectLink = $this->linkToObject(notification: $notification, params: $params);

		$actions = ($params['_actions'] ?? []);
		$rendered = 0;
		if (is_array($actions) === true && count($actions) > 0) {
			$rendered = $this->addDeclaredActions(notification: $notification, actions: $actions, languageCode: $render['language']);
		}

		if ($rendered === 0 && $objectLink !== null) {
			$this->addViewAction(notification: $notification, link: $objectLink, label: $render['l']->t('View'));
		}
	}//end addActions()

	/**
	 * Render the schema-declared action buttons via addParsedAction().
	 *
	 * Each action carries a per-locale `label` map, a `primary` flag, a
	 * pre-resolved absolute `url` (resolved server-side by the dispatcher
	 * through OR RBAC) and an optional `method`. The recipient's locale
	 * label wins, falling back to `en` then the first available locale.
	 *
	 * A `task-verb` target arrives with `method: POST` and is rendered as a
	 * state-changing action; everything else renders GET, as before. The
	 * method is whitelisted here so a resolved action can never smuggle an
	 * arbitrary verb into the client.
	 *
	 * @param INotification $notification Notification to attach actions to.
	 * @param array<int, mixed> $actions Resolved actions (each element validated at runtime).
	 * @param string $languageCode Active recipient locale.
	 *
	 * @return int The number of action buttons actually rendered.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-a-binary-decision-is-decidable-from-the-notification
	 */
	private function addDeclaredActions(INotification $notification, array $actions, string $languageCode): int {
		$rendered = 0;
		foreach ($actions as $action) {
			if (is_array($action) === false) {
				continue;
			}

			$url = (string)($action['url'] ?? '');
			if ($url === '') {
				continue;
			}

			// The dispatcher returns a registry deep link as a path, so a browser
			// keeps the page's own port. Nextcloud refuses a relative action
			// link and the whole notification then failed to render.
			if (str_starts_with($url, '/') === true) {
				$url = $this->urlGenerator->getAbsoluteURL($url);
			}

			$labelMap = ($action['label'] ?? []);
			if (is_array($labelMap) === false) {
				$labelMap = [];
			}

			$fallbackLabel = 'Open';
			$firstLabel = reset($labelMap);
			if ($firstLabel !== false) {
				$fallbackLabel = $firstLabel;
			}

			$label = (string)($labelMap[$languageCode] ?? ($labelMap['en'] ?? $fallbackLabel));

			$method = strtoupper((string)($action['method'] ?? 'GET'));
			if (in_array($method, ['GET', 'POST', 'PUT', 'DELETE'], true) === false) {
				$method = 'GET';
			}

			// Nextcloud's notification API returns PARSED actions only, so an
			// action added with addAction() never reached the client. The raw
			// label is a short key (Nextcloud caps it at 32 characters); the
			// person reads the parsed label.
			$actionObject = $notification->createAction();
			$actionObject->setLabel('action-'.$rendered)
				->setParsedLabel($label)
				->setPrimary((bool)($action['primary'] ?? false))
				->setLink($url, $method);
			$notification->addParsedAction($actionObject);
			$rendered++;
		}//end foreach

		return $rendered;
	}//end addDeclaredActions()

	/**
	 * Set the notification link to the object, and return that link.
	 *
	 * @param INotification $notification The notification being prepared.
	 * @param array<string,mixed> $params Subject parameters from the notification.
	 *
	 * @return string|null The link set, or null when the notification names no object.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-an-object-notification-must-link-to-the-object
	 */
	private function linkToObject(INotification $notification, array $params): ?string {
		$link = $this->buildObjectLink(params: $params);
		if ($link !== null) {
			$notification->setLink($link);
		}

		return $link;
	}//end linkToObject()

	/**
	 * The absolute link to the object a notification is about.
	 *
	 * The owning app's detail page from the deep link registry when an app
	 * claimed the schema (pipelinq: `/apps/pipelinq/clients/{uuid}`), else
	 * OpenRegister's object view. Null when the notification does not name a
	 * register, a schema and an object.
	 *
	 * @param array<string,mixed> $params Subject parameters from the notification.
	 *
	 * @return string|null The absolute link, or null.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-an-object-notification-must-link-to-the-object
	 * @spec openspec/changes/notification-links-in-releases-and-case-insensitive-order/specs/notificatie-engine/spec.md#requirement-an-object-notification-must-link-to-the-object
	 */
	private function buildObjectLink(array $params): ?string {
		$registerId = (string)($params['registerId'] ?? '');
		$schemaId = (string)($params['schemaId'] ?? '');
		$objectUuid = (string)($params['objectUuid'] ?? '');
		if ($registerId === '' || $schemaId === '' || $objectUuid === '') {
			return null;
		}

		$owned = $this->resolveOwnedLink(registerId: $registerId, schemaId: $schemaId, objectUuid: $objectUuid);
		if ($owned !== null) {
			return $owned;
		}

		// OpenRegister runs in history mode: its object view is
		// objects/{register}/{schema}/{uuid}. The old #/registers/... hash
		// opened the dashboard (cloud check, 9 October 2026).
		return $this->urlGenerator->linkToRouteAbsolute('openregister.dashboard.page')
			. sprintf('objects/%s/%s/%s', rawurlencode($registerId), rawurlencode($schemaId), rawurlencode($objectUuid));
	}//end buildObjectLink()

	/**
	 * The owning app's absolute detail link from the deep link registry, or null.
	 *
	 * @param string $registerId The register id.
	 * @param string $schemaId The schema id.
	 * @param string $objectUuid The object uuid.
	 *
	 * @return string|null The absolute link, or null when no app claimed the schema.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-an-object-notification-must-link-to-the-object
	 */
	private function resolveOwnedLink(string $registerId, string $schemaId, string $objectUuid): ?string {
		if ($this->deepLinks === null || is_numeric($registerId) === false || is_numeric($schemaId) === false) {
			return null;
		}

		$owned = $this->deepLinks->resolveUrl(
			registerId: (int)$registerId,
			schemaId: (int)$schemaId,
			objectData: ['uuid' => $objectUuid, 'id' => $objectUuid]
		);
		if ($owned === null || $owned === '') {
			return null;
		}

		if (str_starts_with($owned, 'http://') === true || str_starts_with($owned, 'https://') === true) {
			return $owned;
		}

		return $this->urlGenerator->getAbsoluteURL($owned);
	}//end resolveOwnedLink()

	/**
	 * Attach the primary "View" action, linking where the notification links.
	 *
	 * @param INotification $notification Notification to attach the action to.
	 * @param string $link The absolute link to the object.
	 * @param string $label Localised label for the action button.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-an-object-notification-must-link-to-the-object
	 */
	private function addViewAction(INotification $notification, string $link, string $label): void {
		$action = $notification->createAction();
		$action->setLabel('view')
			->setParsedLabel($label)
			->setPrimary(true)
			->setLink($link, 'GET');
		$notification->addParsedAction($action);
	}//end addViewAction()
}//end class
