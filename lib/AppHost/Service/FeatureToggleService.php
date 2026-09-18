<?php

/**
 * One way to switch a feature on or off per instance (ledger row 11.15).
 *
 * The register's note: "`tenantConfiguration.features`,
 * `lib/Controller/SettingsController.php` (AI toggles); no feature-flag admin".
 * Every app that wanted a switch grew its own, so there is no one place an
 * administrator can look and no one call PHP can make.
 *
 * 🔑 DECLARED, NEVER FREE-FORM (D-1). A key nobody declared cannot be set, so
 * a toggle nobody reads cannot exist and a typo cannot quietly disable a
 * feature by writing `ai-summry: false` and being believed.
 *
 * 🔴 A STORED VALUE IS NOT A BOOLEAN. It arrives from a form and comes back
 * out of `IAppConfig` as a string, and `(bool)"false"` is TRUE. A toggle that
 * an administrator switched off and that reads as on is the whole failure this
 * change exists to prevent, so the coercion is explicit and tested.
 *
 * 🔴 AND AN UNREADABLE OVERRIDE FAILS THE WAY THE DECLARATION SAYS (ADR-102).
 * When the stored map cannot be parsed, a toggle declaring `failMode: closed`
 * resolves to false whatever its default says. A toggle guarding a
 * security-relevant path must not come back on because a JSON blob got
 * truncated; a toggle guarding a convenience should not disable itself over
 * the same accident, so it keeps its declared default.
 *
 * @category Service
 * @package  OCA\OpenRegister\AppHost\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/feature-toggle-surface/specs/apphost-settings-plane/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost\Service;

use OCA\OpenRegister\AppHost\Exception\FeatureToggleRefusedException;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The merged feature-toggle map, and the one reader PHP uses.
 *
 * @spec openspec/changes/feature-toggle-surface/specs/apphost-settings-plane/spec.md
 */
class FeatureToggleService {

	/**
	 * The app-config key the instance overrides live under, as a JSON object.
	 *
	 * One key rather than one per toggle, so reading the whole map costs one
	 * config read: `isEnabled()` is meant to be callable in a loop.
	 *
	 * @var string
	 */
	public const OVERRIDE_KEY = 'feature_toggles';

	/**
	 * The key a declaration list is carried under.
	 *
	 * @var string
	 */
	public const DECLARATION_KEY = 'features';

	/**
	 * A toggle that must read false when its override cannot be read.
	 *
	 * @var string
	 */
	public const FAIL_CLOSED = 'closed';

	/**
	 * A toggle that keeps its declared default when the override is unreadable.
	 *
	 * @var string
	 */
	public const FAIL_OPEN = 'open';

	/**
	 * What an audited toggle key is prefixed with on the trail.
	 *
	 * A settings row reading `key: "features.ai-summary"` says what was
	 * switched; a row reading `key: "feature_toggles"` with two JSON blobs
	 * beside it makes an auditor diff them by eye.
	 *
	 * @var string
	 */
	public const AUDIT_PREFIX = 'features.';

	/**
	 * The auditor, resolved lazily so the AppHost base never hard-depends on it.
	 *
	 * @var string
	 */
	private const AUDITOR = 'OCA\\OpenRegister\\Service\\Rbac\\SettingsChangeAuditor';

	/**
	 * The merged map per app, for this request only.
	 *
	 * @var array<string, array<string, bool>>
	 */
	private array $memo = [];

	/**
	 * Constructor.
	 *
	 * @param IAppConfig         $appConfig Where the overrides live.
	 * @param ContainerInterface $container For the auditor, which may be absent.
	 * @param LoggerInterface    $logger    The logger.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The declared defaults, keyed by toggle.
	 *
	 * A declaration without a `key` is not a toggle and is dropped; a
	 * declaration without a `default` defaults to FALSE, because a feature
	 * somebody forgot to give a default to is a feature nobody decided to
	 * ship on.
	 *
	 * @param array<int, mixed> $declarations The declared toggles.
	 *
	 * @return array<string, bool> The defaults.
	 *
	 * @spec openspec/changes/feature-toggle-surface/specs/apphost-settings-plane/spec.md
	 */
	public function defaults(array $declarations): array {
		$defaults = [];
		foreach ($declarations as $declaration) {
			if (is_array($declaration) === false) {
				continue;
			}

			$key = (string)($declaration['key'] ?? '');
			if ($key === '') {
				continue;
			}

			$defaults[$key] = $this->asBool(value: ($declaration['default'] ?? false));
		}

		return $defaults;
	}//end defaults()

