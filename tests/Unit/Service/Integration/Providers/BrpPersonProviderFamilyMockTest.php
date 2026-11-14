<?php

/**
 * Unit tests for BrpPersonProvider::lookupFamily() on the mock BRP register (design D4).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/brp-family-members-lookup/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration\Providers;

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers take positional args by convention; mirroring BrpPersonProviderTest.

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\ProviderUnavailableException;
use OCA\OpenRegister\Service\Integration\ExternalIntegrationRouter;
use OCA\OpenRegister\Service\Integration\Providers\BrpMockRegister;
use OCA\OpenRegister\Service\Integration\Providers\BrpPersonProvider;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use OCP\IConfig;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The seeded family of BSN 999990627 answers through the same method and shape
 * as the real BRP, and the mock is read only when the source says `mock`.
 *
 * @covers \OCA\OpenRegister\Service\Integration\Providers\BrpPersonProvider
 * @covers \OCA\OpenRegister\Service\Integration\Providers\BrpMockRegister
 */
class BrpPersonProviderFamilyMockTest extends TestCase {

	/**
	 * The seeded resident with two children.
	 */
	private const RESIDENT = '999990627';

	/**
	 * The router double.
	 *
	 * @var ExternalIntegrationRouter&MockObject
	 */
	private ExternalIntegrationRouter&MockObject $router;

	/**
	 * The BSNs the mock register was asked for, in order.
	 *
	 * @var array<int,string>
	 */
	private array $asked = [];

	/**
	 * Build the router double before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->router = $this->createMock(ExternalIntegrationRouter::class);
	}//end setUp()

	/**
	 * The seeded family comes back minimised, with no BSN, and no upstream call is made.
	 *
	 * @return void
	 */
	public function testTheSeededFamilyIsAnsweredFromTheMockRegister(): void {
		$this->router->method('sourceConfiguration')->willReturn(['mock' => true]);
		$this->router->expects($this->never())->method('callWithMeta');

		$answer = $this->provider()->lookupFamily(bsn: self::RESIDENT);

		$this->assertSame(2, $answer['total']);
		$this->assertSame(['Nina Overvaart', 'Roos Janssen'], array_column($answer['results'], 'name'));
		$this->assertSame(['child', 'child'], array_column($answer['results'], 'relation'));
		$this->assertSame([2005, 2008], array_column($answer['results'], 'birthYear'));
		$this->assertSame([true, true], array_column($answer['results'], 'sameAddress'));
		$this->assertSame(200, $answer['meta']['status']);
		$this->assertStringNotContainsString('9999', json_encode($answer['results']));
		$this->assertSame([self::RESIDENT, '999997580', '999995145'], $this->asked);
	}//end testTheSeededFamilyIsAnsweredFromTheMockRegister()

	/**
	 * A reference from the mock answer resolves back to that child, and only for its resident.
	 *
	 * @return void
	 */
	public function testAMockReferenceResolvesBackToTheChild(): void {
		$this->router->method('sourceConfiguration')->willReturn(['mock' => true]);
		$provider = $this->provider();
		$reference = $provider->lookupFamily(bsn: self::RESIDENT)['results'][1]['reference'];

		$this->assertSame('999995145', $provider->resolveFamilyReference(bsn: self::RESIDENT, reference: $reference));
		$this->assertNull($provider->resolveFamilyReference(bsn: '999993653', reference: $reference));
	}//end testAMockReferenceResolvesBackToTheChild()

	/**
	 * A partner-only request on a resident without a partner answers nothing.
	 *
	 * @return void
	 */
	public function testOnlyTheAskedRelationsAreAnswered(): void {
		$this->router->method('sourceConfiguration')->willReturn(['mock' => true]);

		$answer = $this->provider()->lookupFamily(bsn: self::RESIDENT, relations: ['partner']);

		$this->assertSame(0, $answer['total']);
		$this->assertNull($answer['relativesMeta']);
		$this->assertSame([self::RESIDENT], $this->asked);
	}//end testOnlyTheAskedRelationsAreAnswered()

