<?php

/**
 * Unit tests for BrpPersonProvider::lookupFamily() and resolveFamilyReference().
 *
 * The router is mocked with recorded Haal Centraal answers for a fictional
 * family: the resident, a partner and two children on the resident's address,
 * a child on another address, a dissolved partnership and a deceased child.
 * Asserts the two calls of design D1, the minimised answer of D2 (no BSN, no
 * birth date, no address), the reference of D3 and the degraded path.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration\Providers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/brp-family-members-lookup/specs/integration-person-lookup/spec.md#requirement-the-brp-provider-answers-a-residents-partner-and-children-minimised-req-brp-fam-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration\Providers;

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers take positional args by convention; mirroring BrpPersonProviderTest.

use OCA\OpenRegister\Exception\ProviderUnavailableException;
use OCA\OpenRegister\Service\Integration\ExternalIntegrationRouter;
use OCA\OpenRegister\Service\Integration\Providers\BrpPersonProvider;
use OCA\OpenRegister\Service\Integration\UpstreamRequest;
use OCP\App\IAppManager;
use OCP\IConfig;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The family lookup of the BRP provider (REQ-BRP-FAM-001).
 */
class BrpPersonProviderFamilyTest extends TestCase {

	private const RESIDENT = '999990627';

	private const HOME = '0363010000100100';

	/**
	 * Mocked router.
	 *
	 * @var ExternalIntegrationRouter&MockObject
	 */
	private ExternalIntegrationRouter&MockObject $router;

