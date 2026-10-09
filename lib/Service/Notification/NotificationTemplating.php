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
 * @spec openspec/specs/flow-messaging-nodes/spec.md#requirement-flows-send-through-the-notification-subsystem-never-beside-it
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Notification;

use OCA\OpenRegister\Service\WriteCause;
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
	 * A translatable property holds a language map (`{"nl": "Bel klant"}`).
	 * It renders in the first language of $languages it has, else its first
	 * value, so `Task changed: {{subject}}` no longer keeps the placeholder.
	 *
	 * @param string $template The template carrying `{{ key }}` placeholders.
	 * @param array<string, mixed> $data The primary data (object data, or a flow item's json).
	 * @param array<string, mixed> $context Secondary lookup values.
	 * @param array<int, string|null> $languages Language chain for a language map, first wins (recipient, then register default).
	 *
	 * @return string The interpolated string.
	 *
	 * @spec openspec/specs/flow-messaging-nodes/spec.md#requirement-flows-send-through-the-notification-subsystem-never-beside-it
	 * @spec openspec/changes/notification-links-in-releases-and-case-insensitive-order/specs/notificatie-engine/spec.md#requirement-a-translatable-value-must-fill-its-placeholder
	 */
	public function interpolate(string $template, array $data, array $context, array $languages = []): string {
		return preg_replace_callback(
			'/\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}/',
			function (array $matches) use ($data, $context, $languages): string {
				$key = $matches[1];
				if (array_key_exists($key, $data) === true) {
					$value = $this->translatedValue(value: $data[$key], languages: $languages);
					if (is_scalar($value) === false) {
						return $matches[0];
					}

					// Relation fields hold a UUID reference; show the related
					// object's display name instead of the raw UUID so
					// "{{client}}" reads "Acme Gemeente BV", not a UUID string.
					$raw = (string)$value;
					$display = $this->resolveRelationDisplayName(value: $raw);

					return htmlspecialchars(($display ?? $raw), ENT_QUOTES, 'UTF-8');
				}

				if (array_key_exists($key, $context) === true) {
					$value = $this->translatedValue(value: $context[$key], languages: $languages);
					if (is_scalar($value) === false) {
						return $matches[0];
					}

					return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
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
			if (array_key_exists($key, $data) === true && is_scalar($this->translatedValue(value: $data[$key], languages: [])) === true) {
				continue;
			}

			if (array_key_exists($key, $context) === true && is_scalar($this->translatedValue(value: $context[$key], languages: [])) === true) {
				continue;
			}

			$unanswered[] = $key;
		}

		return array_values(array_unique($unanswered));
	}//end unanswered()

	/**
	 * The value a language map shows, or the value itself when it is not one.
	 *
	 * A language map is a non-empty object whose keys are all language codes
	 * (`nl`, `en`, `en-GB`) and whose values are all scalar or null. It
	 * resolves to its value in the first language of the chain it has (a
	 * regional code also tries its base language), else its first non-empty
	 * value. Anything else is returned unchanged.
	 *
	 * @param mixed                   $value     The data or context value.
	 * @param array<int, string|null> $languages Language chain, first wins.
	 *
	 * @return mixed The resolved string, or the value unchanged.
	 *
	 * @spec openspec/changes/notification-links-in-releases-and-case-insensitive-order/specs/notificatie-engine/spec.md#requirement-a-translatable-value-must-fill-its-placeholder
	 */
	public function translatedValue(mixed $value, array $languages): mixed {
		if ($this->isLanguageMap(value: $value) === false) {
			return $value;
		}

		foreach ($this->languageCandidates(languages: $languages) as $candidate) {
			$text = ($value[$candidate] ?? null);
			if ($text !== null && (string)$text !== '') {
				return (string)$text;
			}
		}

		foreach ($value as $text) {
			if ($text !== null && (string)$text !== '') {
				return (string)$text;
			}
		}

		return $value;
	}//end translatedValue()

	/**
	 * Whether a value is a language map: language-code keys, scalar values.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool True for a non-empty language map.
	 *
	 * @spec openspec/changes/notification-links-in-releases-and-case-insensitive-order/specs/notificatie-engine/spec.md#requirement-a-translatable-value-must-fill-its-placeholder
	 */
	private function isLanguageMap(mixed $value): bool {
		if (is_array($value) === false || $value === [] || array_is_list($value) === true) {
			return false;
		}

		foreach ($value as $code => $text) {
			if (preg_match('/^[a-z]{2,3}([-_][A-Za-z0-9]{2,8})?$/', (string)$code) !== 1) {
				return false;
			}

			if ($text !== null && is_scalar($text) === false) {
				return false;
			}
		}

		return true;
	}//end isLanguageMap()

	/**
	 * The codes to try, in order: each language, then its base language.
	 *
	 * @param array<int, string|null> $languages Language chain, first wins.
	 *
	 * @return array<int, string> Codes to look up.
	 *
	 * @spec openspec/changes/notification-links-in-releases-and-case-insensitive-order/specs/notificatie-engine/spec.md#requirement-a-translatable-value-must-fill-its-placeholder
	 */
	private function languageCandidates(array $languages): array {
		$candidates = [];
		foreach ($languages as $language) {
			if (is_string($language) === false || $language === '') {
				continue;
			}

			$candidates[] = $language;
			$candidates[] = strtolower(preg_split('/[-_]/', $language)[0]);
		}

		return array_values(array_unique($candidates));
	}//end languageCandidates()

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
	 * @spec openspec/specs/notificatie-engine/spec.md
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) WriteCause::asLookup() is the ambient audit-cause frame; there is no instance to inject.
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
			$related = WriteCause::asLookup(fn () => $this->objectService->find(id: $value, _rbac: true));
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