	/**
	 * A source that is not the mock goes upstream and never reads the register.
	 *
	 * @return void
	 */
	public function testARealSourceIsCalledUpstream(): void {
		$this->router->method('sourceConfiguration')->willReturn(['mock' => false]);
		$this->router->expects($this->once())->method('callWithMeta')->willReturn(['body' => ['personen' => []], 'meta' => []]);

		$this->assertSame(0, $this->provider()->lookupFamily(bsn: self::RESIDENT)['total']);
		$this->assertSame([], $this->asked);
	}//end testARealSourceIsCalledUpstream()

	/**
	 * A source that cannot be read is not taken for the mock; the call degrades as before.
	 *
	 * @return void
	 */
	public function testAnUnreadableSourceDegradesAsBefore(): void {
		$this->router->method('sourceConfiguration')->willThrowException(new ProviderUnavailableException('brp', ProviderUnavailableException::CAUSE_UPSTREAM_SERVICE_DOWN));
		$this->router->method('callWithMeta')->willThrowException(new ProviderUnavailableException('brp', ProviderUnavailableException::CAUSE_UPSTREAM_SERVICE_DOWN));

		$answer = $this->provider()->lookupFamily(bsn: self::RESIDENT);

		$this->assertTrue($answer['unavailable']);
		$this->assertSame([], $this->asked);
	}//end testAnUnreadableSourceDegradesAsBefore()

	/**
	 * The shape maps the flat mock fields and leaves out what the person lacks.
	 *
	 * @return void
	 */
	public function testTheHaalCentraalShapeOfAMockPerson(): void {
		$shaped = BrpMockRegister::haalCentraalShape(
			person: [
				'burgerservicenummer' => '1',
				'voornamen' => 'Jan',
				'voorvoegsel' => '',
				'geslachtsnaam' => 'Vries',
				'geboortedatum' => '1950-01-02',
				'overlijden' => ['datum' => '2020-01-01'],
				'partners' => ['x', ['burgerservicenummer' => '2']],
			]
		);

		$this->assertSame(['voornamen' => 'Jan', 'geslachtsnaam' => 'Vries'], $shaped['naam']);
		$this->assertSame(['datum' => ['datum' => '1950-01-02']], $shaped['geboorte']);
		$this->assertSame([['burgerservicenummer' => '2']], $shaped['partners']);
		$this->assertSame(['datum' => '2020-01-01'], $shaped['overlijden']);
		$this->assertArrayNotHasKey('geboorte', BrpMockRegister::haalCentraalShape(person: ['burgerservicenummer' => '3']));
	}//end testTheHaalCentraalShapeOfAMockPerson()

	/**
	 * The provider over the router double and a mock register backed by the shipped seed.
	 *
	 * @return BrpPersonProvider
	 */
	private function provider(): BrpPersonProvider {
		$seed = json_decode((string)file_get_contents(__DIR__ . '/../../../../../lib/Settings/brp_register.json'), true);
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config) use ($seed): array {
				$filters = $config['filters'];
				$this->assertSame(['brp', 'ingeschreven-persoon'], [$filters['register'], $filters['schema']]);
				$this->asked[] = $filters['burgerservicenummer'];
				$found = [];
				foreach ($seed['components']['objects'] as $object) {
					if (($object['burgerservicenummer'] ?? null) === $filters['burgerservicenummer']) {
						$entity = new ObjectEntity();
						$entity->setObject($object);
						$found[] = $entity;
					}
				}
				return $found;
			}
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(ObjectService::class)->willReturn($objects);

		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturnCallback(
			static fn (string $key, string $default=''): string => ($key === 'secret') ? 'instance-secret' : $default
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new BrpPersonProvider(
			router: $this->router,
			appManager: $this->createMock(IAppManager::class),
			l10n: $l10n,
			logger: $this->createMock(LoggerInterface::class),
			config: $config,
			mockRegister: new BrpMockRegister(container: $container),
		);
	}//end provider()
}//end class
