<?php

/**
 * Six ways of not showing an external record, and only one of them means the
 * register has nothing.
 *
 * 🔴 THE FAILURE THIS FILE EXISTS FOR IS A BLANK PANEL. A municipality is
 * obliged to consult the basisregistraties, so "the BAG says nothing about
 * this address" is a claim with consequences. An unreachable source, an
 * unconfigured one and an absent app all render as no record, and folding them
 * together tells a handler something false about the world rather than
 * something true about us.
 *
 * 🔴 A REFUSAL IS NOT AN OUTAGE. A source that answered and said no is working
 * exactly as configured; reporting it as unavailable sends somebody to phone
 * an administrator about nothing.
 *
 * 🔴 THE ORDER OF THE CAUSES IS ASSERTED, because a case with no address has
 * no BAG record whether or not the BAG app is installed, and reporting the
 * installation first would send an administrator to fix something that is not
 * broken.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/external-register-view-leaf/specs/integration-external-register/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\Integration\ExternalRegisterDegrade;
use PHPUnit\Framework\TestCase;

/**
 * The degrade contract.
 *
 * @spec openspec/changes/external-register-view-leaf/specs/integration-external-register/spec.md
 */
class ExternalRegisterDegradeTest extends TestCase {

	private ExternalRegisterDegrade $degrade;

	protected function setUp(): void {
		parent::setUp();
		$this->degrade = new ExternalRegisterDegrade();
	}//end setUp()

	/**
	 * A lookup that works, with the named parts overridden.
	 *
	 * @param array<string,mixed> $overrides What to change.
	 *
	 * @return array<string,mixed> The lookup.
	 */
	private function lookup(array $overrides = []): array {
		return array_merge(
			[
				'key' => '0363010000000001',
				'appInstalled' => true,
				'configured' => true,
				'answered' => true,
				'refused' => false,
				'record' => ['straat' => 'Keizersgracht', 'huisnummer' => '117'],
			],
			$overrides
		);
	}//end lookup()

	public function testARecordThatWasReadComesBack(): void {
		$result = $this->degrade->evaluate($this->lookup());

		$this->assertSame(ExternalRegisterDegrade::OK, $result['state']);
		$this->assertSame('Keizersgracht', $result['record']['straat']);
		$this->assertFalse($result['adminActionable']);
	}//end testARecordThatWasReadComesBack()

	public function testEachWayOfFailingHasItsOwnState(): void {
		$cases = [
			ExternalRegisterDegrade::NO_KEY => ['key' => ''],
			ExternalRegisterDegrade::SOURCE_ABSENT => ['appInstalled' => false],
			ExternalRegisterDegrade::NOT_CONFIGURED => ['configured' => false],
			ExternalRegisterDegrade::UNREACHABLE => ['answered' => false],
			ExternalRegisterDegrade::REFUSED => ['refused' => true],
			ExternalRegisterDegrade::NOT_FOUND => ['record' => []],
		];

		foreach ($cases as $expected => $overrides) {
			$result = $this->degrade->evaluate($this->lookup($overrides));

			$this->assertSame($expected, $result['state']);
			// None of them carries a record: that is exactly why they would
			// otherwise collapse into one blank panel.
			$this->assertNull($result['record']);
		}
	}//end testEachWayOfFailingHasItsOwnState()

	public function testOnlyOneStateMeansTheRegisterHasNothing(): void {
		$meaning = [];
		foreach (ExternalRegisterDegrade::STATES as $state) {
			if ($this->degrade->meansTheRegisterHasNothing($state) === true) {
				$meaning[] = $state;
			}
		}

		$this->assertSame([ExternalRegisterDegrade::NOT_FOUND], $meaning);
	}//end testOnlyOneStateMeansTheRegisterHasNothing()

	public function testAMissingKeyIsReportedBeforeAMissingApp(): void {
		// A case with no address has no BAG record whether or not the BAG app
		// is installed; reporting the installation would send an
		// administrator to fix something that is not broken.
		$result = $this->degrade->evaluate($this->lookup(['key' => '', 'appInstalled' => false]));

		$this->assertSame(ExternalRegisterDegrade::NO_KEY, $result['state']);
		$this->assertFalse($result['adminActionable']);
	}//end testAMissingKeyIsReportedBeforeAMissingApp()

	public function testARefusalIsNotAnOutage(): void {
		$refused = $this->degrade->evaluate($this->lookup(['refused' => true]));

		$this->assertSame(ExternalRegisterDegrade::REFUSED, $refused['state']);
		// Working as configured, so nobody is sent to phone an administrator.
		$this->assertFalse($refused['adminActionable']);
	}//end testARefusalIsNotAnOutage()

	public function testTheStatesAnAdministratorCanActOnAreNamed(): void {
		foreach ([ExternalRegisterDegrade::SOURCE_ABSENT, ExternalRegisterDegrade::NOT_CONFIGURED] as $state) {
			$this->assertContains($state, ExternalRegisterDegrade::ADMIN_ACTIONABLE);
		}

		$this->assertTrue($this->degrade->evaluate($this->lookup(['answered' => false]))['adminActionable']);
		$this->assertFalse($this->degrade->evaluate($this->lookup(['record' => []]))['adminActionable']);
	}//end testTheStatesAnAdministratorCanActOnAreNamed()

	public function testAFailureIsCachedBrieflyAndAnAnswerForLonger(): void {
		$ok = $this->degrade->cacheSecondsFor(ExternalRegisterDegrade::OK);
		$unreachable = $this->degrade->cacheSecondsFor(ExternalRegisterDegrade::UNREACHABLE);

		// Caching a failure as long as a success keeps a widget broken for an
		// hour after the thing it depends on is fixed.
		$this->assertGreaterThan($unreachable, $ok);
		$this->assertGreaterThan(0, $unreachable);
	}//end testAFailureIsCachedBrieflyAndAnAnswerForLonger()

	public function testTheStatesThatChangeWithAnActAreNotCachedAtAll(): void {
		// A refusal changes the moment the caller's rights do; the two
		// configuration states change the moment an administrator acts.
		foreach (
			[
				ExternalRegisterDegrade::REFUSED,
				ExternalRegisterDegrade::NOT_CONFIGURED,
				ExternalRegisterDegrade::SOURCE_ABSENT,
				ExternalRegisterDegrade::NO_KEY,
			] as $state
		) {
			$this->assertSame(0, $this->degrade->cacheSecondsFor($state), $state . ' must not be held');
		}
	}//end testTheStatesThatChangeWithAnActAreNotCachedAtAll()

	public function testAnEmptyRecordIsNotFoundRatherThanOk(): void {
		$this->assertSame(
			ExternalRegisterDegrade::NOT_FOUND,
			$this->degrade->evaluate($this->lookup(['record' => null]))['state']
		);
	}//end testAnEmptyRecordIsNotFoundRatherThanOk()
}//end class
