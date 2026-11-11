<?php

/**
 * RuleMessageRenderer: the subject and body text of a notification rule.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Notifications\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Notification;

use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;

/**
 * Picks a rule's template for a language and interpolates it.
 *
 * A template is a plain string or a map keyed by ISO 639-1 code with an
 * optional `defaultLocale`. New locales beyond NL/EN just need to be added to
 * the schema annotation; no code change is required.
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */
final class RuleMessageRenderer {

	/**
	 * Constructor.
	 *
	 * @param NotificationTemplating $templating The dialect's placeholder evaluator.
	 * @param array<int, string>     $languages  The register's languages, default first.
	 * @param IAppManager|null       $appManager App manager for the auto-derived body.
	 * @param LoggerInterface        $logger     The logger.
	 */
	public function __construct(
		private readonly NotificationTemplating $templating,
		private readonly array $languages,
		private readonly ?IAppManager $appManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The subject; the rule name when no template is usable.
	 *
	 * @param MessageTarget $target The firing and language.
	 *
	 * @return string
	 */
	public function subject(MessageTarget $target): string {
		$text = $this->rendered(template: $target->firing->subjectTemplate(), target: $target);

		return ($text ?? $target->firing->ruleId);
	}//end subject()

	/**
	 * The body of a rule, distinct from the title.
	 *
	 * The rule's `message` template when it declares one. Without one the body
	 * is "Open in {AppName}." for a rule with actions, and empty otherwise.
	 *
	 * @param MessageTarget $target The firing and language.
	 *
	 * @return string The body, or '' for the empty back-compat body.
	 */
	public function body(MessageTarget $target): string {
		$firing = $target->firing;
		if (self::declaresTemplate(template: $firing->messageTemplate()) === true) {
			return $this->declaredBody(target: $target);
		}

		if (count($firing->actions) === 0) {
			return '';
		}

		return sprintf('Open in %s.', $this->appDisplayName(app: $firing->originApp));
	}//end body()

	/**
	 * The body the rule's own `message` template gives, '' when it gives none.
	 *
	 * @param MessageTarget $target The firing and language.
	 *
	 * @return string
	 */
	public function declaredBody(MessageTarget $target): string {
		return ($this->rendered(template: $target->firing->messageTemplate(), target: $target) ?? '');
	}//end declaredBody()

	/**
	 * Whether a `message` value is a template at all.
	 *
	 * @param mixed $template The value.
	 *
	 * @return bool
	 */
	private static function declaresTemplate(mixed $template): bool {
		return (is_string($template) === true && $template !== '')
			|| (is_array($template) === true && $template !== []);
	}//end declaresTemplate()

	/**
	 * The template for the target's language, interpolated, null when there is none.
	 *
	 * @param mixed         $template The raw value.
	 * @param MessageTarget $target   The firing and language.
	 *
	 * @return string|null
	 */
	private function rendered(mixed $template, MessageTarget $target): ?string {
		$text = self::pick(template: $template, target: $target);
		if ($text === null) {
			return null;
		}

		return $this->templating->render(
			template: $text,
			values: new PlaceholderValues(
				data: $target->firing->data,
				context: $target->firing->context,
				languages: array_merge([$target->locale], $this->languages)
			)
		);
	}//end rendered()

	/**
	 * The text of a template for the target's language.
	 *
	 * @param mixed         $template The raw value.
	 * @param MessageTarget $target   The firing and language.
	 *
	 * @return string|null
	 */
	private static function pick(mixed $template, MessageTarget $target): string|null {
		if (is_array($template) === true) {
			return self::pickFromMap(map: $template, locale: $target->locale);
		}

		if (is_string($template) === true && $template !== '') {
			return $template;
		}

		return null;
	}//end pick()

	/**
	 * The text of a locale map: the recipient's locale, the declared default
	 * (`nl` when none is declared), the NL/EN baseline, then the first locale
	 * in declaration order.
	 *
	 * @param array<mixed> $map    The map.
	 * @param string|null  $locale The locale.
	 *
	 * @return string|null
	 */
	private static function pickFromMap(array $map, ?string $locale): ?string {
		$default = ($map['defaultLocale'] ?? null);
		unset($map['defaultLocale']);

		$candidates = array_merge(array_filter([$locale, $default, 'nl', 'en'], 'is_string'), array_keys($map));
		foreach ($candidates as $key) {
			if (is_string($map[$key] ?? null) === true) {
				return $map[$key];
			}
		}

		return null;
	}//end pickFromMap()

	/**
	 * An app's human display name for the auto-derived body.
	 *
	 * Reads the app's `name` from IAppManager::getAppInfo() when the manager is
	 * available; falls back to ucfirst(app) when the manager is absent or the
	 * lookup yields no usable name.
	 *
	 * @param string $app The app id.
	 *
	 * @return string
	 */
	private function appDisplayName(string $app): string {
		$name = $this->declaredAppName(app: $app);
		if ($name !== '') {
			return $name;
		}

		return ucfirst($app);
	}//end appDisplayName()

	/**
	 * The `name` in an app's info, '' when there is none to read.
	 *
	 * @param string $app The app id.
	 *
	 * @return string
	 */
	private function declaredAppName(string $app): string {
		$name = ($this->appInfo(app: $app)['name'] ?? '');
		if (is_string($name) === false) {
			return '';
		}

		return $name;
	}//end declaredAppName()

	/**
	 * An app's info, empty when the manager is absent or the lookup fails.
	 *
	 * @param string $app The app id.
	 *
	 * @return array<string, mixed>
	 */
	private function appInfo(string $app): array {
		if ($this->appManager === null || $app === '') {
			return [];
		}

		try {
			return ($this->appManager->getAppInfo($app) ?? []);
		} catch (\Throwable $e) {
			$this->logger->debug(
				sprintf('[AnnotationNotificationDispatcher] app display-name lookup failed: %s', $e->getMessage())
			);
			return [];
		}
	}//end appInfo()
}//end class
