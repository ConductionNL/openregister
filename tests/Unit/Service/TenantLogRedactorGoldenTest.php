<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for the value walk and the pseudonym salt of the tenant log
 * redactor, pinned on the code before both were split into small units:
 * which values pass, which are redacted, which drop the line, and which salt
 * a pseudonym is derived from in every app-config state.
 *
 * @spec openspec/changes/several-legal-entities-in-one-instance/specs/tenant-isolation-audit/spec.md#requirement-a-log-line-names-the-tenant-pseudonymously-and-never-carries-a-secret-req-sle-003
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- PHPUnit arrange/act/assert conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\TenantLogRedactor;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Stringable;

class TenantLogRedactorGoldenTest extends TestCase {
	private const FALLBACK_SALT = 'openregister-tenant-log';

	private function expected(string $userId, string $salt): string {
		return 'actor:' . substr(hash_hmac('sha256', $userId, $salt), 0, 16);
	}

	public function testTheWalkPassesRedactsAndDescendsExactlyAsBefore(): void {
		$stringable = new class implements Stringable {
			public function __toString(): string {
				return 'see https://u:pw@host/x';
			}
		};

		$clean = (new TenantLogRedactor())->line([
			'n'      => null,
			'b'      => false,
			'i'      => 7,
			'f'      => 1.5,
			's'      => 'plain',
			'e'      => '',
			'obj'    => $stringable,
			'nested' => ['deeper' => ['msg' => 'Bearer abcdefghijkl', 'apiKey' => ['anything']]],
			0        => 'numeric key',
		]);

		$this->assertSame(
			[
				'n'      => null,
				'b'      => false,
				'i'      => 7,
				'f'      => 1.5,
				's'      => 'plain',
				'e'      => '',
				'obj'    => 'see https[redacted]host/x',
				'nested' => ['deeper' => ['msg' => '[redacted]', 'apiKey' => '[redacted]']],
				0        => 'numeric key',
			],
			$clean
		);
	}

	public static function droppedValues(): array {
		$deep = 'leaf';
		for ($i = 0; $i < 6; $i++) {
			$deep = [$deep];
		}

		$atLimit = 'leaf';
		for ($i = 0; $i < 5; $i++) {
			$atLimit = [$atLimit];
		}

		return [
			'closure'          => [['c' => static fn (): int => 1], false],
			'plain object'     => [['o' => new stdClass()], false],
			'binary string'    => [['b' => "\xff\xfe"], false],
			'past the limit'   => [['d' => $deep], false],
			'at the limit'     => [['d' => $atLimit], true],
		];
	}

	#[DataProvider('droppedValues')]
	public function testWhatDropsTheLine(array $context, bool $kept): void {
		$line = (new TenantLogRedactor())->line($context);

		$this->assertSame($kept, $line !== null);
	}

	public function testWithoutAnAppConfigTheFallbackSaltIsUsed(): void {
		$this->assertSame($this->expected('jan', self::FALLBACK_SALT), (new TenantLogRedactor())->pseudonym('jan'));
	}

	public function testAStoredSaltIsUsedAndNothingIsWritten(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->with('openregister', TenantLogRedactor::PSEUDONYM_SALT, '')->willReturn('stored-salt');
		$config->expects($this->never())->method('setValueString');

		$this->assertSame($this->expected('jan', 'stored-salt'), (new TenantLogRedactor($config))->pseudonym('jan'));
	}

	public function testAMissingSaltIsGeneratedStoredAndUsed(): void {
		$written = null;
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('');
		$config->expects($this->once())->method('setValueString')->willReturnCallback(
			static function (string $app, string $key, string $value) use (&$written): bool {
				$written = [$app, $key, $value];
				return true;
			}
		);

		$pseudonym = (new TenantLogRedactor($config))->pseudonym('jan');

		$this->assertSame('openregister', $written[0]);
		$this->assertSame(TenantLogRedactor::PSEUDONYM_SALT, $written[1]);
		$this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $written[2]);
		$this->assertSame($this->expected('jan', $written[2]), $pseudonym);
	}

	public function testAnUnreadableConfigFallsBackToTheInstanceLessSalt(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willThrowException(new RuntimeException('db down'));

		$this->assertSame($this->expected('jan', self::FALLBACK_SALT), (new TenantLogRedactor($config))->pseudonym('jan'));
	}

	public function testAnUnwritableConfigFallsBackToTheInstanceLessSalt(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('');
		$config->method('setValueString')->willThrowException(new RuntimeException('read only'));

		$this->assertSame($this->expected('jan', self::FALLBACK_SALT), (new TenantLogRedactor($config))->pseudonym('jan'));
	}
}
