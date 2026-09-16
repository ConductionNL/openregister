<?php

/**
 * The one proxy setting, read in one place.
 *
 * WHY ONE. Most gemeenten have no direct egress: every outbound call leaves
 * through the organisation's proxy or it does not leave at all. This app makes
 * outbound calls from sixteen files, and the moment there are two ways to
 * build a client, one of them bypasses the proxy. The one that bypasses it is
 * always the one that works on the developer's laptop and fails in production,
 * because a laptop has direct egress and a gemeente does not (design D-6).
 *
 * So the setting is read here and nowhere else, and
 * {@see OutboundHttpClient} is the only way to reach the network.
 *
 * 🔴 THE PASSWORD IS NEVER PUBLISHED. `describe()` exists for the settings
 * screen and the capabilities answer, and it reports whether a proxy is set and
 * what its host is, never its credentials. A proxy URL with a password in it is
 * a credential, and this app has already learnt that a secret nested in an
 * untyped configuration blob has no property to hang `writeOnly` on.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Outbound
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/api-as-a-versioned-surface/specs/api-surface-governance/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Outbound;

use OCP\IAppConfig;
use OCP\IConfig;
use Throwable;

/**
 * Resolves the administered proxy and turns it into request options.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Outbound
 */
class ProxySettings {

	/**
	 * The app the setting lives under.
	 *
	 * @var string
	 */
	private const APP_ID = 'openregister';

	/**
	 * Configuration key holding the proxy URL.
	 *
	 * @var string
	 */
	public const PROXY_KEY = 'outbound_proxy';

