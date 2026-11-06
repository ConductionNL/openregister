<?php

/**
 * ObjectDetailLinks: the deeplink of an object or of one of its relations.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Notification
 * @package  OCA\OpenRegister\Notifications\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Notification;

use Closure;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\DeepLinkRegistryService;
use Psr\Log\LoggerInterface;

/**
 * Builds object-detail links; a related object is read through RBAC first.
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */
final class ObjectDetailLinks {
	/**
	 * Constructor
	 *
	 * @param LoggerInterface              $logger           Logs a relation that cannot be read.
	 * @param AppRouteBase                 $routes           The base of the fallback link.
	 * @param Closure|null                 $readObject       Reads an object with RBAC on: fn (string): ?ObjectEntity.
	 * @param DeepLinkRegistryService|null $deepLinkRegistry Canonical per-app deeplink resolver.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly AppRouteBase $routes,
		private readonly ?Closure $readObject = null,
		private readonly ?DeepLinkRegistryService $deepLinkRegistry = null,
	) {
	}//end __construct()

	/**
	 * The detail link of the triggering object, or of the relation the target names.
	 *
	 * @param array<string, mixed> $target  The object-detail target.
	 * @param ActionTargetContext  $context What the target resolves against.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	public function url(array $target, ActionTargetContext $context): string|null {
		$relationSpec = ($target['object'] ?? null);
		if (is_array($relationSpec) === true && (string)($relationSpec['kind'] ?? '') === 'relation') {
			return $this->relationLink(field: (string)($relationSpec['field'] ?? ''), data: $context->data);
		}

		return $this->objectLink(object: $context->object);
	}//end url()

	/**
	 * Resolve a relation-field deeplink ("Open client") through OR RBAC.
	 *
	 * The field value on the triggering object is treated only as a lookup
	 * key; the related object is re-resolved via ObjectService::find() with
	 * RBAC enabled so a deeplink is built only for an object the current
	 * context may read. The wire-supplied id is never trusted directly.
	 *
	 * @param string               $field The relation field name.
	 * @param array<string, mixed> $data  The triggering object's data.
	 *
	 * @return string|null The deeplink, or null when the relation is empty/unreadable.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function relationLink(string $field, array $data): string|null {
		$lookup = self::relationKey(raw: ($data[$field] ?? null));
		if ($field === '' || $lookup === null || $this->readObject === null) {
			return null;
		}

		$related = $this->readableObject(lookup: $lookup);
		if ($related === null) {
			return null;
		}

		return $this->objectLink(object: $related);
	}//end relationLink()

	/**
	 * The lookup key in a relation value; of an array relation, the first id or uuid.
	 *
	 * @param mixed $raw The relation value.
	 *
	 * @return string|null
	 */
	private static function relationKey(mixed $raw): string|null {
		$candidate = $raw;
		if (is_array($raw) === true) {
			$candidate = ($raw['id'] ?? ($raw['uuid'] ?? ($raw[0] ?? null)));
		}

		if (is_string($candidate) === true && $candidate !== '') {
			return $candidate;
		}

		return null;
	}//end relationKey()

	/**
	 * The object behind a lookup key, if the current context may read it.
	 *
	 * @param string $lookup The id or uuid.
	 *
	 * @return ObjectEntity|null
	 */
	private function readableObject(string $lookup): ObjectEntity|null {
		try {
			return ($this->readObject)($lookup);
		} catch (\Throwable $e) {
			$this->logger->debug(
				sprintf('[AnnotationNotificationDispatcher] relation deeplink resolve failed: %s', $e->getMessage())
			);
			return null;
		}
	}//end readableObject()

	/**
	 * Build an object-detail deeplink: the owning app's registered route, else
	 * OpenRegister's object view.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return string|null The deeplink, or null when ids are missing.
	 *
	 * @spec openspec/changes/notification-links-in-releases-and-case-insensitive-order/specs/notificatie-engine/spec.md#requirement-an-object-notification-must-link-to-the-object
	 */
	private function objectLink(ObjectEntity $object): string|null {
		$ids = [(string)($object->getRegister() ?? ''), (string)($object->getSchema() ?? ''), (string)($object->getUuid() ?? '')];
		if (in_array('', $ids, true) === true) {
			return null;
		}

		$registered = $this->registeredLink(ids: $ids);
		if ($registered !== null && $registered !== '') {
			return self::withoutOrigin(url: $registered);
		}

		// No app claimed the schema: open the object in OpenRegister's object
		// view (history mode). The origin app's #/registers/... hash was never
		// a route there, so pipelinq redirected it to its dashboard.
		return $this->routes->forApp(app: 'openregister') . vsprintf('objects/%s/%s/%s', array_map('rawurlencode', $ids));
	}//end objectLink()

	/**
	 * The canonical per-app deeplink registered via DeepLinkRegistrationEvent
	 * (e.g. pipelinq::client → /apps/pipelinq/clients/{uuid}), if there is one.
	 *
	 * @param array<int, string> $ids The register id, schema id and object uuid.
	 *
	 * @return string|null
	 */
	private function registeredLink(array $ids): string|null {
		[$registerId, $schemaId, $objectUuid] = $ids;
		if ($this->deepLinkRegistry === null || is_numeric($registerId) === false || is_numeric($schemaId) === false) {
			return null;
		}

		return $this->deepLinkRegistry->resolveUrl(
			registerId: (int)$registerId,
			schemaId: (int)$schemaId,
			objectData: ['uuid' => $objectUuid, 'id' => $objectUuid]
		);
	}//end registeredLink()

	/**
	 * A registered link as a RELATIVE path.
	 *
	 * The Service Worker or browser then resolves it against the real page
	 * origin, preserving a non-standard port such as :8080. Wrapping with
	 * getAbsoluteURL() injects overwrite.cli.url, which can drop the port and
	 * 404 the deeplink.
	 *
	 * @param string $url The registered link.
	 *
	 * @return string
	 */
	private static function withoutOrigin(string $url): string {
		if (str_starts_with($url, 'http') === false) {
			return $url;
		}

		$parts = parse_url($url);
		$path = ($parts['path'] ?? '/');
		if (isset($parts['query']) === true) {
			$path .= '?' . $parts['query'];
		}

		if (isset($parts['fragment']) === true) {
			$path .= '#' . $parts['fragment'];
		}

		return $path;
	}//end withoutOrigin()
}//end class
