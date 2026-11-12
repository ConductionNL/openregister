<?php

declare(strict_types=1);

/**
 * DSAR seam registries and results golden test.
 *
 * Pins the fail-closed behaviour of the three provider registries
 * (identity-verify, regulator-escalate, evidence-source) and the two seam
 * result value objects: first registration wins, a duplicate is refused and
 * warned with its exact message, an unset / empty / unknown selector resolves
 * the refusing default (never null), and a result refuses any status outside
 * its closed set with its exact message.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Gdpr
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Gdpr;

use InvalidArgumentException;
use OCA\OpenRegister\Service\Gdpr\Evidence\EvidenceSourceProvider;
use OCA\OpenRegister\Service\Gdpr\Evidence\EvidenceSourceRegistry;
use OCA\OpenRegister\Service\Gdpr\Identity\IdentityVerifyProvider;
use OCA\OpenRegister\Service\Gdpr\Identity\IdentityVerifyRegistry;
use OCA\OpenRegister\Service\Gdpr\Identity\IdentityVerifyResult;
use OCA\OpenRegister\Service\Gdpr\Identity\NullIdentityVerifyProvider;
use OCA\OpenRegister\Service\Gdpr\Regulator\NullRegulatorEscalateProvider;
use OCA\OpenRegister\Service\Gdpr\Regulator\RegulatorEscalateProvider;
use OCA\OpenRegister\Service\Gdpr\Regulator\RegulatorEscalateRegistry;
use OCA\OpenRegister\Service\Gdpr\Regulator\RegulatorEscalateResult;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden test for the DSAR seam registries and results.
 */
class GdprSeamRegistriesGoldenTest extends TestCase {

	/**
	 * Warnings logged, in order.
	 *
	 * @var list<string>
	 */
	private array $warnings = [];

