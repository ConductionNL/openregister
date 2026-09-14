<?php

/**
 * Reports which implementation answers behind OpenRegister's DI seams.
 *
 * Three connections are chosen by a dependency-injection binding, not by a
 * setting: the translation provider and the two DSAR seams. Only OpenRegister
 * can tell which implementation is bound, and it can only tell by resolving the
 * service. Doing that at boot would cost every request (ADR-076), and a repair
 * step runs before integriq has synced the rows. So this job resolves the three
 * seams from cron and sends integriq one report each (hydra connection-registry
 * D6 and D12, reportedOnly rows).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use OCA\OpenRegister\Service\Connection\ConnectionReporter;
use OCA\OpenRegister\Service\Gdpr\Identity\IdentityVerifyRegistry;
use OCA\OpenRegister\Service\Gdpr\Identity\NullIdentityVerifyProvider;
use OCA\OpenRegister\Service\Gdpr\Regulator\NullRegulatorEscalateProvider;
use OCA\OpenRegister\Service\Gdpr\Regulator\RegulatorEscalateRegistry;
use OCA\OpenRegister\Service\Translation\TranslationProviderInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Sends one connection report per DI seam, every six hours.
 */
class ConnectionSeamReportJob extends TimedJob {

	/**
	 * How often the seams are reported, in seconds.
	 *
	 * A binding only changes when an app is deployed or enabled, so six hours
	 * keeps the page current within a working day without writing integriq's
	 * rows every hour for nothing.
	 *
	 * @var integer
	 */
	private const INTERVAL_SECONDS = 21600;

	/**
	 * The identifier IdentityTranslationProvider reports.
	 *
	 * @var string
	 */
	private const IDENTITY_TRANSLATION = 'identity';

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory       $time      Time factory for TimedJob.
	 * @param ContainerInterface $container Resolves each seam on its own, so one broken binding reports only itself.
	 * @param ConnectionReporter $reporter  Sends the reports to integriq.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly ContainerInterface $container,
		private readonly ConnectionReporter $reporter,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL_SECONDS);
	}//end __construct()

	/**
	 * Report the three seams.
	 *
	 * @param mixed $argument Job argument (unused).
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md
	 */
	protected function run($argument): void {
		$this->reportSeam(key: 'translation', describe: fn (): array => $this->describeTranslation());
		$this->reportSeam(key: 'dsar-identity', describe: fn (): array => $this->describeIdentity());
		$this->reportSeam(key: 'dsar-regulator', describe: fn (): array => $this->describeRegulator());
	}//end run()

	/**
	 * What answers behind the translation seam.
	 *
	 * @return array{0: string, 1: string} The status and the message.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md
	 */
	private function describeTranslation(): array {
		/*
		 * @var TranslationProviderInterface $provider
		 */

		$provider = $this->container->get(TranslationProviderInterface::class);
		$identifier = $provider->getIdentifier();

		if ($identifier === self::IDENTITY_TRANSLATION) {
			return [
				'simulated',
				'The identity provider is bound. A translation returns the source text unchanged. Bind a real TranslationProviderInterface to translate.',
			];
		}

		return ['configured', 'Translation provider "' . $identifier . '" is bound. Not tested.'];
	}//end describeTranslation()

	/**
	 * What answers behind the DSAR identity-verify seam.
	 *
	 * @return array{0: string, 1: string} The status and the message.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md
	 */
	private function describeIdentity(): array {
		/*
		 * @var IdentityVerifyRegistry $registry
		 */

		$registry = $this->container->get(IdentityVerifyRegistry::class);
		$others = array_values(array_diff($registry->listIds(), [NullIdentityVerifyProvider::PROVIDER_ID]));

		if ($others === []) {
			return [
				'simulated',
				'Only the fail-closed default is registered. It refuses every identity check and answers needs-more, so no subject is verified.',
			];
		}

		return [
			'configured',
			'Registered identity providers: ' . implode(', ', $others) . '. A policy pack chooses which one runs.',
		];
	}//end describeIdentity()

	/**
	 * What answers behind the DSAR regulator-escalate seam.
	 *
	 * @return array{0: string, 1: string} The status and the message.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md
	 */
	private function describeRegulator(): array {
		/*
		 * @var RegulatorEscalateRegistry $registry
		 */

		$registry = $this->container->get(RegulatorEscalateRegistry::class);
		$others = array_values(array_diff($registry->listIds(), [NullRegulatorEscalateProvider::PROVIDER_ID]));

		if ($others === []) {
			return [
				'simulated',
				'Only the fail-closed default is registered. It refuses every escalation, so nothing reaches a regulator.',
			];
		}

		return [
			'configured',
			'Registered regulator providers: ' . implode(', ', $others) . '. A policy pack chooses which one runs.',
		];
	}//end describeRegulator()

	/**
	 * Resolve one seam and report it; a resolve that throws reports an error for that seam only.
	 *
	 * @param string                                   $key      The connection key.
	 * @param callable(): array{0: string, 1: string} $describe Resolves the seam and names its status.
	 *
	 * @return void
	 */
	private function reportSeam(string $key, callable $describe): void {
		try {
			[$status, $message] = $describe();
		} catch (Throwable $e) {
			$status = 'error';
			$message = 'The seam could not be resolved: ' . $e->getMessage();
		}

		$this->reporter->report(key: $key, status: $status, message: $message);
	}//end reportSeam()
}//end class
