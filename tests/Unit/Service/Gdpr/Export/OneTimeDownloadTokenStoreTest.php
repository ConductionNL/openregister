<?php

declare(strict_types=1);

/**
 * OneTimeDownloadTokenStore Unit Tests
 *
 * Verifies the one-time download token is single-use (burned on first redeem),
 * case-scoped, time-boxed, and fail-closed (replay / wrong-case / expired all
 * refused).
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Gdpr\Export
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Gdpr\Export;

use OCA\OpenRegister\Service\Gdpr\Export\OneTimeDownloadTokenStore;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;

/**
 * Test class for OneTimeDownloadTokenStore.
 */
class OneTimeDownloadTokenStoreTest extends TestCase {

	/**
	 * In-memory app-config store backing the token records.
	 *
	 * @var array<string, string>
	 */
	private array $store = [];

	/**
	 * Current fake time.
	 *
	 * @var int
	 */
	private int $now = 1000000;

	/**
	 * Build the SUT wired to the in-memory store + fixed clock.
	 *
	 * @return OneTimeDownloadTokenStore
	 */
	private function buildStore(): OneTimeDownloadTokenStore {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('setValueString')
			->willReturnCallback(
				function (string $app, string $key, string $value): bool {
					$this->store[$key] = $value;
					return true;
				}
			);
		$appConfig->method('getValueString')
			->willReturnCallback(
				function (string $app, string $key, string $default = '') {
					return $this->store[$key] ?? $default;
				}
			);
		$appConfig->method('deleteKey')
			->willReturnCallback(
				function (string $app, string $key): void {
					unset($this->store[$key]);
				}
			);

		$random = $this->createMock(ISecureRandom::class);
		$seq = 0;
		$random->method('generate')->willReturnCallback(
			static function () use (&$seq): string {
				$seq++;
				return 'TOKEN' . str_pad((string)$seq, 59, '0');
			}
		);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn (): int => $this->now);

		return new OneTimeDownloadTokenStore($appConfig, $random, $time);
	}//end buildStore()

	/**
	 * A valid token permits exactly one download; a replay is refused.
	 *
	 * @return void
	 */
	public function testTokenPermitsExactlyOneDownload(): void {
		$sut = $this->buildStore();
		$token = $sut->mint(caseUuid: 'case-1');

		$this->assertTrue($sut->redeem(token: $token, caseUuid: 'case-1'), 'first redeem must succeed');
		$this->assertFalse($sut->redeem(token: $token, caseUuid: 'case-1'), 'replay must be refused');

	}//end testTokenPermitsExactlyOneDownload()

	/**
	 * A token minted for one case is refused for another case (case scope).
	 *
	 * @return void
	 */
	public function testTokenIsCaseScoped(): void {
		$sut = $this->buildStore();
		$token = $sut->mint(caseUuid: 'case-1');

		$this->assertFalse($sut->redeem(token: $token, caseUuid: 'other-case'));

	}//end testTokenIsCaseScoped()

	/**
	 * An expired token is refused (and burned).
	 *
	 * @return void
	 */
	public function testExpiredTokenRefused(): void {
		$sut = $this->buildStore();
		$token = $sut->mint(caseUuid: 'case-1', ttlSeconds: 60);

		// Advance the clock past the TTL.
		$this->now += 120;

		$this->assertFalse($sut->redeem(token: $token, caseUuid: 'case-1'));

	}//end testExpiredTokenRefused()

	/**
	 * An unknown/empty token is refused (fail closed).
	 *
	 * @return void
	 */
	public function testUnknownTokenRefused(): void {
		$sut = $this->buildStore();

		$this->assertFalse($sut->redeem(token: '', caseUuid: 'case-1'));
		$this->assertFalse($sut->redeem(token: 'never-minted', caseUuid: 'case-1'));

	}//end testUnknownTokenRefused()

	/**
	 * The raw token is never persisted verbatim (only its hash).
	 *
	 * @return void
	 */
	public function testRawTokenIsNotStored(): void {
		$sut = $this->buildStore();
		$token = $sut->mint(caseUuid: 'case-1');

		foreach ($this->store as $value) {
			$this->assertStringNotContainsString($token, $value, 'raw token must not be stored');
		}

	}//end testRawTokenIsNotStored()
}//end class