	/**
	 * A logger recording every warning message.
	 *
	 * @return LoggerInterface
	 */
	private function logger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function ($message) {
				$this->warnings[] = (string)$message;
			}
		);
		return $logger;

	}//end logger()

	/**
	 * Identity registry: default pre-registered, duplicates refused, resolve fails closed.
	 *
	 * @return void
	 */
	public function testIdentityRegistry(): void {
		$default = new NullIdentityVerifyProvider();
		$registry = new IdentityVerifyRegistry(logger: $this->logger(), default: $default);
		$first = $this->createMock(IdentityVerifyProvider::class);
		$first->method('getProviderId')->willReturn('digid');
		$second = $this->createMock(IdentityVerifyProvider::class);
		$second->method('getProviderId')->willReturn('digid');

		$this->assertSame([$default->getProviderId()], $registry->listIds());
		$this->assertTrue($registry->addProvider(provider: $first));
		$this->assertFalse($registry->addProvider(provider: $second));
		$this->assertFalse($registry->addProvider(provider: $default));
		$this->assertSame($first, $registry->get(id: 'digid'));
		$this->assertNull($registry->get(id: 'nope'));
		$this->assertSame([$default, $first], $registry->list());
		$this->assertSame([$default->getProviderId(), 'digid'], $registry->listIds());

		$this->assertSame($first, $registry->resolve(selectorId: 'digid'));
		$this->assertSame($default, $registry->resolve(selectorId: $default->getProviderId()));
		$this->assertSame($default, $registry->resolve(selectorId: null));
		$this->assertSame($default, $registry->resolve(selectorId: ''));
		$this->assertSame($default, $registry->resolve(selectorId: 'unknown'));

		$id = $default->getProviderId();
		$this->assertSame(
			[
				'[IdentityVerifyRegistry] duplicate provider id "digid" — keeping first registration',
				'[IdentityVerifyRegistry] duplicate provider id "' . $id . '" — keeping first registration',
				'[IdentityVerifyRegistry] identityVerifyProvider "(null)" is unset or unregistered — resolving fail-closed default "' . $id . '" (identity NOT verified)',
				'[IdentityVerifyRegistry] identityVerifyProvider "" is unset or unregistered — resolving fail-closed default "' . $id . '" (identity NOT verified)',
				'[IdentityVerifyRegistry] identityVerifyProvider "unknown" is unset or unregistered — resolving fail-closed default "' . $id . '" (identity NOT verified)',
			],
			$this->warnings
		);

	}//end testIdentityRegistry()

	/**
	 * Regulator registry: same contract, its own wording.
	 *
	 * @return void
	 */
	public function testRegulatorRegistry(): void {
		$default = new NullRegulatorEscalateProvider();
		$registry = new RegulatorEscalateRegistry(logger: $this->logger(), default: $default);
		$first = $this->createMock(RegulatorEscalateProvider::class);
		$first->method('getProviderId')->willReturn('ap');
		$second = $this->createMock(RegulatorEscalateProvider::class);
		$second->method('getProviderId')->willReturn('ap');

		$this->assertTrue($registry->addProvider(provider: $first));
		$this->assertFalse($registry->addProvider(provider: $second));
		$this->assertSame($first, $registry->get(id: 'ap'));
		$this->assertNull($registry->get(id: 'nope'));
		$this->assertSame([$default, $first], $registry->list());
		$this->assertSame([$default->getProviderId(), 'ap'], $registry->listIds());
		$this->assertSame($first, $registry->resolve(selectorId: 'ap'));
		$this->assertSame($default, $registry->resolve(selectorId: null));
		$this->assertSame($default, $registry->resolve(selectorId: 'unknown'));

		$id = $default->getProviderId();
		$this->assertSame(
			[
				'[RegulatorEscalateRegistry] duplicate provider id "ap" — keeping first registration',
				'[RegulatorEscalateRegistry] regulatorEscalateProvider "(null)" is unset or unregistered — resolving fail-closed default "' . $id . '" (escalation NOT performed)',
				'[RegulatorEscalateRegistry] regulatorEscalateProvider "unknown" is unset or unregistered — resolving fail-closed default "' . $id . '" (escalation NOT performed)',
			],
			$this->warnings
		);

	}//end testRegulatorRegistry()

	/**
	 * Evidence registry: starts empty, duplicates refused, withProviders replaces.
	 *
	 * @return void
	 */
	public function testEvidenceRegistry(): void {
		$registry = new EvidenceSourceRegistry(logger: $this->logger());
		$a = $this->createMock(EvidenceSourceProvider::class);
		$a->method('getSourceId')->willReturn('mail');
		$a2 = $this->createMock(EvidenceSourceProvider::class);
		$a2->method('getSourceId')->willReturn('mail');
		$b = $this->createMock(EvidenceSourceProvider::class);
		$b->method('getSourceId')->willReturn('files');

		$this->assertSame([], $registry->list());
		$this->assertSame([], $registry->listIds());
		$this->assertTrue($registry->addProvider(provider: $a));
		$this->assertFalse($registry->addProvider(provider: $a2));
		$this->assertSame($a, $registry->get(id: 'mail'));
		$this->assertNull($registry->get(id: 'files'));

		$registry->withProviders(providers: [$b, $a2, $a]);
		$this->assertSame([$b, $a2], $registry->list());
		$this->assertSame(['files', 'mail'], $registry->listIds());
		$this->assertSame($a2, $registry->get(id: 'mail'));

		$registry->withProviders(providers: []);
		$this->assertSame([], $registry->list());

		$this->assertSame(
			[
				'[EvidenceSourceRegistry] duplicate source id "mail" — keeping first registration',
				'[EvidenceSourceRegistry] duplicate source id "mail" — keeping first registration',
			],
			$this->warnings
		);

	}//end testEvidenceRegistry()

	/**
	 * Identity result: closed status set, only `verified` verifies.
	 *
	 * @return void
	 */
	public function testIdentityResult(): void {
		$this->assertSame(
			['status' => 'verified', 'provider' => 'p', 'message' => 'ok'],
			IdentityVerifyResult::verified(providerId: 'p', message: 'ok')->toArray()
		);
		$this->assertTrue(IdentityVerifyResult::verified(providerId: 'p')->isVerified());
		$this->assertFalse(IdentityVerifyResult::failed(providerId: 'p')->isVerified());
		$needsMore = IdentityVerifyResult::needsMore(providerId: 'p');
		$this->assertFalse($needsMore->isVerified());
		$this->assertSame('needs-more', $needsMore->getStatus());
		$this->assertSame('p', $needsMore->getProviderId());
		$this->assertNull($needsMore->getMessage());

		try {
			new IdentityVerifyResult(status: 'VERIFIED', providerId: 'p');
			$this->fail('An unknown status must be refused.');
		} catch (InvalidArgumentException $e) {
			$this->assertSame(
				'IdentityVerifyResult status "VERIFIED" is invalid; expected one of: verified, failed, needs-more',
				$e->getMessage()
			);
		}

	}//end testIdentityResult()

	/**
	 * Regulator result: closed status set, only `escalated` escalates.
	 *
	 * @return void
	 */
	public function testRegulatorResult(): void {
		$escalated = RegulatorEscalateResult::escalated(providerId: 'p', reference: 'AP-1', message: 'm');
		$this->assertTrue($escalated->isEscalated());
		$this->assertSame(
			['status' => 'escalated', 'provider' => 'p', 'reference' => 'AP-1', 'message' => 'm'],
			$escalated->toArray()
		);
		$refused = RegulatorEscalateResult::refused(providerId: 'p');
		$this->assertFalse($refused->isEscalated());
		$this->assertSame('refused', $refused->getStatus());
		$this->assertSame('p', $refused->getProviderId());
		$this->assertSame('', $refused->getReference());
		$this->assertNull($refused->getMessage());
		$this->assertFalse((new RegulatorEscalateResult(status: 'refused', providerId: 'x'))->isEscalated());

		try {
			new RegulatorEscalateResult(status: 'done', providerId: 'p', reference: 'r');
			$this->fail('An unknown status must be refused.');
		} catch (InvalidArgumentException $e) {
			$this->assertSame(
				'RegulatorEscalateResult status "done" is invalid; expected one of: escalated, refused',
				$e->getMessage()
			);
		}

	}//end testRegulatorResult()
}//end class
