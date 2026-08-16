<?php

/**
 * Unit tests for the regulator-escalate seam: RegulatorEscalateRegistry +
 * NullRegulatorEscalateProvider + RegulatorEscalateResult.
 *
 * Covers:
 *  - addProvider() indexes by id and resolve() selects it
 *  - duplicate id is rejected first-wins (original retained)
 *  - resolve() of an unset/unknown selector returns the fail-closed default
 *    (never null) — and that default REFUSES (never a silent success)
 *  - the escalate result rejects an out-of-range status
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Gdpr\Regulator
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/dsar-integration-seams/specs/dsar-regulator-escalate-seam/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Gdpr\Regulator;

use InvalidArgumentException;
use OCA\OpenRegister\Service\Gdpr\Regulator\NullRegulatorEscalateProvider;
use OCA\OpenRegister\Service\Gdpr\Regulator\RegulatorEscalateProvider;
use OCA\OpenRegister\Service\Gdpr\Regulator\RegulatorEscalateRegistry;
use OCA\OpenRegister\Service\Gdpr\Regulator\RegulatorEscalateResult;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Minimal stub regulator-escalate provider for registry tests.
 */
class _StubRegulatorProvider implements RegulatorEscalateProvider {
	public function __construct(
		private string $id,
		private string $reference = 'REG-STUB-0001',
	) {
	}//end __construct()

	public function getProviderId(): string {
		return $this->id;
	}//end getProviderId()

	public function escalate(string $caseUuid, array $case): RegulatorEscalateResult {
		return RegulatorEscalateResult::escalated(providerId: $this->id, reference: $this->reference);
	}//end escalate()
}//end class

/**
 * Test class for the regulator-escalate seam.
 */
class RegulatorEscalateRegistryTest extends TestCase {
	/**
	 * Build a registry pre-seeded with the fail-closed default.
	 *
	 * @return RegulatorEscalateRegistry
	 */
	private function registry(): RegulatorEscalateRegistry {
		return new RegulatorEscalateRegistry(new NullLogger(), new NullRegulatorEscalateProvider());
	}//end registry()

	/**
	 * addProvider() + resolve() selects the pack-named provider.
	 *
	 * @return void
	 */
	public function testResolveSelectsRegisteredProvider(): void {
		$registry = $this->registry();
		$this->assertTrue($registry->addProvider(new _StubRegulatorProvider('leaf.regulator.nl-ap')));

		$resolved = $registry->resolve('leaf.regulator.nl-ap');
		$this->assertSame('leaf.regulator.nl-ap', $resolved->getProviderId());
		$result = $resolved->escalate('case-1', []);
		$this->assertTrue($result->isEscalated());
		$this->assertSame('REG-STUB-0001', $result->getReference());
	}//end testResolveSelectsRegisteredProvider()

	/**
	 * Duplicate id: first registration wins, second is rejected.
	 *
	 * @return void
	 */
	public function testDuplicateIdFirstWins(): void {
		$registry = $this->registry();
		$first = new _StubRegulatorProvider('leaf.regulator', 'REG-A');
		$second = new _StubRegulatorProvider('leaf.regulator', 'REG-B');

		$this->assertTrue($registry->addProvider($first));
		$this->assertFalse($registry->addProvider($second));
		$this->assertSame($first, $registry->get('leaf.regulator'));
	}//end testDuplicateIdFirstWins()

	/**
	 * Unset selector resolves to the fail-closed default (never null),
	 * which REFUSES (no reference, not escalated).
	 *
	 * @return void
	 */
	public function testUnsetSelectorResolvesFailClosedDefault(): void {
		$registry = $this->registry();
		$resolved = $registry->resolve(null);

		$this->assertSame(NullRegulatorEscalateProvider::PROVIDER_ID, $resolved->getProviderId());
		$result = $resolved->escalate('case-1', []);
		$this->assertFalse($result->isEscalated());
		$this->assertSame(RegulatorEscalateResult::STATUS_REFUSED, $result->getStatus());
		$this->assertSame('', $result->getReference());
	}//end testUnsetSelectorResolvesFailClosedDefault()

	/**
	 * Unknown provider id does not silently succeed: falls back to the default
	 * that refuses (fail-closed, never null).
	 *
	 * @return void
	 */
	public function testUnknownSelectorFallsBackToDefaultRefused(): void {
		$registry = $this->registry();
		$resolved = $registry->resolve('does.not.exist');

		$this->assertSame(NullRegulatorEscalateProvider::PROVIDER_ID, $resolved->getProviderId());
		$this->assertFalse($resolved->escalate('case-1', [])->isEscalated());
	}//end testUnknownSelectorFallsBackToDefaultRefused()

	/**
	 * The default provider id is exactly the id the default pack selects.
	 *
	 * @return void
	 */
	public function testDefaultResolvesByExplicitId(): void {
		$registry = $this->registry();
		$resolved = $registry->resolve('or.default.regulator-escalate.null');
		$this->assertSame(NullRegulatorEscalateProvider::PROVIDER_ID, $resolved->getProviderId());
	}//end testDefaultResolvesByExplicitId()

	/**
	 * The escalate result rejects a status outside the permitted set.
	 *
	 * @return void
	 */
	public function testResultRejectsInvalidStatus(): void {
		$this->expectException(InvalidArgumentException::class);
		new RegulatorEscalateResult(status: 'maybe', providerId: 'x');
	}//end testResultRejectsInvalidStatus()
}//end class
