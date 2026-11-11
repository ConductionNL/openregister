<?php

/**
 * ConnectionSeamReportJob behaviour tests.
 *
 * Every seam is answered by a mocked interface, so these tests measure the
 * job's own decisions (which status, which message, which seam is isolated
 * when another throws) and nothing behind the seams.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\ConnectionSeamReportJob;
use OCA\OpenRegister\Service\Connection\ConnectionReporterInterface;
use OCA\OpenRegister\Service\Gdpr\Identity\IdentityVerifyProvider;
use OCA\OpenRegister\Service\Gdpr\Identity\IdentityVerifyRegistryInterface;
use OCA\OpenRegister\Service\Gdpr\Regulator\RegulatorEscalateProvider;
use OCA\OpenRegister\Service\Gdpr\Regulator\RegulatorEscalateRegistryInterface;
use OCA\OpenRegister\Service\Translation\TranslationProviderInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Behaviour tests for the seam report job.
 */
class ConnectionSeamReportJobBehaviourTest extends TestCase {

	/**
	 * Reports sent, in order, as [key, status, message].
	 *
	 * @var list<array{0: string, 1: string, 2: string}>
	 */
	private array $sent = [];

	/**
	 * Build the job over a container answering from $services and run it once.
	 *
	 * @param array<string, object> $services Service (or Throwable to throw) by id.
	 *
	 * @return ConnectionSeamReportJob The job that ran.
	 */
	private function runWith(array $services): ConnectionSeamReportJob {
		$this->sent = [];

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($services): object {
				$service = $services[$id];
				if ($service instanceof \Throwable) {
					throw $service;
				}

				return $service;
			}
		);

		$reporter = $this->createMock(ConnectionReporterInterface::class);
		$reporter->expects($this->exactly(3))->method('report')->willReturnCallback(
			function (string $key, string $status, string $message = ''): bool {
				$this->sent[] = [$key, $status, $message];
				return true;
			}
		);

		$job = new ConnectionSeamReportJob(
			$this->createMock(ITimeFactory::class),
			$container,
			$reporter,
		);

		(new ReflectionMethod(ConnectionSeamReportJob::class, 'run'))->invoke($job, null);

