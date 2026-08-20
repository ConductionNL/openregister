<?php

/**
 * Unit tests for the identity-verify seam: IdentityVerifyRegistry +
 * NullIdentityVerifyProvider + IdentityVerifyResult.
 *
 * Covers:
 *  - addProvider() indexes by id and resolve() selects it
 *  - duplicate id is rejected first-wins (original retained)
 *  - resolve() of an unset/unknown selector returns the fail-closed default
 *    (never null) — and that default is UNVERIFIED (needs-more)
 *  - the three-state result rejects an out-of-range status
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Gdpr\Identity
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/dsar-integration-seams/specs/dsar-identity-verify-seam/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Gdpr\Identity;

use InvalidArgumentException;
use OCA\OpenRegister\Service\Gdpr\Identity\IdentityVerifyProvider;
use OCA\OpenRegister\Service\Gdpr\Identity\IdentityVerifyRegistry;
use OCA\OpenRegister\Service\Gdpr\Identity\IdentityVerifyResult;
use OCA\OpenRegister\Service\Gdpr\Identity\NullIdentityVerifyProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Minimal stub identity-verify provider for registry tests.
 */
class _StubIdentityProvider implements IdentityVerifyProvider {
	public function __construct(
		private string $id,
		private string $status = IdentityVerifyResult::STATUS_VERIFIED,
	) {
	}//end __construct()

	public function getProviderId(): string {
		return $this->id;
	}//end getProviderId()

	public function verify(string $caseUuid, array $case): IdentityVerifyResult {
		return new IdentityVerifyResult(status: $this->status, providerId: $this->id);
	}//end verify()
}//end class

/**
 * Test class for the identity-verify seam.
 */
class IdentityVerifyRegistryTest extends TestCase {
	/**
	 * Build a registry pre-seeded with the fail-closed default.
	 *
	 * @return IdentityVerifyRegistry
	 */
	private function registry(): IdentityVerifyRegistry {
		return new IdentityVerifyRegistry(new NullLogger(), new NullIdentityVerifyProvider());
	}//end registry()

	/**
	 * addProvider() + resolve() selects the pack-named provider.
	 *
	 * @return void
	 */
	public function testResolveSelectsRegisteredProvider(): void {
		$registry = $this->registry();
		$this->assertTrue($registry->addProvider(new _StubIdentityProvider('leaf.identity.nl-brp')));

		$resolved = $registry->resolve('leaf.identity.nl-brp');
		$this->assertSame('leaf.identity.nl-brp', $resolved->getProviderId());
		$this->assertTrue($resolved->verify('case-1', [])->isVerified());
	}//end testResolveSelectsRegisteredProvider()

	/**
	 * Duplicate id: first registration wins, second is rejected.
	 *
	 * @return void
	 */
	public function testDuplicateIdFirstWins(): void {
		$registry = $this->registry();
		$first = new _StubIdentityProvider('leaf.identity', IdentityVerifyResult::STATUS_VERIFIED);
		$second = new _StubIdentityProvider('leaf.identity', IdentityVerifyResult::STATUS_FAILED);

		$this->assertTrue($registry->addProvider($first));
		$this->assertFalse($registry->addProvider($second));
		$this->assertSame($first, $registry->get('leaf.identity'));
	}//end testDuplicateIdFirstWins()

	/**
	 * Unset selector resolves to the fail-closed default (never null),
	 * which is UNVERIFIED (needs-more).
	 *
	 * @return void
	 */
	public function testUnsetSelectorResolvesFailClosedDefault(): void {
		$registry = $this->registry();
		$resolved = $registry->resolve(null);

		$this->assertSame(NullIdentityVerifyProvider::PROVIDER_ID, $resolved->getProviderId());
		$result = $resolved->verify('case-1', []);
		$this->assertFalse($result->isVerified());
		$this->assertSame(IdentityVerifyResult::STATUS_NEEDS_MORE, $result->getStatus());
	}//end testUnsetSelectorResolvesFailClosedDefault()

	/**
	 * Unknown provider id does not skip the check: falls back to the default,
	 * still unverified (fail-closed, never null).
	 *
	 * @return void
	 */
	public function testUnknownSelectorFallsBackToDefaultUnverified(): void {
		$registry = $this->registry();
		$resolved = $registry->resolve('does.not.exist');

		$this->assertSame(NullIdentityVerifyProvider::PROVIDER_ID, $resolved->getProviderId());
		$this->assertFalse($resolved->verify('case-1', [])->isVerified());
	}//end testUnknownSelectorFallsBackToDefaultUnverified()

	/**
	 * The default provider id is exactly the id the default pack selects.
	 *
	 * @return void
	 */
	public function testDefaultResolvesByExplicitId(): void {
		$registry = $this->registry();
		$resolved = $registry->resolve('or.default.identity-verify.null');
		$this->assertSame(NullIdentityVerifyProvider::PROVIDER_ID, $resolved->getProviderId());
	}//end testDefaultResolvesByExplicitId()

	/**
	 * The result is strictly three-state; anything else is rejected.
	 *
	 * @return void
	 */
	public function testResultRejectsInvalidStatus(): void {
		$this->expectException(InvalidArgumentException::class);
		new IdentityVerifyResult(status: 'auto-approved', providerId: 'x');
	}//end testResultRejectsInvalidStatus()
}//end class