	/**
	 * The fail mode of each declared toggle.
	 *
	 * @param array<int, mixed> $declarations The declared toggles.
	 *
	 * @return array<string, string> Key to fail mode.
	 *
	 * @spec openspec/changes/feature-toggle-surface/specs/apphost-settings-plane/spec.md
	 */
	public function failModes(array $declarations): array {
		$modes = [];
		foreach ($declarations as $declaration) {
			if (is_array($declaration) === false) {
				continue;
			}

			$key = (string)($declaration['key'] ?? '');
			if ($key === '') {
				continue;
			}

			$mode = (string)($declaration['failMode'] ?? self::FAIL_OPEN);
			$modes[$key] = ($mode === self::FAIL_CLOSED ? self::FAIL_CLOSED : self::FAIL_OPEN);
		}

		return $modes;
	}//end failModes()

	/**
	 * Why an update is refused, or null when it is acceptable.
	 *
	 * @param array<string, mixed> $overrides    The submitted overrides.
	 * @param array<int, mixed>    $declarations The declared toggles.
	 *
	 * @return string|null The first undeclared key, or null.
	 *
	 * @spec openspec/changes/feature-toggle-surface/specs/apphost-settings-plane/spec.md
	 */
	public function undeclaredKeyIn(array $overrides, array $declarations): ?string {
		$declared = $this->defaults(declarations: $declarations);
		foreach (array_keys($overrides) as $key) {
			if (array_key_exists((string)$key, $declared) === false) {
				return (string)$key;
			}
		}

		return null;
	}//end undeclaredKeyIn()

	/**
	 * The merged map: declared defaults under the instance overrides.
	 *
	 * 🔑 A STORED OVERRIDE FOR A KEY NOBODY DECLARES ANY MORE IS NOT RETURNED,
	 * and is not deleted either. Not returned, because the merged map is the
	 * answer to "what can this app switch" and an undeclared toggle is not one
	 * of those. Not deleted, because a declaration that disappears for one
	 * release would otherwise silently throw away an administrator's decision,
	 * and it would come back ON when the key returned.
	 *
	 * @param string            $app          The app.
	 * @param array<int, mixed> $declarations The declared toggles.
	 *
	 * @return array<string, bool> The effective toggles.
	 *
	 * @spec openspec/changes/feature-toggle-surface/specs/apphost-settings-plane/spec.md
	 */
	public function merged(string $app, array $declarations): array {
		if (isset($this->memo[$app]) === true) {
			return $this->memo[$app];
		}

		$defaults = $this->defaults(declarations: $declarations);
		$stored = $this->storedOverrides(app: $app);

		if ($stored === null) {
			// Unreadable: each toggle falls back the way it declared it should.
			$modes = $this->failModes(declarations: $declarations);
			$merged = [];
			foreach ($defaults as $key => $default) {
				$merged[$key] = (($modes[$key] ?? self::FAIL_OPEN) === self::FAIL_CLOSED ? false : $default);
			}

			$this->memo[$app] = $merged;
			return $merged;
		}

		$merged = $defaults;
		foreach ($stored as $key => $value) {
			if (array_key_exists((string)$key, $defaults) === false) {
				continue;
			}

			$merged[(string)$key] = $this->asBool(value: $value);
		}

		$this->memo[$app] = $merged;
		return $merged;
	}//end merged()

	/**
	 * Whether one feature is on.
	 *
	 * An UNDECLARED key reads FALSE. Asking about a toggle nobody declared is
	 * a question with no answer, and the safe reading of no answer is "this
	 * feature is not on" — the opposite would turn every typo in a guard into
	 * an open door.
	 *
	 * @param string            $app          The app.
	 * @param string            $key          The toggle.
	 * @param array<int, mixed> $declarations The declared toggles.
	 *
	 * @return bool True when the feature is on.
	 *
	 * @spec openspec/changes/feature-toggle-surface/specs/apphost-settings-plane/spec.md
	 */
	public function isEnabled(string $app, string $key, array $declarations = []): bool {
		$merged = $this->merged(app: $app, declarations: $declarations);

		return (($merged[$key] ?? false) === true);
	}//end isEnabled()