	/**
	 * Every request body the router received, in order.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $bodies = [];

	/**
	 * The provider over the mocked router and a fixed instance secret.
	 *
	 * @param string $secret The instance secret.
	 *
	 * @return BrpPersonProvider
	 */
	private function provider(string $secret='instance-secret'): BrpPersonProvider {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturnCallback(
			static fn (string $key, string $default=''): string => ($key === 'secret') ? $secret : $default
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new BrpPersonProvider(
			router: $this->router,
			appManager: $this->createMock(IAppManager::class),
			l10n: $l10n,
			logger: $this->createMock(LoggerInterface::class),
			config: $config,
		);
	}//end provider()

	/**
	 * The recorded answer for the resident call.
	 *
	 * @return array<string,mixed>
	 */
	private static function residentAnswer(): array {
		return [
			'personen' => [
				[
					'burgerservicenummer' => self::RESIDENT,
					'verblijfplaats' => ['adresseerbaarObjectIdentificatie' => self::HOME],
					'partners' => [
						['burgerservicenummer' => '999991000', 'naam' => ['voornamen' => 'Henk']],
						[
							'burgerservicenummer' => '999991001',
							'ontbindingHuwelijkPartnerschap' => ['datum' => ['type' => 'Datum', 'datum' => '2015-01-01']],
						],
					],
					'kinderen' => [
						['burgerservicenummer' => '999991002'],
						['burgerservicenummer' => '999991003'],
						['burgerservicenummer' => '999991004'],
						['burgerservicenummer' => '999991005'],
						'not-a-person',
						['naam' => ['voornamen' => 'No BSN']],
					],
				],
			],
		];
	}//end residentAnswer()

	/**
	 * The recorded answer for the relatives call.
	 *
	 * @return array<string,mixed>
	 */
	private static function relativesAnswer(): array {
		return [
			'personen' => [
				[
					'burgerservicenummer' => '999991000',
					'naam' => ['volledigeNaam' => 'Henk de Vries'],
					'geboorte' => ['datum' => ['type' => 'Datum', 'datum' => '1983-05-01']],
					'verblijfplaats' => ['adresseerbaarObjectIdentificatie' => self::HOME],
				],
				[
					'burgerservicenummer' => '999991002',
					'naam' => ['voornamen' => 'Roos', 'voorvoegsel' => 'de', 'geslachtsnaam' => 'Vries'],
					'geboorte' => ['datum' => ['type' => 'JaarDatum', 'jaar' => 2012]],
					'verblijfplaats' => ['adresseerbaarObjectIdentificatie' => self::HOME],
				],
				[
					'burgerservicenummer' => '999991003',
					'naam' => ['volledigeNaam' => 'Nina de Vries'],
					'geboorte' => ['datum' => '2010-09-12'],
					'verblijfplaats' => ['adresseerbaarObjectIdentificatie' => '0599010000000001'],
				],
				[
					'burgerservicenummer' => '999991004',
					'naam' => ['volledigeNaam' => 'Tim de Vries'],
					'geboorte' => ['datum' => ['type' => 'Datum', 'datum' => '2008-02-02']],
					'overlijden' => ['datum' => ['type' => 'Datum', 'datum' => '2020-01-01']],
					'verblijfplaats' => ['adresseerbaarObjectIdentificatie' => self::HOME],
				],
				[
					'burgerservicenummer' => '999991005',
					'naam' => ['volledigeNaam' => 'Bo de Vries'],
					'geboorte' => ['datum' => ['type' => 'DatumOnbekend']],
					'verblijfplaats' => ['verblijfadres' => ['adresseerbaarObjectIdentificatie' => self::HOME]],
				],
				[
					'burgerservicenummer' => '999999999',
					'naam' => ['volledigeNaam' => 'Not asked'],
				],
			],
		];
	}//end relativesAnswer()

	/**
	 * Answer the resident call, then the relatives call, recording the bodies.
	 *
	 * @param array<string,mixed> $resident  The resident answer.
	 * @param array<string,mixed> $relatives The relatives answer.
	 *
	 * @return void
	 */
	private function answer(array $resident, array $relatives): void {
		$this->router->method('callWithMeta')->willReturnCallback(
			function ($provider, UpstreamRequest $request) use ($resident, $relatives): array {
				unset($provider);
				$this->bodies[] = $request->options['body'];
				if (count($this->bodies) === 1) {
					return ['body' => $resident, 'meta' => ['correlationId' => 'corr-1', 'durationMs' => 120, 'status' => 200]];
				}

				return ['body' => $relatives, 'meta' => ['correlationId' => 'corr-2', 'durationMs' => 90, 'status' => 200]];
			}
		);
	}//end answer()

	protected function setUp(): void {
		parent::setUp();
		$this->router = $this->createMock(ExternalIntegrationRouter::class);
		$this->bodies = [];
	}//end setUp()

	/**
	 * The current partner and the living children, minimised, in two calls.
	 *
	 * @return void
	 */
	public function testFamilyIsAnsweredMinimisedInTwoCalls(): void {
		$this->answer(self::residentAnswer(), self::relativesAnswer());

		$answer = $this->provider()->lookupFamily(bsn: self::RESIDENT);

		$this->assertCount(2, $this->bodies);
		$this->assertSame([self::RESIDENT], $this->bodies[0]['burgerservicenummer']);
		$this->assertSame(['burgerservicenummer', 'verblijfplaats.adresseerbaarObjectIdentificatie', 'partners', 'kinderen'], $this->bodies[0]['fields']);
		$this->assertSame(['999991000', '999991002', '999991003', '999991004', '999991005'], $this->bodies[1]['burgerservicenummer']);
		$this->assertContains('overlijden', $this->bodies[1]['fields']);

		$rows = array_map(static fn (array $row): array => [$row['name'], $row['relation'], $row['birthYear'], $row['sameAddress']], $answer['results']);
		$this->assertSame(
			[
				['Henk de Vries', 'partner', 1983, true],
				['Roos de Vries', 'child', 2012, true],
				['Nina de Vries', 'child', 2010, false],
				['Bo de Vries', 'child', null, true],
			],
			$rows
		);
		$this->assertSame(4, $answer['total']);
		$this->assertSame(['correlationId' => 'corr-1', 'durationMs' => 120, 'status' => 200], $answer['meta']);
		$this->assertSame(['correlationId' => 'corr-2', 'durationMs' => 90, 'status' => 200], $answer['relativesMeta']);

		$expected = substr(hash_hmac('sha256', self::RESIDENT . ':999991000', 'instance-secret'), 0, 32);
		$this->assertSame($expected, $answer['results'][0]['reference']);
		foreach ($answer['results'] as $row) {
			$this->assertSame(['reference', 'name', 'relation', 'birthYear', 'sameAddress'], array_keys($row));
		}

		$this->assertStringNotContainsString('99999100', json_encode($answer['results']));
		$this->assertStringNotContainsString(self::HOME, json_encode($answer['results']));
		$this->assertStringNotContainsString('1983-05-01', json_encode($answer['results']));
	}//end testFamilyIsAnsweredMinimisedInTwoCalls()

	/**
	 * Same address only drops the child living elsewhere; relations narrow the ask.
	 *
	 * @return void
	 */
	public function testSameAddressOnlyAndRelationsNarrowTheAnswer(): void {
		$this->answer(self::residentAnswer(), self::relativesAnswer());

		$answer = $this->provider()->lookupFamily(bsn: self::RESIDENT, relations: ['child', 'parent'], sameAddressOnly: true);

		$this->assertSame(['999991002', '999991003', '999991004', '999991005'], $this->bodies[1]['burgerservicenummer']);
		$this->assertSame(['Roos de Vries', 'Bo de Vries'], array_column($answer['results'], 'name'));
	}//end testSameAddressOnlyAndRelationsNarrowTheAnswer()

	/**
	 * A resident without current relatives makes one call and answers none.
	 *
	 * @return void
	 */
	public function testNoCurrentRelativesMakesOneCall(): void {
		$resident = self::residentAnswer();
		$resident['personen'][0]['partners'] = [$resident['personen'][0]['partners'][1]];
		$resident['personen'][0]['kinderen'] = [];
		$this->answer($resident, []);

		$answer = $this->provider()->lookupFamily(bsn: self::RESIDENT);

		$this->assertCount(1, $this->bodies);
		$this->assertSame([], $answer['results']);
		$this->assertNull($answer['relativesMeta']);
	}//end testNoCurrentRelativesMakesOneCall()

	/**
	 * An empty BSN or no known relation asks nothing.
	 *
	 * @return void
	 */
	public function testEmptyAskMakesNoCall(): void {
		$this->router->expects($this->never())->method('callWithMeta');
		$provider = $this->provider();

		$this->assertSame(0, $provider->lookupFamily(bsn: '  ')['total']);
		$this->assertSame(0, $provider->lookupFamily(bsn: self::RESIDENT, relations: ['sibling'])['total']);
	}//end testEmptyAskMakesNoCall()

	/**
	 * A source that is down degrades, without throwing.
	 *
	 * @return void
	 */
	public function testDownSourceDegrades(): void {
		$this->router->method('callWithMeta')->willThrowException(new RuntimeException('connection refused'));

		$this->assertSame(
			['unavailable' => true, 'cause' => 'upstream-service-down', 'results' => [], 'total' => 0],
			$this->provider()->lookupFamily(bsn: self::RESIDENT)
		);
	}//end testDownSourceDegrades()

	/**
	 * An unconfigured source degrades with its own cause.
	 *
	 * @return void
	 */
	public function testUnconfiguredSourceDegradesWithItsCause(): void {
		$this->router->method('callWithMeta')->willThrowException(
			new ProviderUnavailableException('no source', ProviderUnavailableException::CAUSE_OPENCONNECTOR_SOURCE_MISSING)
		);

		$answer = $this->provider()->lookupFamily(bsn: self::RESIDENT);

		$this->assertTrue($answer['unavailable']);
		$this->assertSame('openconnector-source-missing', $answer['cause']);
	}//end testUnconfiguredSourceDegradesWithItsCause()

	/**
	 * Without an instance secret no reference can be made, so nothing is asked.
	 *
	 * @return void
	 */
	public function testMissingSecretDegrades(): void {
		$this->router->expects($this->never())->method('callWithMeta');

		$answer = $this->provider(secret: '')->lookupFamily(bsn: self::RESIDENT);

		$this->assertSame(BrpPersonProvider::CAUSE_NO_INSTANCE_SECRET, $answer['cause']);
	}//end testMissingSecretDegrades()

	/**
	 * A reference resolves to its relative for that resident only.
	 *
	 * @return void
	 */
	public function testReferenceResolvesForTheSameResidentOnly(): void {
		$this->router->method('callWithMeta')->willReturn(['body' => self::residentAnswer(), 'meta' => []]);
		$provider = $this->provider();
		$reference = substr(hash_hmac('sha256', self::RESIDENT . ':999991002', 'instance-secret'), 0, 32);

		$this->assertSame('999991002', $provider->resolveFamilyReference(bsn: self::RESIDENT, reference: strtoupper($reference)));

		$foreign = substr(hash_hmac('sha256', '999993653:999991002', 'instance-secret'), 0, 32);
		$this->assertNull($provider->resolveFamilyReference(bsn: self::RESIDENT, reference: $foreign));

		$formerPartner = substr(hash_hmac('sha256', self::RESIDENT . ':999991001', 'instance-secret'), 0, 32);
		$this->assertNull($provider->resolveFamilyReference(bsn: self::RESIDENT, reference: $formerPartner));

		$this->assertNull($provider->resolveFamilyReference(bsn: self::RESIDENT, reference: 'not-a-reference'));
		$this->assertNull($provider->resolveFamilyReference(bsn: '', reference: $reference));
	}//end testReferenceResolvesForTheSameResidentOnly()

	/**
	 * A reference made under another instance secret never resolves, nor does one when the source is down.
	 *
	 * @return void
	 */
	public function testReferenceFailsClosed(): void {
		$reference = substr(hash_hmac('sha256', self::RESIDENT . ':999991002', 'other-secret'), 0, 32);
		$this->router->method('callWithMeta')->willReturnOnConsecutiveCalls(
			['body' => self::residentAnswer(), 'meta' => []],
			$this->throwException(new RuntimeException('down'))
		);
		$provider = $this->provider();

		$this->assertNull($provider->resolveFamilyReference(bsn: self::RESIDENT, reference: $reference));
		$good = substr(hash_hmac('sha256', self::RESIDENT . ':999991002', 'instance-secret'), 0, 32);
		$this->assertNull($provider->resolveFamilyReference(bsn: self::RESIDENT, reference: $good));
		$this->assertNull($this->provider(secret: '')->resolveFamilyReference(bsn: self::RESIDENT, reference: $good));
	}//end testReferenceFailsClosed()
}//end class
