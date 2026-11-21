<?php

/**
 * Tests for the federated share lifetime and its use in FederationShareService.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Federation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/federation-scope-enforcement/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Federation;

use DateTime;
use OCA\OpenRegister\Db\FederatedShare;
use OCA\OpenRegister\Db\FederatedShareMapper;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\Federation\ShareCreatorCheck;
use OCA\OpenRegister\Service\Federation\ShareGrantor;
use OCA\OpenRegister\Service\FederationShareService;
use OCA\OpenRegister\Tests\Unit\Service\Federation\Support\FixedClock;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A share is served for 90 days from its creation or renewal, and only its creator renews it.
 */
class ShareLifetimeTest extends TestCase {

	private FederatedShareMapper&MockObject $shareMapper;

	private ShareGrantor&MockObject $grantor;

	private ShareCreatorCheck&MockObject $creatorCheck;

	private ?string $grantRefusal = null;

	protected function setUp(): void {
		parent::setUp();

		$this->shareMapper  = $this->createMock(FederatedShareMapper::class);
		$this->grantor      = $this->createMock(ShareGrantor::class);
		$this->creatorCheck = $this->createMock(ShareCreatorCheck::class);
		$this->grantor->method('userId')->willReturn('alice');
		$this->grantor->method('refusal')->willReturnCallback(fn (): ?string => $this->grantRefusal);
	}//end setUp()

	/**
	 * The service on the fixed clock.
	 *
	 * @return FederationShareService
	 */
	private function service(): FederationShareService {
		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturn(str_repeat('a', 48));

		return new FederationShareService(
			$this->shareMapper,
			$this->grantor,
			$random,
			$this->createMock(LoggerInterface::class),
			$this->creatorCheck,
			(new FixedClock($this))->lifetime()
		);
	}//end service()

	/**
	 * A live outgoing share of alice's.
	 *
	 * @param string|null $expiresAt When it expires; null for none.
	 *
	 * @return FederatedShare
	 */
	private static function share(?string $expiresAt): FederatedShare {
		$share = new FederatedShare();
		$share->setId(7);
		$share->setDirection('outgoing');
		$share->setStatus('accepted');
		$share->setOwner('alice');
		if ($expiresAt !== null) {
			$share->setExpiresAt(new DateTime($expiresAt));
		}

		return $share;
	}//end share()

	/**
	 * A new expiry is 90 days from now.
	 *
	 * @return void
	 */
	public function testANewExpiryIsNinetyDaysAway(): void {
		$this->assertSame('2027-02-09 12:00:00', (new FixedClock($this))->lifetime()->newExpiry()->format('Y-m-d H:i:s'));
	}//end testANewExpiryIsNinetyDaysAway()

	/**
	 * A share without an expiry, or past it, has expired; one before it has not.
	 *
	 * @return void
	 */
	public function testExpiry(): void {
		$lifetime = (new FixedClock($this))->lifetime();

		$this->assertTrue($lifetime->hasExpired(self::share(null)));
		$this->assertTrue($lifetime->hasExpired(self::share('2026-11-11 12:00:00')));
		$this->assertFalse($lifetime->hasExpired(self::share('2026-11-12 12:00:00')));
		$this->assertTrue($lifetime->expiresWithin(self::share('2026-11-20 12:00:00'), 14));
		$this->assertFalse($lifetime->expiresWithin(self::share('2026-12-20 12:00:00'), 14));
	}//end testExpiry()

	/**
	 * A new share is created with its lifetime.
	 *
	 * @return void
	 */
	public function testANewShareIsCreatedWithItsLifetime(): void {
		$stored = null;
		$this->shareMapper->method('createFromArray')->willReturnCallback(
			function (array $data) use (&$stored): FederatedShare {
				$stored = $data;
				return new FederatedShare();
			}
		);

		$this->service()->createOutgoingShare(['scope' => 'schema', 'register' => 'r', 'schema' => 's', 'permissions' => 'read']);

		$this->assertSame('2027-02-09', $stored['expiresAt']->format('Y-m-d'));
	}//end testANewShareIsCreatedWithItsLifetime()

	/**
	 * An expired share is not served, and its creator is not asked; a live one asks the creator check.
	 *
	 * @return void
	 */
	public function testAnExpiredShareIsNotServed(): void {
		$this->creatorCheck->expects($this->once())->method('refusal')->willReturn(null);

		$this->assertSame('the share has expired', $this->service()->servingRefusal(self::share('2026-11-01 00:00:00')));
		$this->assertSame('the share has expired', $this->service()->servingRefusal(self::share(null)));
		$this->assertNull($this->service()->servingRefusal(self::share('2026-12-01 00:00:00')));
	}//end testAnExpiredShareIsNotServed()

	/**
	 * The creator renews a share for 90 days from now.
	 *
	 * @return void
	 */
	public function testTheCreatorRenewsAShare(): void {
		$this->shareMapper->method('find')->willReturn(self::share('2026-11-01 00:00:00'));
		$this->shareMapper->expects($this->once())->method('updateFromArray')->with(
			7,
			$this->callback(static fn (array $data): bool => $data['expiresAt']->format('Y-m-d') === '2027-02-09')
		)->willReturn(new FederatedShare());

		$this->service()->renewShare(7);
	}//end testTheCreatorRenewsAShare()

	/**
	 * The cases in which a renewal is refused.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string|null}>
	 */
	public static function refusedRenewals(): array {
		return [
			'another user' => ['bob', 'accepted', null],
			'a revoked share' => ['alice', 'revoked', null],
			'a creator who lost the right' => ['alice', 'accepted', 'This share needs the right to manage its schema'],
		];
	}//end refusedRenewals()

	/**
	 * Nobody but the creator renews, a dead share is not renewed, and nor is one beyond the creator's rights.
	 *
	 * @param string      $owner        The share's creator.
	 * @param string      $status       The share's status.
	 * @param string|null $grantRefusal What the grantor answers.
	 *
	 * @return void
	 *
	 * @dataProvider refusedRenewals
	 */
	public function testARenewalIsRefused(string $owner, string $status, ?string $grantRefusal): void {
		$share = self::share('2026-12-01 00:00:00');
		$share->setOwner($owner);
		$share->setStatus($status);
		$this->grantRefusal = $grantRefusal;
		$this->shareMapper->method('find')->willReturn($share);
		$this->shareMapper->expects($this->never())->method('updateFromArray');

		$this->expectException(NotAuthorizedException::class);
		$this->service()->renewShare(7);
	}//end testARenewalIsRefused()
}//end class