	/**
	 * Write the overrides, audit each changed toggle, return the merged map.
	 *
	 * @param string               $app          The app.
	 * @param array<int, mixed>    $declarations The declared toggles.
	 * @param array<string, mixed> $overrides    The submitted overrides.
	 *
	 * @return array<string, bool> The merged map after the write.
	 *
	 * @throws FeatureToggleRefusedException When a key is not declared.
	 *
	 * @spec openspec/changes/feature-toggle-surface/specs/apphost-settings-plane/spec.md
	 */
	public function update(string $app, array $declarations, array $overrides): array {
		$undeclared = $this->undeclaredKeyIn(overrides: $overrides, declarations: $declarations);
		if ($undeclared !== null) {
			throw new FeatureToggleRefusedException(appId: $app, key: $undeclared);
		}

		$before = $this->merged(app: $app, declarations: $declarations);

		$stored = ($this->storedOverrides(app: $app) ?? []);
		foreach ($overrides as $key => $value) {
			$stored[(string)$key] = $this->asBool(value: $value);
		}

		$this->appConfig->setValueString($app, self::OVERRIDE_KEY, (string)json_encode($stored));

		// The memo is this request's answer and it is now stale. Dropping it
		// here rather than recomputing keeps one place where the map is built.
		unset($this->memo[$app]);

		$after = $this->merged(app: $app, declarations: $declarations);
		$this->audit(app: $app, before: $before, after: $after);

		return $after;
	}//end update()

	/**
	 * The stored overrides, or null when they cannot be read.
	 *
	 * Null and `[]` are different answers: nothing stored yet is an empty map,
	 * and a blob that will not parse is an unknown one, which is what the fail
	 * mode is for.
	 *
	 * @param string $app The app.
	 *
	 * @return array<string, mixed>|null The overrides, or null when unreadable.
	 *
	 * @spec openspec/changes/feature-toggle-surface/specs/apphost-settings-plane/spec.md
	 */
	public function storedOverrides(string $app): ?array {
		$raw = $this->appConfig->getValueString($app, self::OVERRIDE_KEY, '');
		if ($raw === '') {
			return [];
		}

		$decoded = json_decode($raw, true);
		if (is_array($decoded) === false) {
			$this->logger->error(
				sprintf('[AppHost:%s] feature toggle overrides could not be read; declared fail modes apply', $app)
			);
			return null;
		}

		return $decoded;
	}//end storedOverrides()

	/**
	 * Record each changed toggle on the settings trail.
	 *
	 * Never throws: the toggle has already been written, so failing here would
	 * report a failed save for a change that happened.
	 *
	 * @param string              $app    The app.
	 * @param array<string, bool> $before The map before.
	 * @param array<string, bool> $after  The map after.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/feature-toggle-surface/specs/apphost-settings-plane/spec.md
	 */
	private function audit(string $app, array $before, array $after): void {
		try {
			$auditor = $this->container->get(self::AUDITOR);
			if (method_exists($auditor, 'recordUpdate') === false) {
				return;
			}

			$auditor->recordUpdate(
				$app,
				$this->prefixed(map: $before),
				$this->prefixed(map: $after),
				[]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				sprintf('[AppHost:%s] feature toggle changed but not audited: %s', $app, $e->getMessage())
			);
		}
	}//end audit()

	/**
	 * The map with its keys prefixed, so a trail row names a toggle.
	 *
	 * @param array<string, bool> $map The map.
	 *
	 * @return array<string, bool> The prefixed map.
	 */
	private function prefixed(array $map): array {
		$prefixed = [];
		foreach ($map as $key => $value) {
			$prefixed[self::AUDIT_PREFIX . $key] = $value;
		}

		return $prefixed;
	}//end prefixed()

	/**
	 * A stored or submitted value read as the boolean it means.
	 *
	 * 🔴 `(bool)"false"` IS TRUE, and `IAppConfig` hands back strings. The
	 * strings below are the ones a form, a JSON body and a config store
	 * actually produce for "off"; everything else falls through to PHP's own
	 * truthiness.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool What it means.
	 */
	private function asBool(mixed $value): bool {
		if (is_string($value) === true) {
			return (in_array(strtolower(trim($value)), ['', '0', 'false', 'off', 'no'], true) === false);
		}

		return (bool)$value;
	}//end asBool()
}//end class
