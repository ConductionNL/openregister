<?php

/**
 * Tests for `occ openregister:federation:expiring`.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Command
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

namespace OCA\OpenRegister\Tests\Unit\Command;

use DateTime;
use OCA\OpenRegister\Command\FederationSharesExpiringCommand;
use OCA\OpenRegister\Db\FederatedShare;
use OCA\OpenRegister\Db\FederatedShareMapperInterface;
use OCA\OpenRegister\Tests\Unit\Service\Federation\Support\FixedClock;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The report lists the shares that expire within the days asked, and no others.
 */
class FederationSharesExpiringCommandTest extends TestCase {

	/**
	 * A share.
	 *
	 * @param int         $id        The id.
	 * @param string|null $expiresAt When it expires.
	 *
	 * @return FederatedShare
	 */
	private static function share(int $id, ?string $expiresAt): FederatedShare {
		$share = new FederatedShare();
		$share->setId($id);
		$share->setOwner('creator-'.$id);
		$share->setScope('schema');
		$share->setPermissions('read');
		if ($expiresAt !== null) {
			$share->setExpiresAt(new DateTime($expiresAt));
		}

		return $share;
	}//end share()

	/**
	 * Expiring and expired shares are listed with their creator; a later one is not.
	 *
	 * @return void
	 */
	public function testItListsTheSharesThatExpireSoon(): void {
		$mapper = $this->createMock(FederatedShareMapperInterface::class);
		$mapper->method('findLiveOutgoingAcrossOrganisations')->willReturn(
			[self::share(1, '2026-11-15 00:00:00'), self::share(2, '2027-01-30 00:00:00'), self::share(3, '2026-10-01 00:00:00')]
		);
		$tester = new CommandTester(new FederationSharesExpiringCommand($mapper, (new FixedClock($this))->lifetime()));

		$this->assertSame(0, $tester->execute(['--days' => '7']));

		$display = $tester->getDisplay();
		$this->assertStringContainsString('creator-1', $display);
		$this->assertStringContainsString('creator-3', $display);
		$this->assertStringNotContainsString('creator-2', $display);
		$this->assertStringContainsString('2 of 3 live outgoing shares expire within 7 days.', $display);
	}//end testItListsTheSharesThatExpireSoon()
}//end class
