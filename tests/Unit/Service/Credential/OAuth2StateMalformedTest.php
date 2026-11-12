<?php

/**
 * OAuth2 state values that are signed but malformed.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Credential
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Credential;

use OCA\OpenRegister\Service\Credential\OAuth2StateService;
use OCP\Security\ICredentialsManager;
use OCP\Security\ICrypto;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * A state that is not two parts, not JSON, names no nonce, or points at a broken pending
 * record is refused, and a broken record is still burned.
 *
 * @covers \OCA\OpenRegister\Service\Credential\OAuth2StateService
 */
final class OAuth2StateMalformedTest extends TestCase {

	private ICredentialsManager&MockObject $vault;

	private OAuth2StateService $service;

	/**
	 * A service whose HMAC is a readable prefix.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('calculateHMAC')->willReturnCallback(static fn (string $message): string => 'hmac:' . $message);
		$this->vault = $this->createMock(ICredentialsManager::class);
		$this->service = new OAuth2StateService(crypto: $crypto, vault: $this->vault, random: $this->createMock(ISecureRandom::class));
	}//end setUp()

	/**
	 * Base64url without padding.
	 *
	 * @param string $value The bytes.
	 *
	 * @return string The encoding.
	 */
	private static function b64(string $value): string {
		return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
	}//end b64()

	/**
	 * A correctly signed state over the given payload text.
	 *
	 * @param string $json The payload text.
	 *
	 * @return string The state.
	 */
	private static function signed(string $json): string {
		$encoded = self::b64($json);
		return $encoded . '.' . self::b64('hmac:' . $encoded);
	}//end signed()

	/**
	 * Reading a state without verifying refuses anything that is not two parts of JSON.
	 *
	 * @return void
	 */
	public function testParseUnverifiedRefusesMalformedStates(): void {
		$this->assertNull($this->service->parseUnverified(state: 'a.b.c'));
		$this->assertNull($this->service->parseUnverified(state: 'no-dot'));
		$this->assertNull($this->service->parseUnverified(state: self::b64('not json') . '.sig'));
		$this->assertNull($this->service->parseUnverified(state: '!!!.sig'));
		$this->assertSame(['u' => 'alice'], $this->service->parseUnverified(state: self::b64('{"u":"alice"}') . '.unchecked'));
	}//end testParseUnverifiedRefusesMalformedStates()

	/**
	 * A signed, unexpired state without a nonce is refused without touching the vault.
	 *
	 * @return void
	 */
	public function testASignedStateWithoutNonceIsRefused(): void {
		$this->vault->expects($this->never())->method('retrieve');

		$this->assertNull($this->service->consume(state: self::signed((string)json_encode(['u' => 'alice', 'exp' => time() + 600]))));
	}//end testASignedStateWithoutNonceIsRefused()

	/**
	 * A pending record that is not JSON, or has no verifier, is refused and still deleted.
	 *
	 * @return void
	 */
	public function testABrokenPendingRecordIsRefusedAndBurned(): void {
		$state = self::signed((string)json_encode(['u' => 'alice', 'n' => 'nonce1', 'exp' => time() + 600]));
		$this->vault->method('retrieve')->willReturnOnConsecutiveCalls('not json', '{"exp": 1}', '');
		$this->vault->expects($this->exactly(2))->method('delete')->with($this->anything(), $this->stringEndsWith('nonce1'));

		$this->assertNull($this->service->consume(state: $state));
		$this->assertNull($this->service->consume(state: $state));
		$this->assertNull($this->service->consume(state: $state));
	}//end testABrokenPendingRecordIsRefusedAndBurned()
}//end class
