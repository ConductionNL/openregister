<?php

/**
 * Golden tests for StatementService::accept: who may accept, which version
 * counts, and exactly what is stored and audited on each outcome.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-a-published-statement-is-accepted-before-use-per-version-req-ihc-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Hardening;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use InvalidArgumentException;
use OCA\OpenRegister\Service\Hardening\HardeningAuditWriter;
use OCA\OpenRegister\Service\Hardening\HardeningFact;
use OCA\OpenRegister\Service\Hardening\HardeningPolicy;
use OCA\OpenRegister\Service\Hardening\PlatformConfiguration;
use OCA\OpenRegister\Service\Hardening\StatementService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

final class StatementAcceptGoldenTest extends TestCase {

	/** @var array<int, array<int, mixed>> The audit calls, as kind, fact, before, after and refusal. */
	private array $audited = [];

	/** @var array<int, array<int, string>> The user values written, as uid, app, key and value. */
	private array $stored = [];

	private function service(?string $statement): StatementService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($statement): string {
				if ($key === StatementService::STATEMENT_KEY && $statement !== null) {
					return $statement;
				}

				return $default;
			}
		);

		$config = $this->createMock(IConfig::class);
		$config->method('setUserValue')->willReturnCallback(function (string $uid, string $app, string $key, string $value): void {
			$this->stored[] = [$uid, $app, $key, $value];
		});

		$audit = $this->createMock(HardeningAuditWriter::class);
		$audit->method('accepted')->willReturnCallback(function (HardeningFact $change): void {
			$this->audited[] = ['accepted', $change->fact, $change->before, $change->after];
		});
		$audit->method('refused')->willReturnCallback(function (HardeningFact $change, string $refusal): void {
			$this->audited[] = ['refused', $change->fact, $change->before, $change->after, $refusal];
		});

		return new StatementService(
			platform: new PlatformConfiguration(
				config: $config,
				appConfig: $appConfig,
				appManager: $this->createMock(IAppManager::class)
			),
			audit: $audit
		);
	}

	private function published(string $version): string {
		return (string)json_encode(
			['version' => $version, 'title' => '', 'body' => 'text', 'publishedAt' => '2026-01-01T00:00:00+00:00', 'publishedBy' => 'admin']
		);
	}

	private function refusal(StatementService $service, string $userId, string $version): string {
		try {
			$service->accept(userId: $userId, version: $version);
		} catch (InvalidArgumentException $refusal) {
			return $refusal->getMessage();
		}

		$this->fail('The acceptance was not refused.');
	}

	public function testNothingPublishedRefusesBeforeLookingAtTheUser(): void {
		$message = $this->refusal($this->service(null), '', '1');

		$this->assertSame('This instance publishes no statement, so there is nothing to accept.', $message);
		$this->assertSame([], $this->audited);
		$this->assertSame([], $this->stored);
	}

	public function testABlankAccountIsRefusedWithoutAnAudit(): void {
		$this->assertSame('An acceptance is recorded against an account.', $this->refusal($this->service($this->published('2')), '  ', '1'));
		$this->assertSame([], $this->audited);
		$this->assertSame([], $this->stored);
	}

	public function testAnotherVersionIsRefusedAndAuditedWithBothVersions(): void {
		$message = $this->refusal($this->service($this->published('3')), 'mw', ' 2 ');

		$this->assertSame('The statement has moved on to version 3. Read it again before accepting.', $message);
		$this->assertSame([['refused', 'statement.accepted', '2', '3', 'The version accepted is not the version in force.']], $this->audited);
		$this->assertSame([], $this->stored);
	}

	public function testTheVersionInForceIsStoredAndAuditedForTheUser(): void {
		$acceptance = $this->service($this->published('3'))->accept(userId: 'mw', version: ' 3 ');

		$this->assertSame(['version', 'acceptedAt'], array_keys($acceptance));
		$this->assertSame('3', $acceptance['version']);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $acceptance['acceptedAt']);
		$this->assertSame([['mw', HardeningPolicy::APP_ID, StatementService::ACCEPTANCE_KEY, (string)json_encode($acceptance)]], $this->stored);
		$this->assertSame(
			[['accepted', 'statement.accepted', '', ['user' => 'mw', 'version' => '3', 'acceptedAt' => $acceptance['acceptedAt']]]],
			$this->audited
		);
	}
}//end class
