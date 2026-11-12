<?php

/**
 * The DSAR service registrations of OpenRegister.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Gdpr
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Gdpr;

use OCA\OpenRegister\Service\Gdpr\Evidence\EvidenceSourceRegistry;
use OCA\OpenRegister\Service\Gdpr\Export\UnsignedPadesSigner;
use OCA\OpenRegister\Service\Gdpr\Identity\IdentityVerifyRegistry;
use OCA\OpenRegister\Service\Gdpr\Identity\NullIdentityVerifyProvider;
use OCA\OpenRegister\Service\Gdpr\Regulator\NullRegulatorEscalateProvider;
use OCA\OpenRegister\Service\Gdpr\Regulator\RegulatorEscalateRegistry;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use Psr\Container\ContainerInterface;

/**
 * Registers the DSAR signing seam and the evidence, identity-verify and regulator-escalate registries.
 *
 * Application::register() calls register() once.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) A registrar names every service it wires.
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)  Factory closures list every constructor argument.
 */
final class GdprRegistrar {

	/**
	 * Register the DSAR signer and the shared DSAR registries with their fail-closed defaults.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-b2b-crossrefs-2026-04-28/tasks.md
	 */
	public static function register(IRegistrationContext $context): void {
		self::registerSigner(context: $context);
		self::registerEvidenceRegistry(context: $context);
		self::registerIdentitySeam(context: $context);
		self::registerRegulatorSeam(context: $context);
	}//end register()

	/**
	 * Bind the swappable PAdES signing seam to its default SHA-256-only stub.
	 *
	 * DSAR case-engine (dsar-case-engine): the real PAdES-LTV signer drops in
	 * here later by replacing this binding — the ExportBundleService depends
	 * only on the PadesSigner interface, so nothing else changes.
	 * TODO(ADR-047 Phase-1b, DEFERRED): PAdES-LTV signing deferred — the
	 * 2026-07-04 tc-lib-pdf spike was No-Go (8.65 stubs the B-T timestamp).
	 * Interim = SHA-256 hash-only. When resumed, bind a real PadesSigner
	 * against pyHanko (MIT sidecar) or a matured tc-lib-pdf; TSA URL config.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 */
	private static function registerSigner(IRegistrationContext $context): void {
		$context->registerService(
			\OCA\OpenRegister\Service\Gdpr\Export\PadesSigner::class,
			function () {
				return new UnsignedPadesSigner();
			}
		);
	}//end registerSigner()

	/**
	 * DSAR evidence-source registry, shared.
	 *
	 * Shared, so leaf-app addProvider() registrations during boot() persist
	 * for the whole request. The harvest service auto-wires it; OR core
	 * enumerates only registered providers (ADR-019 — an unregistered source
	 * contributes nothing).
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 */
	private static function registerEvidenceRegistry(IRegistrationContext $context): void {
		$context->registerService(
			EvidenceSourceRegistry::class,
			function (ContainerInterface $container) {
				return new EvidenceSourceRegistry(
					logger: $container->get('Psr\Log\LoggerInterface')
				);
			}
		);
	}//end registerEvidenceRegistry()

	/**
	 * DSAR identity-verify seam: the fail-closed default and the shared registry.
	 *
	 * DSAR integration seams (dsar-integration-seams): each seam is a shared
	 * per-request registry (so a leaf app's addProvider() during boot()
	 * persists for the whole request, ADR-019) pre-seeded with the OR
	 * fail-closed default in its constructor. Resolution is
	 * pack-selector-driven and fail-closed: an unset/unknown selector resolves
	 * the default that refuses (never null, never "verified"/"escalated" —
	 * ADR-005/CWE-863).
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 */
	private static function registerIdentitySeam(IRegistrationContext $context): void {
		$context->registerService(NullIdentityVerifyProvider::class, fn () => new NullIdentityVerifyProvider());

		$context->registerService(
			IdentityVerifyRegistry::class,
			fn (ContainerInterface $container) => new IdentityVerifyRegistry(
				logger: $container->get('Psr\Log\LoggerInterface'),
				default: $container->get(NullIdentityVerifyProvider::class)
			)
		);
	}//end registerIdentitySeam()

	/**
	 * DSAR regulator-escalate seam: the fail-closed default and the shared registry.
	 *
	 * Same shape and fail-closed resolution as {@see registerIdentitySeam()}.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 */
	private static function registerRegulatorSeam(IRegistrationContext $context): void {
		$context->registerService(NullRegulatorEscalateProvider::class, fn () => new NullRegulatorEscalateProvider());

		$context->registerService(
			RegulatorEscalateRegistry::class,
			fn (ContainerInterface $container) => new RegulatorEscalateRegistry(
				logger: $container->get('Psr\Log\LoggerInterface'),
				default: $container->get(NullRegulatorEscalateProvider::class)
			)
		);
	}//end registerRegulatorSeam()
}//end class
