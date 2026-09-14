<?php

/**
 * ConnectionSeamReportJob unit tests.
 *
 * The job is the only thing that tells integriq what answers behind the three
 * DI seams. Every test asserts the report it sends against real registries and
 * the real fail-closed defaults, so a stand-in that starts answering "verified"
 * cannot hide behind a mock.
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
use OCA\OpenRegister\Service\Connection\ConnectionReporter;
use OCA\OpenRegister\Service\Gdpr\Identity\IdentityVerifyProvider;
use OCA\OpenRegister\Service\Gdpr\Identity\IdentityVerifyRegistry;
use OCA\OpenRegister\Service\Gdpr\Identity\NullIdentityVerifyProvider;
use OCA\OpenRegister\Service\Gdpr\Regulator\NullRegulatorEscalateProvider;
use OCA\OpenRegister\Service\Gdpr\Regulator\RegulatorEscalateRegistry;
use OCA\OpenRegister\Service\Translation\IdentityTranslationProvider;
use OCA\OpenRegister\Service\Translation\TranslationProviderInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Unit tests for the seam report job.
 *
 * @covers \OCA\OpenRegister\BackgroundJob\ConnectionSeamReportJob
 */
class ConnectionSeamReportJobTest extends TestCase {

	/**
	 * Every report the job sent, as [key, status, message].
	 *
	 * @var array<string, array{0: string, 1: string}>
	 */
	private array $reports = [];

	/**
	 * Services the mocked container answers with, by id.
	 *
	 * @var array<string, object>
	 */
	private array $services = [];

	/**
	 * Set up real registries with their real defaults.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$this->reports = [];
		$this->services = [
			TranslationProviderInterface::class => new IdentityTranslationProvider(),
			IdentityVerifyRegistry::class => new IdentityVerifyRegistry(logger: $logger, default: new NullIdentityVerifyProvider()),
			RegulatorEscalateRegistry::class => new RegulatorEscalateRegistry(logger: $logger, default: new NullRegulatorEscalateProvider()),
		];
	}//end setUp()

	/**
	 * Run the job once and collect its reports.
	 *
	 * @return void
	 */
	private function runJob(): void {
		/*
		 * @var ContainerInterface&MockObject $container
		 */

		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id): object {
				$service = ($this->services[$id] ?? null);
				if ($service instanceof RuntimeException) {
					throw $service;
				}

				if ($service === null) {
					throw new RuntimeException('No service ' . $id);
				}

				return $service;
			}
		);

		$reporter = $this->getMockBuilder(ConnectionReporter::class)
			->disableOriginalConstructor()
			->onlyMethods(['report'])
			->getMock();
		$reporter->method('report')->willReturnCallback(
			function (string $key, string $status, string $message = ''): bool {
				$this->reports[$key] = [$status, $message];
				return true;
			}
		);

		$job = new ConnectionSeamReportJob(
			time: $this->createMock(originalClassName: ITimeFactory::class),
			container: $container,
			reporter: $reporter,
		);

		$run = new ReflectionMethod(ConnectionSeamReportJob::class, 'run');
		$run->invoke($job, null);
	}//end runJob()

	/**
	 * On a default install all three seams read Simulated, each saying what its stand-in does.
	 *
	 * @return void
	 */
	public function testDefaultBindingsReadSimulated(): void {
		$this->runJob();

		$this->assertSame(expected: ['translation', 'dsar-identity', 'dsar-regulator'], actual: array_keys($this->reports));

		$this->assertSame(expected: 'simulated', actual: $this->reports['translation'][0]);
		$this->assertStringContainsString(needle: 'source text unchanged', haystack: $this->reports['translation'][1]);

		$this->assertSame(expected: 'simulated', actual: $this->reports['dsar-identity'][0]);
		$this->assertStringContainsString(needle: 'refuses', haystack: $this->reports['dsar-identity'][1]);

		$this->assertSame(expected: 'simulated', actual: $this->reports['dsar-regulator'][0]);
		$this->assertStringContainsString(needle: 'refuses', haystack: $this->reports['dsar-regulator'][1]);
	}//end testDefaultBindingsReadSimulated()

	/**
	 * A stand-in never reads as success: no default message claims verification.
	 *
	 * @return void
	 */
	public function testNoStandInClaimsSuccess(): void {
		$this->runJob();

		foreach (['dsar-identity', 'dsar-regulator'] as $key) {
			$this->assertDoesNotMatchRegularExpression(pattern: '/\b(verified|escalated) (the|every)\b/i', string: $this->reports[$key][1]);
		}
	}//end testNoStandInClaimsSuccess()

	/**
	 * A registered identity provider turns the row Configured and is named.
	 *
	 * @return void
	 */
	public function testARegisteredIdentityProviderReadsConfigured(): void {
		$provider = $this->createMock(originalClassName: IdentityVerifyProvider::class);
		$provider->method('getProviderId')->willReturn('pipelinq.digid');
		$this->services[IdentityVerifyRegistry::class]->addProvider($provider);

		$this->runJob();

		$this->assertSame(expected: 'configured', actual: $this->reports['dsar-identity'][0]);
		$this->assertStringContainsString(needle: 'pipelinq.digid', haystack: $this->reports['dsar-identity'][1]);
		$this->assertStringNotContainsString(needle: NullIdentityVerifyProvider::PROVIDER_ID, haystack: $this->reports['dsar-identity'][1]);
		$this->assertSame(expected: 'simulated', actual: $this->reports['dsar-regulator'][0]);
	}//end testARegisteredIdentityProviderReadsConfigured()

	/**
	 * A real translation provider reads Configured and is named.
	 *
	 * @return void
	 */
	public function testARealTranslationProviderReadsConfigured(): void {
		$provider = $this->createMock(originalClassName: TranslationProviderInterface::class);
		$provider->method('getIdentifier')->willReturn('deepl');
		$this->services[TranslationProviderInterface::class] = $provider;

		$this->runJob();

		$this->assertSame(expected: 'configured', actual: $this->reports['translation'][0]);
		$this->assertStringContainsString(needle: 'deepl', haystack: $this->reports['translation'][1]);
	}//end testARealTranslationProviderReadsConfigured()

	/**
	 * A seam that cannot be resolved reports an error for itself only.
	 *
	 * @return void
	 */
	public function testABrokenBindingReportsOnlyItself(): void {
		$this->services[RegulatorEscalateRegistry::class] = new RuntimeException('factory closure failed');

		$this->runJob();

		$this->assertSame(expected: 'error', actual: $this->reports['dsar-regulator'][0]);
		$this->assertStringContainsString(needle: 'factory closure failed', haystack: $this->reports['dsar-regulator'][1]);
		$this->assertSame(expected: 'simulated', actual: $this->reports['translation'][0]);
		$this->assertSame(expected: 'simulated', actual: $this->reports['dsar-identity'][0]);
	}//end testABrokenBindingReportsOnlyItself()
}//end class
