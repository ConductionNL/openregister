<?php

/**
 * Tests for the GiveFederationSharesALifetime repair step.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Repair
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

namespace OCA\OpenRegister\Tests\Unit\Repair;

use OCA\OpenRegister\Repair\GiveFederationSharesALifetime;
use OCA\OpenRegister\Tests\Unit\Service\Federation\Support\FixedClock;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Existing shares get their lifetime from the upgrade, once.
 */
class GiveFederationSharesALifetimeTest extends TestCase {

	/**
	 * The step writes the new expiry only where there is none, and reports the count.
	 *
	 * @return void
	 */
	public function testOnlySharesWithoutAnExpiryGetOne(): void {
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->expects($this->once())->method('isNull')->with('expires_at')->willReturn('expires_at IS NULL');
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->expects($this->once())->method('update')->with('openregister_federated_shares')->willReturnSelf();
		$qb->expects($this->once())->method('createNamedParameter')->with(
			$this->callback(static fn (\DateTimeInterface $end): bool => $end->format('Y-m-d') === '2027-02-09'),
			IQueryBuilder::PARAM_DATE
		)->willReturn(':end');
		$qb->expects($this->once())->method('set')->with('expires_at', ':end')->willReturnSelf();
		$qb->expects($this->once())->method('where')->with('expires_at IS NULL')->willReturnSelf();
		$qb->method('executeStatement')->willReturn(3);
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$db->method('getQueryBuilder')->willReturn($qb);
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')->with('3 federated share(s) now expire in 90 days.');

		(new GiveFederationSharesALifetime($db, (new FixedClock($this))->lifetime(), $this->createMock(LoggerInterface::class)))->run($output);
	}//end testOnlySharesWithoutAnExpiryGetOne()

	/**
	 * A failed update is logged and does not abort the upgrade; a missing table is skipped.
	 *
	 * @return void
	 */
	public function testAFailureIsLoggedAndAMissingTableSkipped(): void {
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($this->createMock(IExpressionBuilder::class));
		$qb->method('update')->willThrowException(new RuntimeException('down'));
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturnOnConsecutiveCalls(true, false);
		$db->expects($this->once())->method('getQueryBuilder')->willReturn($qb);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error');
		$step = new GiveFederationSharesALifetime($db, (new FixedClock($this))->lifetime(), $logger);

		$step->run($this->createMock(IOutput::class));
		$step->run($this->createMock(IOutput::class));
	}//end testAFailureIsLoggedAndAMissingTableSkipped()
}//end class
