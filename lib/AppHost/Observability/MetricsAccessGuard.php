<?php

/**
 * OpenRegister AppHost — Metrics Access Guard
 *
 * Decides who may read an app's Prometheus metrics: an admin session, a
 * scraper presenting the app's configured bearer token, or a caller from one of
 * the app's configured address ranges. Nothing is configured by default, so
 * the endpoint stays admin-only until an admin opts a scraper in.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category AppHost
 * @package  OCA\OpenRegister\AppHost\Observability
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost\Observability;

use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Security\Ip\IFactory;
use Throwable;

/**
 * The access decision for `GET /api/metrics`, as the HTTP status to answer.
 *
 * Order: an admin session is let through; a bearer token is then judged on its
 * own (a wrong one is refused, it never falls through to the address check);
 * then the caller's address against the allowed ranges. Anything that cannot be
 * read or parsed counts as not configured, so a failure refuses, never admits.
 *
 * @spec openspec/changes/production-observability/specs/production-observability/spec.md#requirement-prometheus-metrics-endpoint
 */
class MetricsAccessGuard {

	/**
	 * App config key holding the scraper bearer token of the calling app.
	 */
	public const CONFIG_TOKEN = 'metrics_api_token';

	/**
	 * App config key holding the comma-separated address ranges allowed without authentication.
	 */
	public const CONFIG_ALLOWED_IPS = 'metrics_allowed_ips';

	/**
	 * Constructor.
	 *
	 * @param IUserSession  $userSession  The session user, if any.
	 * @param IGroupManager $groupManager Tells whether that user is an admin.
	 * @param IAppConfig    $appConfig    The calling app's token and address ranges.
	 * @param IFactory      $ipFactory    Parses addresses and ranges.
	 *
	 * @spec openspec/changes/production-observability/specs/production-observability/spec.md#requirement-prometheus-metrics-endpoint
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly IAppConfig $appConfig,
		private readonly IFactory $ipFactory,
	) {
	}//end __construct()

	/**
	 * The status to answer a metrics request with: 200 to serve it, 401 or 403 to refuse it.
	 *
	 * @param string   $appId   The app whose metrics are asked for (its config is read).
	 * @param IRequest $request The request.
	 *
	 * @return int Http::STATUS_OK, Http::STATUS_UNAUTHORIZED or Http::STATUS_FORBIDDEN.
	 *
	 * @spec openspec/changes/production-observability/specs/production-observability/spec.md#requirement-prometheus-metrics-endpoint
	 */
	public function decide(string $appId, IRequest $request): int {
		if ($this->isAdminSession() === true) {
			return Http::STATUS_OK;
		}

		$token = self::bearerToken(header: (string)$request->getHeader('Authorization'));
		if ($token !== null) {
			if ($this->tokenMatches(appId: $appId, token: $token) === true) {
				return Http::STATUS_OK;
			}

			return Http::STATUS_FORBIDDEN;
		}

		$ranges = $this->allowedRanges(appId: $appId);
		if ($ranges !== []) {
			if ($this->addressAllowed(address: (string)$request->getRemoteAddress(), ranges: $ranges) === true) {
				return Http::STATUS_OK;
			}

			return Http::STATUS_FORBIDDEN;
		}

		if ($this->userSession->getUser() !== null) {
			return Http::STATUS_FORBIDDEN;
		}

		return Http::STATUS_UNAUTHORIZED;
	}//end decide()

	/**
	 * Whether the request carries a bearer token (right or wrong).
	 *
	 * @param IRequest $request The request.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/production-observability/specs/production-observability/spec.md#requirement-prometheus-metrics-endpoint
	 */
	public function presentsToken(IRequest $request): bool {
		return self::bearerToken(header: (string)$request->getHeader('Authorization')) !== null;
	}//end presentsToken()

	/**
	 * Whether the session user is an admin.
	 *
	 * @return bool
	 */
	private function isAdminSession(): bool {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return false;
		}

		return $this->groupManager->isAdmin($user->getUID()) === true;
	}//end isAdminSession()

	/**
	 * The token of a `Bearer` Authorization header, or null when there is none.
	 *
	 * @param string $header The Authorization header.
	 *
	 * @return string|null The token; an empty string for a `Bearer` header without one.
	 */
	private static function bearerToken(string $header): ?string {
		if (preg_match('/^Bearer\s*(.*)$/i', trim($header), $matches) !== 1) {
			return null;
		}

		return trim($matches[1]);
	}//end bearerToken()

	/**
	 * Whether the token equals the app's configured one; never when none is configured.
	 *
	 * @param string $appId The app.
	 * @param string $token The presented token.
	 *
	 * @return bool
	 */
	private function tokenMatches(string $appId, string $token): bool {
		$configured = $this->configValue(appId: $appId, key: self::CONFIG_TOKEN);
		if ($configured === '' || $token === '') {
			return false;
		}

		return hash_equals($configured, $token);
	}//end tokenMatches()

	/**
	 * The app's allowed address ranges, unparsable entries left out.
	 *
	 * @param string $appId The app.
	 *
	 * @return \OCP\Security\Ip\IRange[]
	 */
	private function allowedRanges(string $appId): array {
		$ranges = [];
		foreach (explode(',', $this->configValue(appId: $appId, key: self::CONFIG_ALLOWED_IPS)) as $entry) {
			$entry = trim($entry);
			if ($entry === '') {
				continue;
			}

			try {
				$ranges[] = $this->ipFactory->rangeFromString($entry);
			} catch (Throwable $unparsable) {
				unset($unparsable);
			}
		}

		return $ranges;
	}//end allowedRanges()

	/**
	 * Whether the address lies in one of the ranges; never for an unparsable address.
	 *
	 * @param string                    $address The caller's address.
	 * @param \OCP\Security\Ip\IRange[] $ranges  The allowed ranges.
	 *
	 * @return bool
	 */
	private function addressAllowed(string $address, array $ranges): bool {
		try {
			$parsed = $this->ipFactory->addressFromString($address);
		} catch (Throwable $unparsable) {
			unset($unparsable);
			return false;
		}

		foreach ($ranges as $range) {
			if ($range->contains($parsed) === true) {
				return true;
			}
		}

		return false;
	}//end addressAllowed()

	/**
	 * A config value of the app; empty when it is unset or cannot be read.
	 *
	 * @param string $appId The app.
	 * @param string $key   The config key.
	 *
	 * @return string
	 */
	private function configValue(string $appId, string $key): string {
		try {
			return trim($this->appConfig->getValueString($appId, $key, ''));
		} catch (Throwable $unreadable) {
			unset($unreadable);
			return '';
		}
	}//end configValue()
}//end class
