<?php

/**
 * Tests that the share checks read a federated share through the Core contract.
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
use OCA\OpenRegister\Kernel\Contracts\Core\FederatedShareInterface;
use OCA\OpenRegister\Tests\Unit\Service\Federation\Support\FixedClock;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The stored share is the contract, and every accessor the contract names answers on it.
 */
class FederatedShareContractTest extends TestCase {

	/**
	 * The entity is what the share checks are handed.
	 *
	 * @return void
	 */
	public function testTheStoredShareImplementsTheContract(): void {
		$this->assertInstanceOf(expected: FederatedShareInterface::class, actual: new FederatedShare());
	}//end testTheStoredShareImplementsTheContract()

	/**
	 * Each accessor tag of the contract returns the stored column.
	 *
	 * @return void
	 */
	public function testEveryContractAccessorReadsTheStoredColumn(): void {
		$doc = (string)(new ReflectionClass(FederatedShareInterface::class))->getDocComment();
		preg_match_all('/@method\s+\S+\s+(get[A-Za-z]+)\(\)/', $doc, $matches);
		$getters = $matches[1];

		$this->assertContains(needle: 'getOwner', haystack: $getters);
		$this->assertContains(needle: 'getExpiresAt', haystack: $getters);

		$share = new FederatedShare();
		$share->setId(7);
		$share->setDirection('outgoing');
		$share->setScope('object');
		$share->setRegister('r');
		$share->setSchema('s');
		$share->setObjectUri('https://here/r/s/1');
		$share->setPermissions('read');
		$share->setOwner('alice');
		$share->setStatus('accepted');
		$share->setExpiresAt(new DateTime('2026-01-02T00:00:00+00:00'));

		foreach ($getters as $getter) {
			$this->assertNotNull(actual: $share->$getter(), message: $getter.' answers on the stored share');
		}
	}//end testEveryContractAccessorReadsTheStoredColumn()

	/**
	 * A check accepts any implementation of the contract, not only the entity.
	 *
	 * @return void
	 */
	public function testALifetimeCheckReadsAnyContractImplementation(): void {
		$share = new class implements FederatedShareInterface {

			/**
			 * The expiry of this share.
			 *
			 * @return DateTime The expiry.
			 */
			public function getExpiresAt(): DateTime {
				return new DateTime('2026-01-01T00:00:00+00:00');
			}//end getExpiresAt()

			/**
			 * Serialise the share.
			 *
			 * @return array<string, mixed> Nothing.
			 */
			public function jsonSerialize(): array {
				return [];
			}//end jsonSerialize()
		};

		$lifetime = (new FixedClock(test: $this))->lifetime(now: '2026-01-02 00:00:00');

		$this->assertTrue(condition: $lifetime->hasExpired(share: $share));
	}//end testALifetimeCheckReadsAnyContractImplementation()
}//end class
