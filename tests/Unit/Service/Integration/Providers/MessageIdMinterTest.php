<?php

/**
 * The Message-ID an outbound e-mail is minted.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration\Providers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/send-at-on-the-messaging-leaf/specs/integration-message-dispatch/spec.md#requirement-e-mail-is-a-channel-of-the-dispatch-leaf
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.
// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

namespace OCA\OpenRegister\Tests\Unit\Service\Integration\Providers;

use OCA\OpenRegister\Service\Integration\Providers\MessageIdMinter;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Integration\Providers\MessageIdMinter
 */
class MessageIdMinterTest extends TestCase {

	public function testAMessageIdIsAngleBracketedRandomAtTheHost(): void {
		$this->assertMatchesRegularExpression('/^<[0-9a-f]{32}@register\.zuiddrecht\.nl>$/', (new MessageIdMinter())->mint(host: 'Register.Zuiddrecht.nl'));
	}

	public function testThePortIsDropped(): void {
		$this->assertStringEndsWith('@localhost>', (new MessageIdMinter())->mint(host: 'localhost:8080'));
	}

	public function testTwoMessagesNeverShareOne(): void {
		$minter = new MessageIdMinter();
		$ids    = [];
		for ($i = 0; $i < 50; $i++) {
			$ids[] = $minter->mint(host: 'example.org');
		}

		$this->assertCount(50, array_unique($ids));
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function notAHost(): array {
		return [
			'empty' => [''],
			'an IPv6 literal' => ['[::1]:80'],
			'a header injection' => ["example.org>\r\nBcc: x@y"],
			'a space' => ['exa mple.org'],
			'a leading dash' => ['-example.org'],
		];
	}

	/**
	 * @dataProvider notAHost
	 */
	public function testAnythingButAHostNameFallsBackToTheReservedDomain(string $host): void {
		$this->assertMatchesRegularExpression('/^<[0-9a-f]{32}@openregister\.invalid>$/', (new MessageIdMinter())->mint(host: $host));
	}
}//end class
