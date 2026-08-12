<?php

/**
 * Federation confidentiality guard — property-name coverage.
 *
 * `applyShareVisibility()` refuses to serve a non-public object through a
 * non-object-scope federated share. It read exactly one property name,
 * `confidentiality`, while the same concept is written under two others:
 *
 *   - `confidentialityLevel`        — the target the ZGW migration mapping pack
 *                                     maps `/vertrouwelijkheidaanduiding` onto
 *                                     (SeedZgwZakenMigrationPack);
 *   - `vertrouwelijkheidaanduiding` — the ZGW/GGM schema property itself.
 *
 * That was not a cosmetic miss. `?? ''` yields the empty string for an object
 * storing its level under either other name, and the empty string is IN the
 * public allowlist — an object is public precisely when no level is set. So the
 * guard failed OPEN: a `zeer_geheim` object was served as public.
 *
 * These tests pin every spelling. They are written to FAIL against the
 * single-key read, which was confirmed before the fix landed.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\FederationController;
use OCA\OpenRegister\Db\FederatedShare;
use OCA\OpenRegister\Db\FederatedShareMapper;
use OCA\OpenRegister\Service\FederationShareService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Confidentiality is honoured under every property name it is stored as.
 */
class FederationControllerConfidentialityTest extends TestCase {
	// PHPUnit's own assertions and mock builders take positional arguments
	// ($expected, $actual, $message); the custom named-parameter sniff is aimed
	// at this app's code, not at the framework. Calls to methods defined in
	// THIS file still use named parameters.
	// phpcs:disable CustomSniffs.Functions.NamedParameters

	/**
	 * The controller under test.
	 *
	 * @var FederationController
	 */
	private FederationController $controller;

	/**
	 * Build the controller over mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->controller = new FederationController(
			'openregister',
			$this->createMock(IRequest::class),
			$this->createMock(FederatedShareMapper::class),
			$this->createMock(ObjectService::class),
			$this->createMock(FederationShareService::class),
			$this->createMock(LoggerInterface::class)
		);

	}//end setUp()

	/**
	 * A schema-scope share — the scope the confidentiality guard applies to.
	 *
	 * @return FederatedShare The share.
	 */
	private function schemaScopeShare(): FederatedShare {
		$share = new FederatedShare();
		$share->setDirection('outgoing');
		$share->setStatus('accepted');
		$share->setScope('schema');
		$share->setRegister('zaken');
		$share->setSchema('zaak');

		return $share;
	}//end schemaScopeShare()

	/**
	 * Run one object through the private visibility filter.
	 *
	 * @param array<string, mixed> $object The rendered object.
	 *
	 * @return bool Whether the object would be served.
	 */
	private function isServed(array $object): bool {
		$m = new ReflectionMethod(FederationController::class, 'applyShareVisibility');
		$m->setAccessible(true);

		$visible = $m->invoke($this->controller, [$object], $this->schemaScopeShare());

		return count($visible) === 1;
	}//end isServed()

	/**
	 * Every property name a confidentiality level is stored under is honoured.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: bool}>
	 */
	public static function confidentialityProvider(): array {
		return [
			// The three that were failing open before the fix.
			'ZGW pack target, secret' => [['confidentialityLevel' => 'zeer_geheim'], false],
			'ZGW schema property, restricted' => [['vertrouwelijkheidaanduiding' => 'vertrouwelijk'], false],
			'empty canonical, secret alias' => [
				[
					'confidentiality' => '',
					'confidentialityLevel' => 'zeer_geheim',
				],
				false,
			],

			// Already correct — pinned so the fix cannot regress them.
			'canonical name, secret' => [['confidentiality' => 'zeer_geheim'], false],
			'canonical name, openbaar' => [['confidentiality' => 'openbaar'], true],
			'no level set at all' => [[], true],
			'uppercase is normalised' => [['confidentialityLevel' => 'ZEER_GEHEIM'], false],
		];

	}//end confidentialityProvider()

	/**
	 * A non-public object is withheld whichever name carries its level.
	 *
	 * @param array<string, mixed> $object The rendered object.
	 * @param bool $expectSeen Whether it should be served.
	 *
	 * @return void
	 *
	 * @dataProvider confidentialityProvider
	 *
	 * @spec openspec/specs/federation/spec.md
	 */
	public function testConfidentialityIsHonouredUnderEveryName(array $object, bool $expectSeen): void {
		$why = 'SERVED but must be withheld';
		if ($expectSeen === true) {
			$why = 'withheld but should be served';
		}

		$this->assertSame(
			$expectSeen,
			$this->isServed(object: $object),
			'Object ' . json_encode($object) . ' was ' . $why
		);

	}//end testConfidentialityIsHonouredUnderEveryName()

	/**
	 * An object-scope share still bypasses the guard.
	 *
	 * The sharer picked that one object by hand, so its level is not re-checked.
	 * Pinned because the fix must not tighten this path by accident.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/federation/spec.md
	 */
	public function testObjectScopeShareStillBypassesTheGuard(): void {
		$share = $this->schemaScopeShare();
		$share->setScope('object');

		$m = new ReflectionMethod(FederationController::class, 'applyShareVisibility');
		$m->setAccessible(true);

		$visible = $m->invoke($this->controller, [['confidentialityLevel' => 'zeer_geheim']], $share);

		$this->assertCount(1, $visible, 'An object-scope share serves the chosen object regardless of its level.');

	}//end testObjectScopeShareStillBypassesTheGuard()
}//end class
