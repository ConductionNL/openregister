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
	 * Data keys win over context keys. A UUID-shaped data value is resolved to
	 * the related object's display name when possible, so `{{client}}` reads
	 * "Acme Gemeente BV" rather than a UUID.
	 *
	 * 🔴 A KEY NOTHING ANSWERS IS LEFT IN THE TEXT, NOT BLANKED. It used to
	 * render as an empty string, and that is the worse of the two failures.
	 * `Bewaartermijn: {{skippedCount}} records overgeslagen` became
	 * "Bewaartermijn:  records overgeslagen": a sentence with a hole, which
	 * reads as clumsy writing rather than as a defect, so nobody reports it
	 * and the notification keeps going out wrong. Leaving `{{skippedCount}}`
	 * in announces itself the first time anybody reads it — which is exactly
	 * how dossiq#2950 found six templates that had been broken for 35 days.
	 *
	 * It also makes this evaluator agree with the one beside it.
	 * `NotificationTemplateRegistry::interpolate()` renders the SAME kind of
	 * text for the SAME subsystem and has always left an unknown key alone.
	 * Two evaluators disagreeing about the same question meant which failure a
	 * reader got depended on whether an administrator had edited the template.
	 *
	 * A caller that must REFUSE rather than render asks {@see unanswered()}
	 * first. Nothing here throws: a notification is an alert, and a missing
	 * word in one is better than silence about the thing it was raised for.
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
						return $matches[0];
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
						return $matches[0];
					}

					return htmlspecialchars((string)$context[$key], ENT_QUOTES, 'UTF-8');
				}

				// A DOTTED path, last, because the flat lookups above are the
				// common case and must keep winning over a key that merely
				// contains a dot.
				//
				// Without this the flow engine had two placeholder dialects that
				// disagreed. `FlowValueTemplate` walks a dotted path, so an
				// object-write storing `{{issueResult.number}}` worked, while the
				// Talk and notification nodes did a flat `array_key_exists` and
				// left the same placeholder in the text. Measured 2026-09-23: a
				// pipeline posted "the issue is open at {{issueResult.url}}" into
				// a Talk room, four messages in a row. The author had written one
				// grammar across one chain and got it honoured in some steps and
				// printed in others.
				$nested = self::valueAtPath(path: $key, data: $data);
				if ($nested === null) {
					$nested = self::valueAtPath(path: $key, data: $context);
				}

				if (is_scalar($nested) === true) {
					return htmlspecialchars((string)$nested, ENT_QUOTES, 'UTF-8');
				}

				// Left as it was found. See the docblock: a hole is harder to
				// notice than a leak, and this evaluator now agrees with the
				// registry's.
				return $matches[0];
			},
			$template
		) ?? $template;
	}//end interpolate()

	/**
	 * The value at a dotted path, or null when the path is absent.
	 *
	 * Deliberately the same walk as `FlowValueTemplate::valueAt()`, because the
	 * point of having it here at all is that the two evaluators answer the same
	 * question the same way. A flow author writes one grammar across one chain.
	 *
	 * @param string $path The dotted path.
	 * @param array<string, mixed> $data The map to walk.
	 *
	 * @return mixed The value, or null when any segment is missing.
	 */
	private static function valueAtPath(string $path, array $data): mixed {
		if (str_contains($path, '.') === false) {
			return null;
		}

		$cursor = $data;
		foreach (explode('.', $path) as $segment) {
			if (is_array($cursor) === false || array_key_exists($segment, $cursor) === false) {
				return null;
			}

			$cursor = $cursor[$segment];
		}

		return $cursor;
	}//end valueAtPath()

	/**
	 * The placeholders this template names that neither data nor context fills.
	 *
	 * The question {@see interpolate()} answers silently, asked out loud. A
	 * caller that must not emit half-rendered text asks this first and refuses;
	 * a caller for whom a missing word beats silence renders anyway.
	 *
	 * Named after the same method in dossiq's two renderers, which is
	 * deliberate: this is one class of defect across two apps and a reader who
	 * has met it once should recognise it here.
	 *
	 * @param string               $template The template carrying `{{ key }}` placeholders.
	 * @param array<string, mixed> $data     The primary data.
	 * @param array<string, mixed> $context  Secondary lookup values.
	 *
	 * @return array<int, string> The unanswerable names, in the order they appear, without repeats.
	 *
	 * @spec openspec/changes/notification-placeholders-refuse/specs/notificatie-engine/spec.md
	 */
	public function unanswered(string $template, array $data, array $context): array {
		if (preg_match_all('/\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}/', $template, $matches) === false) {
			return [];
		}

		$unanswered = [];
		foreach ($matches[1] as $key) {
			// A non-scalar counts as unanswered, because that is exactly what
			// interpolate() cannot render either. Asking a different question
			// here than the renderer asks is how a guard comes to disagree with
			// the thing it guards.
			if (array_key_exists($key, $data) === true && is_scalar($data[$key]) === true) {
				continue;
			}

			if (array_key_exists($key, $context) === true && is_scalar($context[$key]) === true) {
				continue;
			}

			$unanswered[] = $key;
		}

		return array_values(array_unique($unanswered));
	}//end unanswered()

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