		return $job;
	}//end runWith()

	/**
	 * Services where every seam has only its default stand-in.
	 *
	 * @return array<string, object> Services by id.
	 */
	private function defaults(): array {
		$translation = $this->createMock(TranslationProviderInterface::class);
		$translation->method('getIdentifier')->willReturn('identity');

		$identity = $this->createMock(IdentityVerifyRegistryInterface::class);
		$identity->method('listIds')->willReturn([IdentityVerifyProvider::NULL_PROVIDER_ID]);

		$regulator = $this->createMock(RegulatorEscalateRegistryInterface::class);
		$regulator->method('listIds')->willReturn([RegulatorEscalateProvider::NULL_PROVIDER_ID]);

		return [
			TranslationProviderInterface::class       => $translation,
			IdentityVerifyRegistryInterface::class    => $identity,
			RegulatorEscalateRegistryInterface::class => $regulator,
		];
	}//end defaults()

	/**
	 * The job reports every six hours.
	 *
	 * @return void
	 */
	public function testIntervalIsSixHours(): void {
		$job = $this->runWith($this->defaults());

		$this->assertSame(21600, $job->getInterval());
	}//end testIntervalIsSixHours()

	/**
	 * Defaults only: all three seams report simulated, in seam order.
	 *
	 * @return void
	 */
	public function testDefaultsReportSimulatedInOrder(): void {
		$this->runWith($this->defaults());

		$this->assertSame(['translation', 'dsar-identity', 'dsar-regulator'], array_column($this->sent, 0));
		$this->assertSame(['simulated', 'simulated', 'simulated'], array_column($this->sent, 1));
		$this->assertStringContainsString('source text unchanged', $this->sent[0][2]);
		$this->assertStringContainsString('refuses every identity check', $this->sent[1][2]);
		$this->assertStringContainsString('refuses every escalation', $this->sent[2][2]);
	}//end testDefaultsReportSimulatedInOrder()

	/**
	 * An empty registry (not even the default) still reads simulated, not configured.
	 *
	 * @return void
	 */
	public function testEmptyRegistriesReadSimulated(): void {
		$services = $this->defaults();
		$identity = $this->createMock(IdentityVerifyRegistryInterface::class);
		$identity->method('listIds')->willReturn([]);
		$regulator = $this->createMock(RegulatorEscalateRegistryInterface::class);
		$regulator->method('listIds')->willReturn([]);
		$services[IdentityVerifyRegistryInterface::class]    = $identity;
		$services[RegulatorEscalateRegistryInterface::class] = $regulator;

		$this->runWith($services);

		$this->assertSame('simulated', $this->sent[1][1]);
		$this->assertSame('simulated', $this->sent[2][1]);
	}//end testEmptyRegistriesReadSimulated()

	/**
	 * A real translation provider is configured and named.
	 *
	 * @return void
	 */
	public function testNonIdentityTranslationReadsConfigured(): void {
		$services    = $this->defaults();
		$translation = $this->createMock(TranslationProviderInterface::class);
		$translation->method('getIdentifier')->willReturn('deepl');
		$services[TranslationProviderInterface::class] = $translation;

		$this->runWith($services);

		$this->assertSame(['translation', 'configured', 'Translation provider "deepl" is bound. Not tested.'], $this->sent[0]);
		$this->assertSame('simulated', $this->sent[1][1]);
	}//end testNonIdentityTranslationReadsConfigured()

	/**
	 * Registered identity providers beside the default are listed, the default is not.
	 *
	 * @return void
	 */
	public function testIdentityProvidersReadConfiguredWithoutTheDefault(): void {
		$services = $this->defaults();
		$identity = $this->createMock(IdentityVerifyRegistryInterface::class);
		$identity->method('listIds')->willReturn(['a.digid', IdentityVerifyProvider::NULL_PROVIDER_ID, 'b.eherkenning']);
		$services[IdentityVerifyRegistryInterface::class] = $identity;

		$this->runWith($services);

		$this->assertSame(
			['dsar-identity', 'configured', 'Registered identity providers: a.digid, b.eherkenning. A policy pack chooses which one runs.'],
			$this->sent[1]
		);
		$this->assertSame('simulated', $this->sent[2][1]);
	}//end testIdentityProvidersReadConfiguredWithoutTheDefault()

	/**
	 * Registered regulator providers beside the default are listed, the default is not.
	 *
	 * @return void
	 */
	public function testRegulatorProvidersReadConfiguredWithoutTheDefault(): void {
		$services  = $this->defaults();
		$regulator = $this->createMock(RegulatorEscalateRegistryInterface::class);
		$regulator->method('listIds')->willReturn([RegulatorEscalateProvider::NULL_PROVIDER_ID, 'ap.nl']);
		$services[RegulatorEscalateRegistryInterface::class] = $regulator;

		$this->runWith($services);

		$this->assertSame(
			['dsar-regulator', 'configured', 'Registered regulator providers: ap.nl. A policy pack chooses which one runs.'],
			$this->sent[2]
		);
		$this->assertSame('simulated', $this->sent[1][1]);
	}//end testRegulatorProvidersReadConfiguredWithoutTheDefault()

	/**
	 * A seam whose resolve throws reports error for itself; the others still report.
	 *
	 * @return void
	 */
	public function testThrowingSeamReportsErrorAndOthersContinue(): void {
		$services = $this->defaults();
		$services[TranslationProviderInterface::class]    = new RuntimeException('no binding');
		$services[IdentityVerifyRegistryInterface::class] = new \Error('factory broke');

		$this->runWith($services);

		$this->assertSame(['translation', 'error', 'The seam could not be resolved: no binding'], $this->sent[0]);
		$this->assertSame(['dsar-identity', 'error', 'The seam could not be resolved: factory broke'], $this->sent[1]);
		$this->assertSame('simulated', $this->sent[2][1]);
	}//end testThrowingSeamReportsErrorAndOthersContinue()
}//end class
