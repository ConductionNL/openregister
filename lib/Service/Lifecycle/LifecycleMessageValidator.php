<?php

/**
 * LifecycleMessageValidator: the shape of a transition's refusal message.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Lifecycle
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

namespace OCA\OpenRegister\Service\Lifecycle;

/**
 * Shape-checks a transition's refusal `message`: a string or a per-locale map.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class LifecycleMessageValidator {

	use LifecycleErrorBuilding;

	/**
	 * Shape-check a transition's optional refusal `message`.
	 *
	 * Accepts a non-empty string, or a per-locale map optionally carrying
	 * `defaultLocale`. The map form mirrors the `x-openregister-notifications`
	 * dialect key for key, so an author who has written one has written both.
	 * Every malformed shape returns the single code `lifecycle-message-malformed`.
	 *
	 * @param mixed $message Raw value of the transition's `message` key.
	 * @param string $action The transition name, for the error message.
	 *
	 * @return array<int, array{code: string, message: string}> Errors (empty = valid).
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function validate(mixed $message, string $action): array {
		if ($message === '') {
			return [$this->messageError(template: 'Transition "%s" `message` must be a non-empty string when present.', values: [$action])];
		}

		if (is_string($message) === true) {
			return [];
		}

		if (is_array($message) === false) {
			return [$this->messageError(template: 'Transition "%s" `message` must be a string or a per-locale map.', values: [$action])];
		}

		return $this->validateMessageMap(message: $message, action: $action);
	}//end validate()


	/**
	 * One `lifecycle-message-malformed` error.
	 *
	 * @param string            $template The message template.
	 * @param array<int, mixed> $values   The template's values.
	 *
	 * @return array{code: string, message: string}
	 */
	private function messageError(string $template, array $values): array {
		return $this->error(code: 'lifecycle-message-malformed', message: sprintf($template, ...$values));
	}//end messageError()


	/**
	 * Shape-check the per-locale map form of a transition `message`.
	 *
	 * At least one locale, every locale a non-empty string, and a
	 * `defaultLocale` (when present) naming a declared locale.
	 *
	 * @param array<mixed> $message The per-locale map.
	 * @param string $action The transition name, for the error message.
	 *
	 * @return array<int, array{code: string, message: string}> Errors (empty = valid).
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function validateMessageMap(array $message, string $action): array {
		$localeKeys = array_filter(
			array_keys($message),
			static fn ($key): bool => $key !== 'defaultLocale' && is_string($key) === true
		);

		$errors = [];
		if (count($localeKeys) === 0) {
			$template = 'Transition "%s" `message` map must declare at least one locale (e.g. nl, en).';
			$errors[] = $this->messageError(template: $template, values: [$action]);
		}

		foreach (array_intersect_key($message, array_flip($localeKeys)) as $locale => $text) {
			$errors = array_merge($errors, $this->localeTextErrors(text: $text, names: [$action, $locale]));
		}

		return array_merge($errors, $this->validateDefaultLocale(message: $message, action: $action));
	}//end validateMessageMap()


	/**
	 * Check that one locale's text is a non-empty string.
	 *
	 * @param mixed                $text  The locale's text.
	 * @param array<int, int|string> $names The action and the locale.
	 *
	 * @return array<int, array{code: string, message: string}> Errors (empty = valid).
	 */
	private function localeTextErrors(mixed $text, array $names): array {
		if (is_string($text) === true && $text !== '') {
			return [];
		}

		$template = 'Transition "%s" `message` for locale "%s" must be a non-empty string.';

		return [$this->messageError(template: $template, values: $names)];
	}//end localeTextErrors()


	/**
	 * Check that a message map's `defaultLocale`, when present, names a declared locale.
	 *
	 * @param array<mixed> $message The per-locale map.
	 * @param string $action The transition name, for the error message.
	 *
	 * @return array<int, array{code: string, message: string}> The error, if any.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function validateDefaultLocale(array $message, string $action): array {
		$defaultLocale = ($message['defaultLocale'] ?? null);
		if ($defaultLocale === null || (is_string($defaultLocale) === true && isset($message[$defaultLocale]) === true)) {
			return [];
		}

		$shown = gettype($defaultLocale);
		if (is_string($defaultLocale) === true) {
			$shown = $defaultLocale;
		}

		$template = 'Transition "%s" `message` defaultLocale "%s" is not declared in the message map.';

		return [$this->messageError(template: $template, values: [$action, $shown])];
	}//end validateDefaultLocale()
}//end class
