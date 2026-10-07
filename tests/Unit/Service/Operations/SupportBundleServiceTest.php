<?php

/**
 * Unit tests for SupportBundleService — the bundle that carries no secret.
 *
 * A bundle is built to be sent to somebody outside the organisation, so the
 * failure mode is not "it looks wrong", it is "a credential left the building
 * and nobody noticed". The assertions therefore go both ways: the value must
 * be gone, and the KEY must still be there, because a bundle that drops the
 * key entirely tells the reader nothing is configured.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Operations
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Operations;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\JobRun;
use OCA\OpenRegister\Db\JobRunMapper;
use OCA\OpenRegister\Service\Operations\ConsistencyCheckService;
use OCA\OpenRegister\Service\Operations\SupportBundleService;
use OCP\App\IAppManager;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

final class SupportBundleServiceTest extends TestCase {

	/**
	 * The configuration the bundle is built from.
	 *
	 * @var array<string, string>
	 */
	private array $stored = [];

	/**
	 * The service, over the stored configuration.
	 *
	 * @param array<int, JobRun> $failures What the run log answers with.
	 *
	 * @return SupportBundleService The service under test.
	 */
	private function service(array $failures = []): SupportBundleService {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppKeys')->willReturnCallback(
			fn (string $app): array => array_keys($this->stored)
		);
		$config->method('getAppValue')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->stored[$key] ?? $default)
		);
		$config->method('getSystemValueString')->willReturn('32.0.1.2');

		$apps = $this->createMock(IAppManager::class);
		$apps->method('getAppVersion')->willReturn('2.1.32');

		$runs = $this->createMock(JobRunMapper::class);
		$runs->method('findRecent')->willReturn($failures);

		$check = $this->createMock(ConsistencyCheckService::class);
		$check->method('check')->willReturn(['checked' => 3, 'inconsistent' => 0, 'findings' => []]);

		return new SupportBundleService($config, $apps, $runs, $check);
	}

	/**
	 * A configured credential does not travel, and the key that held it does.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-a-support-bundle-and-the-instances-own-facts-are-readable-req-aoc-007
	 *
	 * @return void
	 */
	public function testTheBundleCarriesTheKeysAndNoneOfTheCredentialValues(): void {
		$this->stored = [
			'smtp_password' => 'hunter2',
			'elastic_api_key' => 'ak-live-9911',
			'oidc_client_secret' => 'sh-abcdef',
			'search_backend' => 'typesense',
		];

		$bundle = $this->service()->build();
		$configuration = $bundle['configuration'];

		$this->assertArrayHasKey('smtp_password', $configuration);
		$this->assertSame(SupportBundleService::REDACTED, $configuration['smtp_password']);
		$this->assertSame(SupportBundleService::REDACTED, $configuration['elastic_api_key']);
		$this->assertSame(SupportBundleService::REDACTED, $configuration['oidc_client_secret']);

		$this->assertStringNotContainsString('hunter2', (string)json_encode($bundle));
		$this->assertStringNotContainsString('ak-live-9911', (string)json_encode($bundle));
		$this->assertStringNotContainsString('sh-abcdef', (string)json_encode($bundle));
	}

	/**
	 * A setting that is not a secret is carried as it stands, because a bundle
	 * that redacts everything answers nothing.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-a-support-bundle-and-the-instances-own-facts-are-readable-req-aoc-007
	 *
	 * @return void
	 */
	public function testAnOrdinarySettingTravelsUnchanged(): void {
		$this->stored = ['search_backend' => 'typesense'];

		$this->assertSame('typesense', $this->service()->build()['configuration']['search_backend']);
	}

	/**
	 * The redaction is a key rule, so a key nobody thought of is covered as
	 * long as it looks like a secret.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-a-support-bundle-and-the-instances-own-facts-are-readable-req-aoc-007
	 *
	 * @return void
	 */
	public function testTheRuleMatchesAnywhereInTheKeyAndIgnoresCase(): void {
		$service = $this->service();

		$this->assertSame(SupportBundleService::REDACTED, $service->redact('MAIL_SMTP_PASSWORD', 'x'));
		$this->assertSame(SupportBundleService::REDACTED, $service->redact('someTokenHere', 'x'));
		$this->assertSame(SupportBundleService::REDACTED, $service->redact('tenant_private_key_pem', 'x'));
		$this->assertSame('x', $service->redact('page_size', 'x'));
	}

	/**
	 * The bundle carries the recent failed runs, which is what a support call
	 * opens with.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-a-support-bundle-and-the-instances-own-facts-are-readable-req-aoc-007
	 *
	 * @return void
	 */
	public function testTheBundleCarriesTheRecentFailedRunsAndTheCheck(): void {
		$run = new JobRun();
		$run->setId(4);
		$run->setJobClass('Acme\\NightlyJob');
		$run->setOutcome(JobRun::OUTCOME_FAILED);
		$run->setMessage('the source refused the connection');

		$bundle = $this->service([$run])->build();

		$this->assertCount(1, $bundle['recentFailures']);
		$this->assertSame('the source refused the connection', $bundle['recentFailures'][0]['message']);
		$this->assertSame(3, $bundle['consistency']['checked']);
	}

	/**
	 * The facts page names the version, the build and the licence.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-a-support-bundle-and-the-instances-own-facts-are-readable-req-aoc-007
	 *
	 * @return void
	 */
	public function testTheFactsNameTheVersionTheBuildAndTheLicence(): void {
		$this->stored = ['build' => 'a6ab296'];

		$facts = $this->service()->facts();

		$this->assertSame('2.1.32', $facts['version']);
		$this->assertSame('a6ab296', $facts['build']);
		$this->assertSame('EUPL-1.2', $facts['licence']);
		$this->assertSame('32.0.1.2', $facts['nextcloud']);
		$this->assertArrayHasKey('openregister', $facts['dependencies']);
	}
}
