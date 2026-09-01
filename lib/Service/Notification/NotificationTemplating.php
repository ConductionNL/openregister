<?php

/**
 * The notification dialect's placeholder evaluator, as a call-shared unit.
 *
 * Extracted from AnnotationNotificationDispatcher so the SAME `{{ key }}`
 * evaluation serves both callers: the declarative dispatcher rendering a
 * schema-declared subject, and the flow messaging service rendering a node's
 * template against a flow item. One placeholder syntax, one implementation —
 * a second evaluator is precisely the place where the two would drift apart.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-messaging-nodes/specs/flow-messaging-nodes/spec.md#requirement-flows-send-through-the-notification-subsystem-never-beside-it
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Notification;

use Psr\Log\LoggerInterface;

/**
 * Interpolates `{{ key }}` placeholders against data and context.
 */
class NotificationTemplating {

	/**
	 * Per-instance cache of resolved relation display names, keyed by UUID.
	 * Avoids repeat ObjectService lookups when the same relation is
	 * interpolated across a recipient fan-out.
	 *
	 * @var array<string, string|null>
	 */
	private array $relationDisplayCache = [];

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Logger for resolve diagnostics.
	 * @param \OCA\OpenRegister\Service\ObjectService|null $objectService Object resolver for relation display names (RBAC-scoped).
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly ?\OCA\OpenRegister\Service\ObjectService $objectService = null,
	) {

	}//end __construct()

	/**
	 * Interpolate `{{ key }}` placeholders in a template.
	 *
	 * Data keys win over context keys; a placeholder that resolves to a
	 * non-scalar or to nothing renders as an empty string. A UUID-shaped data
	 * value is resolved to the related object's display name when possible,
	 * so `{{client}}` reads "Acme Gemeente BV" rather than a UUID.
	 *
	 * This is the notification dialect's ONE placeholder syntax; the flow
	 * messaging nodes reuse it verbatim rather than introducing a second one.
	 *
	 * @param string $template The template carrying `{{ key }}` placeholders.
	 * @param array<string, mixed> $data The primary data (object data, or a flow item's json).
	 * @param array<string, mixed> $context Secondary lookup values.
	 *
	 * @return string The interpolated string.
	 *
	 * @spec openspec/changes/flow-messaging-nodes/specs/flow-messaging-nodes/spec.md#requirement-flows-send-through-the-notification-subsystem-never-beside-it
	 */
	public function interpolate(string $template, array $data, array $context): string {
		return preg_replace_callback(
			'/\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}/',
			function (array $matches) use ($data, $context): string {
				$key = $matches[1];
				if (array_key_exists($key, $data) === true) {
					if (is_scalar($data[$key]) === false) {
						return '';
					}

					// Relation fields hold a UUID reference; show the related
					// object's display name instead of the raw UUID so
					// "{{client}}" reads "Acme Gemeente BV", not a UUID string.
					$raw = (string)$data[$key];
					$display = $this->resolveRelationDisplayName(value: $raw);

					return htmlspecialchars(($display ?? $raw), ENT_QUOTES, 'UTF-8');
				}

				if (array_key_exists($key, $context) === true) {
					if (is_scalar($context[$key]) === false) {
						return '';
					}

					return htmlspecialchars((string)$context[$key], ENT_QUOTES, 'UTF-8');
				}

				return '';
			},
			$template
		) ?? $template;
	}//end interpolate()

	/**
	 * Resolve a relation-reference UUID to the related object's display name.
	 *
	 * Returns null — so the caller keeps the raw value — for non-UUID values,
	 * an absent ObjectService, an unresolvable id, or a nameless object.
	 * Cached per instance to avoid repeat lookups across a recipient fan-out.
	 *
	 * @param string $value The interpolated field value.
	 *
	 * @return string|null The related object's display name, or null to keep the raw value.
	 *
	 * @spec openspec/changes/openregister-notification-relation-names/specs/notificatie-engine/spec.md
	 */
	public function resolveRelationDisplayName(string $value): ?string {
		if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) !== 1) {
			return null;
		}

		if ($this->objectService === null) {
			return null;
		}

		if (array_key_exists($value, $this->relationDisplayCache) === true) {
			return $this->relationDisplayCache[$value];
		}

		$name = null;
		try {
			$related = $this->objectService->find(id: $value, _rbac: true);
			if ($related !== null) {
				$candidate = $related->getName();
				if (is_string($candidate) === true && $candidate !== '') {
					$name = $candidate;
				}
			}
		} catch (\Throwable $e) {
			$this->logger->debug('[NotificationTemplating] relation display-name resolve failed: ' . $e->getMessage());
			$name = null;
		}

		$this->relationDisplayCache[$value] = $name;

		return $name;
	}//end resolveRelationDisplayName()
}//end class
