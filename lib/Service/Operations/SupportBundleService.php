<?php

/**
 * SupportBundleService: the instance's own facts, redacted where it is built.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Operations
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Operations;

use DateTime;
use OCA\OpenRegister\Db\JobRun;
use OCA\OpenRegister\Db\JobRunMapper;
use OCP\App\IAppManager;
use OCP\IConfig;
use Throwable;

/**
 * Builds the bundle an administrator attaches to a support call.
 *
 * D-7: a bundle assembled from the live configuration carries credentials
 * unless something removes them, and the redaction happens HERE, where the
 * bundle is built, rather than in whatever renders it. A redaction applied on
 * the way out is a redaction one new caller can skip.
 *
 * The rule is a key-name rule, not a value rule: anything whose key looks like
 * a secret is replaced by a marker, and the KEY is kept. The key is what makes
 * the bundle useful ("so a token IS configured"); the value is what must never
 * leave the instance.
 *
 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-a-support-bundle-and-the-instances-own-facts-are-readable-req-aoc-007
 */
class SupportBundleService {

	/**
	 * The app the bundle is about.
	 *
	 * @var string
	 */
	public const APP_ID = 'openregister';

	/**
	 * What a redacted value is replaced by.
	 *
	 * A fixed marker, never a masked prefix: a masked prefix still leaks the
	 * first characters, and length still leaks which credential it is.
	 *
	 * @var string
	 */
	public const REDACTED = '***redacted***';

	/**
	 * The key fragments that mark a value as a secret.
	 *
	 * Matched case-insensitively anywhere in the key, because a configuration
	 * key is as likely to be `smtp_password` as `password`.
	 *
	 * @var array<int, string>
	 */
	public const SECRET_FRAGMENTS = [
		'password',
		'passwd',
		'secret',
		'token',
		'apikey',
		'api_key',
		'credential',
		'private_key',
		'privatekey',
		'certificate',
		'salt',
		'signature',
		'authorization',
		'bearer',
	];

	/**
	 * How many failed runs the bundle carries.
	 *
	 * @var integer
	 */
	public const RECENT_FAILURES = 25;

	/**
	 * Constructor.
	 *
	 * @param IConfig                 $config The live configuration.
	 * @param IAppManager             $apps   The installed apps and their versions.
	 * @param JobRunMapper            $runs   The run log the failures come from.
	 * @param ConsistencyCheckService $check  The read-only consistency check.
	 */
	public function __construct(
		private readonly IConfig $config,
		private readonly IAppManager $apps,
		private readonly JobRunMapper $runs,
		private readonly ConsistencyCheckService $check,
	) {
	}//end __construct()

	/**
	 * The bundle.
	 *
	 * @return array<string, mixed> The version, the build, the redacted
	 *                              configuration, the check and the failures.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-a-support-bundle-and-the-instances-own-facts-are-readable-req-aoc-007
	 */
	public function build(): array {
		return [
			'producedAt' => (new DateTime())->format(DateTime::ATOM),
			'instance' => $this->facts(),
			'configuration' => $this->redactedConfiguration(),
			'consistency' => $this->consistency(),
			'recentFailures' => $this->recentFailures(),
		];

	}//end build()

	/**
	 * The instance facts page: version, build, dependencies, licence.
	 *
	 * @return array<string, mixed> The facts.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-a-support-bundle-and-the-instances-own-facts-are-readable-req-aoc-007
	 */
	public function facts(): array {
		return [
			'app' => self::APP_ID,
			'version' => $this->appVersion(appId: self::APP_ID),
			'build' => $this->config->getAppValue(self::APP_ID, 'build', ''),
			'licence' => 'EUPL-1.2',
			'php' => PHP_VERSION,
			'nextcloud' => $this->config->getSystemValueString('version', ''),
			'dependencies' => $this->dependencies(),
		];

	}//end facts()

	/**
	 * Redact one value by its key.
	 *
	 * Public because the rule is shared: the same answer has to cover the
	 * bundle and anything else that renders configuration (D-7).
	 *
	 * @param string $key   The configuration key.
	 * @param string $value The configured value.
	 *
	 * @return string The value, or the marker.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-a-support-bundle-and-the-instances-own-facts-are-readable-req-aoc-007
	 */
	public function redact(string $key, string $value): string {
		$lowered = strtolower($key);

		foreach (self::SECRET_FRAGMENTS as $fragment) {
			if (str_contains($lowered, $fragment) === true) {
				return self::REDACTED;
			}
		}

		return $value;

	}//end redact()

	/**
	 * The app's configuration, every secret replaced by the marker.
	 *
	 * @return array<string, string> The keys, and the values that may travel.
	 */
	private function redactedConfiguration(): array {
		$configuration = [];

		try {
			$keys = $this->config->getAppKeys(self::APP_ID);
		} catch (Throwable $exception) {
			return [];
		}

		foreach ($keys as $key) {
			$configuration[$key] = $this->redact(
				key: $key,
				value: $this->config->getAppValue(self::APP_ID, $key, '')
			);
		}

		return $configuration;

	}//end redactedConfiguration()

	/**
	 * The consistency check's findings, or why they are missing.
	 *
	 * @return array<string, mixed> The check.
	 */
	private function consistency(): array {
		try {
			return $this->check->check();
		} catch (Throwable $exception) {
			return ['unavailable' => $exception->getMessage()];
		}

	}//end consistency()

	/**
	 * The recent failed runs.
	 *
	 * @return array<int, array<string, mixed>> The failures, newest first.
	 */
	private function recentFailures(): array {
		try {
			$rows = $this->runs->findRecent(
				outcome: JobRun::OUTCOME_FAILED,
				limit: self::RECENT_FAILURES
			);
		} catch (Throwable $exception) {
			return [];
		}

		return array_map(static fn (JobRun $run): array => $run->jsonSerialize(), $rows);

	}//end recentFailures()

	/**
	 * The apps this one depends on, with their versions.
	 *
	 * @return array<string, string> The app ids and versions.
	 */
	private function dependencies(): array {
		$dependencies = [];

		foreach (['openregister', 'opencatalogi', 'integriq', 'nextcloud_vue'] as $appId) {
			$version = $this->appVersion(appId: $appId);

			if ($version === null) {
				continue;
			}

			$dependencies[$appId] = $version;
		}

		return $dependencies;

	}//end dependencies()

	/**
	 * One app's version, or null when it is not installed.
	 *
	 * @param string $appId The app id.
	 *
	 * @return string|null The version.
	 */
	private function appVersion(string $appId): ?string {
		try {
			return $this->apps->getAppVersion($appId);
		} catch (Throwable $exception) {
			return null;
		}

	}//end appVersion()
}//end class