	/**
	 * Configuration key holding the hosts that bypass the proxy.
	 *
	 * A comma-separated list. An instance behind a proxy still has to reach
	 * itself and its own neighbours on the internal network, and routing those
	 * through an external proxy is how a working federation link becomes a
	 * timeout nobody can explain.
	 *
	 * @var string
	 */
	public const NO_PROXY_KEY = 'outbound_proxy_exceptions';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig Reads this app's own setting.
	 * @param IConfig $systemConfig Reads the Nextcloud-wide proxy, as a fallback.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly IConfig $systemConfig,
	) {

	}//end __construct()

	/**
	 * The proxy URL in force, or null when there is none.
	 *
	 * This app's own setting wins, and Nextcloud's instance-wide `proxy` is the
	 * fallback. An administrator who already configured the server's proxy has
	 * said what they want; making them say it twice is how one of the two ends
	 * up stale.
	 *
	 * @return string|null The proxy URL, or null.
	 *
	 * @spec openspec/changes/api-as-a-versioned-surface/specs/api-surface-governance/spec.md#requirement-the-instance-answers-the-well-known-paths-and-honours-an-administered-proxy-req-avs-004
	 */
	public function proxy(): ?string {
		$own = $this->readAppValue(key: self::PROXY_KEY);
		if ($own !== null) {
			return $own;
		}

		try {
			$system = trim((string)$this->systemConfig->getSystemValue('proxy', ''));
		} catch (Throwable) {
			return null;
		}

		if ($system === '') {
			return null;
		}

		return $system;

	}//end proxy()

	/**
	 * The hosts that are reached directly, bypassing the proxy.
	 *
	 * @return array<int, string> The host patterns.
	 *
	 * @spec openspec/changes/api-as-a-versioned-surface/specs/api-surface-governance/spec.md
	 */
	public function exceptions(): array {
		$raw = $this->readAppValue(key: self::NO_PROXY_KEY);
		if ($raw === null) {
			try {
				$system = $this->systemConfig->getSystemValue('proxyexclude', []);
			} catch (Throwable) {
				$system = [];
			}

			if (is_array($system) === false) {
				return [];
			}

			return array_values(array_filter(array_map('strval', $system), static fn (string $h): bool => (trim($h) !== '')));
		}

		$hosts = [];
		foreach (explode(',', $raw) as $host) {
			$trimmed = trim($host);
			if ($trimmed !== '') {
				$hosts[] = $trimmed;
			}
		}

		return $hosts;

	}//end exceptions()

	/**
	 * The request options every outbound call carries.
	 *
	 * Guzzle's `proxy` option takes either a string or a map with `http`,
	 * `https` and `no`. The map form is used whenever there are exceptions,
	 * because the string form has no way to express them and an instance that
	 * cannot reach its own neighbours directly is an instance whose federation
	 * quietly stops working.
	 *
	 * @return array<string, mixed> The options, or an empty array when no proxy is set.
	 *
	 * @spec openspec/changes/api-as-a-versioned-surface/specs/api-surface-governance/spec.md#requirement-the-instance-answers-the-well-known-paths-and-honours-an-administered-proxy-req-avs-004
	 */
	public function requestOptions(): array {
		$proxy = $this->proxy();
		if ($proxy === null) {
			return [];
		}

		$exceptions = $this->exceptions();
		if ($exceptions === []) {
			return ['proxy' => $proxy];
		}

		return [
			'proxy' => [
				'http' => $proxy,
				'https' => $proxy,
				'no' => $exceptions,
			],
		];

	}//end requestOptions()

	/**
	 * Whether an administered proxy is in force.
	 *
	 * @return bool True when outbound calls go through a proxy.
	 *
	 * @spec openspec/changes/api-as-a-versioned-surface/specs/api-surface-governance/spec.md
	 */
	public function isConfigured(): bool {
		return ($this->proxy() !== null);

	}//end isConfigured()

	/**
	 * The proxy as it may safely be shown, credentials removed.
	 *
	 * @return array<string, mixed> The description.
	 *
	 * @spec openspec/changes/api-as-a-versioned-surface/specs/api-surface-governance/spec.md
	 */
	public function describe(): array {
		$proxy = $this->proxy();
		if ($proxy === null) {
			return ['configured' => false];
		}

		return [
			'configured' => true,
			'endpoint' => self::withoutCredentials(url: $proxy),
			'exceptions' => $this->exceptions(),
		];

	}//end describe()

	/**
	 * A proxy URL with any user information stripped out.
	 *
	 * @param string $url The proxy URL.
	 *
	 * @return string The URL, without credentials.
	 *
	 * @spec openspec/changes/api-as-a-versioned-surface/specs/api-surface-governance/spec.md
	 */
	public static function withoutCredentials(string $url): string {
		// No user-information delimiter means nothing to strip, so the value can
		// be shown exactly as administered. This also covers the scheme-less
		// form (`proxy.test:3128`), which parse_url reads as a path with no
		// host and which the branch below would otherwise reduce to "(set)".
		if (str_contains($url, '@') === false) {
			return $url;
		}

		$parts = parse_url($url);
		if (is_array($parts) === false) {
			// Unparseable. Publishing it raw could publish a password, so
			// publish nothing but the fact that something is set.
			return '(set)';
		}

		$rebuilt = '';
		if (isset($parts['scheme']) === true) {
			$rebuilt .= ($parts['scheme'] . '://');
		}

		$rebuilt .= ($parts['host'] ?? '');

		if (isset($parts['port']) === true) {
			$rebuilt .= (':' . $parts['port']);
		}

		if ($rebuilt === '') {
			return '(set)';
		}

		return $rebuilt;

	}//end withoutCredentials()

	/**
	 * Read one of this app's own values, or null when it is unset.
	 *
	 * @param string $key The configuration key.
	 *
	 * @return string|null The value, or null.
	 */
	private function readAppValue(string $key): ?string {
		try {
			$value = trim((string)$this->appConfig->getValueString(self::APP_ID, $key, ''));
		} catch (Throwable) {
			return null;
		}

		if ($value === '') {
			return null;
		}

		return $value;

	}//end readAppValue()
}//end class
