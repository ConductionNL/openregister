<?php

/**
 * The token identity an audited request carries.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Audit
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Audit;

use OCA\OpenRegister\Service\Audit\TokenContext;
use OCA\OpenRegister\Service\Audit\TokenIdentity;
use OCA\OpenRegister\Service\Audit\TokenResolver;
use PHPUnit\Framework\TestCase;

/**
 * A claimed identity wins; otherwise the request is resolved once and remembered until cleared.
 *
 * @covers \OCA\OpenRegister\Service\Audit\TokenContext
 * @uses \OCA\OpenRegister\Service\Audit\TokenIdentity
 */
final class TokenContextTest extends TestCase {

	/**
	 * Without a claim the resolver is asked once, also when it finds nothing; clear asks again.
	 *
	 * @return void
	 */
	public function testTheRequestIsResolvedOnceUntilCleared(): void {
		$found = new TokenIdentity(mechanism: 'bearer');
		$resolver = $this->createMock(TokenResolver::class);
		$resolver->expects($this->exactly(2))->method('resolve')->willReturnOnConsecutiveCalls(null, $found);
		$context = new TokenContext(resolver: $resolver);

		$this->assertNull($context->identity());
		$this->assertNull($context->identity());
		$context->clear();
		$this->assertSame($found, $context->identity());
	}//end testTheRequestIsResolvedOnceUntilCleared()

	/**
	 * A claimed identity is returned without resolving, until it is cleared.
	 *
	 * @return void
	 */
	public function testAClaimedIdentityWins(): void {
		$claimed = new TokenIdentity(mechanism: 'app-token');
		$resolver = $this->createMock(TokenResolver::class);
		$resolver->expects($this->once())->method('resolve')->willReturn(null);
		$context = new TokenContext(resolver: $resolver);

		$context->claim(identity: $claimed);
		$this->assertSame($claimed, $context->identity());
		$context->clear();
		$this->assertNull($context->identity());
	}//end testAClaimedIdentityWins()
}//end class
