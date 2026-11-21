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

namespace OCA\OpenRegister\Notifications\Service\Notification;

use OCA\OpenRegister\Formats\UuidFormat;
use OCA\OpenRegister\Service\WriteCause;
use Psr\Log\LoggerInterface;

/**
 * Interpolates `{{ key }}` placeholders against data and context.
 */
class NotificationTemplating implements NotificationTemplatingInterface {

	/**
	 * A `{{ key }}` placeholder; group 1 is the key.
	 */
	private const PLACEHOLDER = '/\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}/';

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
	 * @param \OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface|null $objectService Object resolver for relation display names (RBAC-scoped).
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly ?\OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface $objectService = null,
	) {

	}//end __construct()

	/**
	 * Fill the `{{ key }}` placeholders of a template.
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
	 * @param string            $template The template carrying `{{ key }}` placeholders.
	 * @param PlaceholderValues $values   The primary data (object data, or a flow item's json), the secondary
	 *                                    lookup values and the language chain for a language map.
	 *
	 * @return string The interpolated string.
	 *
	 * @spec openspec/specs/flow-messaging-nodes/spec.md#requirement-flows-send-through-the-notification-subsystem-never-beside-it
	 * @spec openspec/changes/notification-links-in-releases-and-case-insensitive-order/specs/notificatie-engine/spec.md#requirement-a-translatable-value-must-fill-its-placeholder
	 */
	public function render(string $template, PlaceholderValues $values): string {
		return preg_replace_callback(
			self::PLACEHOLDER,
			fn (array $matches): string => $this->fillPlaceholder(matches: $matches, values: $values),
			$template
		) ?? $template;
	}//end render()

	/**
	 * The text one placeholder renders as: the data value, else the context value.
	 *
	 * A key context lacks reads as null, so it is left as it was found: a hole
	 * is harder to notice than a leak (see the interpolate() docblock).
	 *
	 * @param array<int, string> $matches The placeholder as written and its key.
	 * @param PlaceholderValues  $values  The data, the context and the language chain.
	 *
	 * @return string The rendered text.
	 */
	private function fillPlaceholder(array $matches, PlaceholderValues $values): string {
		$key = $matches[1];
		if (array_key_exists($key, $values->data) === true) {
			return $this->renderDataValue(value: $this->translatedValue(value: $values->data[$key], languages: $values->languages), placeholder: $matches[0]);
		}

		$value = $this->translatedValue(value: ($values->context[$key] ?? null), languages: $values->languages);
		return $this->renderScalar(value: $value, placeholder: $matches[0]);
	}//end fillPlaceholder()

	/**
	 * Render a data value: a relation UUID shows the related object's display
	 * name, so "{{client}}" reads "Acme Gemeente BV", not a UUID string.
	 *
	 * @param mixed  $value       The translated data value.
	 * @param string $placeholder The placeholder as written, kept for a non-scalar.
	 *
	 * @return string
	 */
	private function renderDataValue(mixed $value, string $placeholder): string {
		if (is_scalar($value) === false) {
			return $placeholder;
		}

		$raw = (string)$value;

		return $this->renderScalar(value: ($this->resolveRelationDisplayName(value: $raw) ?? $raw), placeholder: $placeholder);
	}//end renderDataValue()

	/**
	 * Render a scalar HTML-escaped, or keep the placeholder for anything else.
	 *
	 * @param mixed  $value       The translated value.
	 * @param string $placeholder The placeholder as written.
	 *
	 * @return string
	 */
	private function renderScalar(mixed $value, string $placeholder): string {
		if (is_scalar($value) === false) {
			return $placeholder;
		}

		return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
	}//end renderScalar()

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
	 * @param string            $template The template carrying `{{ key }}` placeholders.
	 * @param PlaceholderValues $values   The primary data and the secondary lookup values.
	 *
	 * @return array<int, string> The unanswerable names, in the order they appear, without repeats.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	public function unanswered(string $template, PlaceholderValues $values): array {
		if (preg_match_all(self::PLACEHOLDER, $template, $matches) === false) {
			return [];
		}

		$unanswered = array_filter(
			$matches[1],
			fn (string $key): bool => $this->answers(source: $values->data, key: $key) === false
				&& $this->answers(source: $values->context, key: $key) === false
		);

		return array_values(array_unique($unanswered));
	}//end unanswered()

	/**
	 * Whether a source answers a key with something interpolate() can render.
	 *
	 * A non-scalar counts as unanswered, because that is exactly what
	 * interpolate() cannot render either. Asking a different question here
	 * than the renderer asks is how a guard comes to disagree with the thing
	 * it guards.
	 *
	 * @param array<string, mixed> $source Data or context.
	 * @param string               $key    The placeholder key.
	 *
	 * @return bool
	 */
	private function answers(array $source, string $key): bool {
		return array_key_exists($key, $source) === true && is_scalar($this->translatedValue(value: $source[$key], languages: [])) === true;
	}//end answers()

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

		foreach ($this->textsInOrder(map: $value, languages: $languages) as $text) {
			if ($text !== null && (string)$text !== '') {
				return (string)$text;
			}
		}

		return $value;
	}//end translatedValue()

	/**
	 * A language map's values in lookup order: the chain's languages, then the map's own order.
	 *
	 * @param array<string, mixed>    $map       The language map.
	 * @param array<int, string|null> $languages Language chain, first wins.
	 *
	 * @return array<int, mixed>
	 */
	private function textsInOrder(array $map, array $languages): array {
		$preferred = array_map(
			static fn (string $candidate): mixed => ($map[$candidate] ?? null),
			$this->languageCandidates(languages: $languages)
		);

		return array_merge($preferred, array_values($map));
	}//end textsInOrder()

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
		// An empty array is a list, so this also refuses [].
		if (is_array($value) === false || array_is_list($value) === true) {
			return false;
		}

		foreach ($value as $code => $text) {
			if ($this->isLanguageEntry(code: (string)$code, text: $text) === false) {
				return false;
			}
		}

		return true;
	}//end isLanguageMap()

	/**
	 * Whether one map entry has a language-code key and a scalar or null value.
	 *
	 * @param string $code The key.
	 * @param mixed  $text The value.
	 *
	 * @return bool
	 */
	private function isLanguageEntry(string $code, mixed $text): bool {
		return preg_match('/^[a-z]{2,3}([-_][A-Za-z0-9]{2,8})?$/', $code) === 1 && ($text === null || is_scalar($text) === true);
	}//end isLanguageEntry()

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
		if (UuidFormat::isCanonical(value: $value) === false) {
			return null;
		}

		if ($this->objectService === null) {
			return null;
		}

		if (array_key_exists($value, $this->relationDisplayCache) === true) {
			return $this->relationDisplayCache[$value];
		}

		$name = $this->lookUpDisplayName(uuid: $value);
		$this->relationDisplayCache[$value] = $name;

		return $name;
	}//end resolveRelationDisplayName()

	/**
	 * Ask the object service for the related object's display name.
	 *
	 * @param string $uuid The related object's UUID.
	 *
	 * @return string|null The display name, or null when nothing named resolves or the lookup fails.
	 */
	private function lookUpDisplayName(string $uuid): ?string {
		try {
			$related = WriteCause::asLookup(fn () => $this->objectService->find(id: $uuid, _rbac: true));
			if ($related === null) {
				return null;
			}

			return $this->nonEmptyName(name: $related->getName());
		} catch (\Throwable $e) {
			$this->logger->debug('[NotificationTemplating] relation display-name resolve failed: ' . $e->getMessage());
			return null;
		}
	}//end lookUpDisplayName()

	/**
	 * A name when it is a non-empty string.
	 *
	 * @param mixed $name The object's name.
	 *
	 * @return string|null The name, or null when it is absent or empty.
	 */
	private function nonEmptyName(mixed $name): ?string {
		if (is_string($name) === true && $name !== '') {
			return $name;
		}

		return null;
	}//end nonEmptyName()
}//end class
