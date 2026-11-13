<?php

/**
 * The token grant source binds the caller's token grant and can step out of it.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\Rbac\TokenGrant;
use OCA\OpenRegister\Service\Rbac\TokenGrantSource;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Rbac\TokenGrantSource
 * @uses   \OCA\OpenRegister\Service\Rbac\TokenGrant
 * @uses   \OCA\OpenRegister\Service\Rbac\TokenGrantReach
 * @uses   \OCA\OpenRegister\Service\Rbac\TokenGrantTerms
 */
class TokenGrantSourceTest extends TestCase {

	public function testAFreshSourceIsUnboundAndHoldsNoGrant(): void {
		$source = new TokenGrantSource();

		$this->assertFalse($source->isBound());
		$this->assertNull($source->current());
	}

	public function testBindingNullStillMarksTheSourceBound(): void {
		$source = new TokenGrantSource();
		$source->bind(null);

		$this->assertTrue($source->isBound());
		$this->assertNull($source->current());
	}

	public function testAConsumersStoredGrantIsReadAndBound(): void {
		$source = new TokenGrantSource();
		$grant  = $source->bindFromConsumer(['grant' => ['verbs' => ['read', 'update']], 'other' => 'x'], 'tok-1');

		$this->assertInstanceOf(TokenGrant::class, $grant);
		$this->assertSame($grant, $source->current());
		$this->assertTrue($source->isBound());
		$this->assertSame(['read', 'update'], $grant->verbs);
		$this->assertSame('tok-1', $grant->tokenId);
	}

	public function testAConsumerWithoutAStoredGrantBindsNone(): void {
		$source = new TokenGrantSource();

		$this->assertNull($source->bindFromConsumer(null, 'tok-1'));
		$this->assertTrue($source->isBound());
		$this->assertNull($source->current());

		$this->assertNull($source->bindFromConsumer(['roles' => []], 'tok-2'));
		$this->assertNull($source->current());
	}

	public function testRunWithoutGrantClearsTheGrantForTheOperationOnly(): void {
		$source = new TokenGrantSource();
		$grant  = $source->bindFromConsumer(['grant' => ['verbs' => ['read']]], 'tok-1');

		$seen = $source->runWithoutGrant(
			static fn (): array => [$source->current(), $source->isBound()]
		);

		$this->assertSame([null, false], $seen);
		$this->assertSame($grant, $source->current());
		$this->assertTrue($source->isBound());
	}

	public function testRunWithoutGrantRestoresTheGrantWhenTheOperationThrows(): void {
		$source = new TokenGrantSource();
		$grant  = $source->bindFromConsumer(['grant' => ['verbs' => ['read']]], 'tok-1');

		try {
			$source->runWithoutGrant(static function (): void {
				throw new RuntimeException('boom');
			});
			$this->fail('The operation\'s exception must propagate.');
		} catch (RuntimeException $e) {
			$this->assertSame('boom', $e->getMessage());
		}

		$this->assertSame($grant, $source->current());
		$this->assertTrue($source->isBound());
	}
}
