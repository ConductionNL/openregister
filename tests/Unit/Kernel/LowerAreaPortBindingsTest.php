<?php

/**
 * The Notifications and Processes ports are bound to the Configuration classes behind them.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Kernel
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Kernel;

use OCA\OpenRegister\BulkAction\BulkActionInterface;
use OCA\OpenRegister\BulkAction\ExportWholeSetAction;
use OCA\OpenRegister\Kernel\Contracts\Notifications\DeclaredWebhookInstallerInterface;
use OCA\OpenRegister\Kernel\Contracts\Notifications\ReferencedAddressLimits;
use OCA\OpenRegister\Kernel\Contracts\Processes\TranslationProjectionInterface;
use OCA\OpenRegister\Kernel\Contracts\Processes\WholeSetExportActionInterface;
use OCA\OpenRegister\Service\ContractBindings;
use OCA\OpenRegister\Service\Notification\NotificationsAnnotationInstaller;
use OCA\OpenRegister\Service\Notification\NotificationsAnnotationInstallerInterface;
use OCA\OpenRegister\Service\Notification\ReferencedAddressNotifier;
use OCA\OpenRegister\Service\Notification\ReferencedAddressNotifierInterface;
use OCA\OpenRegister\Service\TranslationProjectionService;
use OCA\OpenRegister\Service\TranslationProjectionServiceInterface;
use OCA\OpenRegister\Tests\Unit\AppHost\RecordingRegistrationContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Each port is bound in Configuration's own wiring, and the existing interface is the port.
 */
final class LowerAreaPortBindingsTest extends TestCase {

	/**
	 * The port, the class Configuration binds to it, and the interface that already named the class.
	 *
	 * @return array<string, array{class-string, class-string, class-string}>
	 */
	public static function ports(): array {
		return [
			'declared webhooks' => [
				DeclaredWebhookInstallerInterface::class,
				NotificationsAnnotationInstaller::class,
				NotificationsAnnotationInstallerInterface::class,
			],
			'translation projection' => [
				TranslationProjectionInterface::class,
				TranslationProjectionService::class,
				TranslationProjectionServiceInterface::class,
			],
			'whole-set export' => [WholeSetExportActionInterface::class, ExportWholeSetAction::class, BulkActionInterface::class],
		];
	}

	#[DataProvider('ports')]
	public function testThePortIsBoundToTheClassBehindIt(string $port, string $class, string $existing): void {
		$context = new RecordingRegistrationContext();
		ContractBindings::register(context: $context);

		$this->assertContains(['alias' => $port, 'target' => $class], $context->aliases);
	}

	#[DataProvider('ports')]
	public function testTheClassImplementsThePortAndTheInterfaceItHad(string $port, string $class, string $existing): void {
		$this->assertTrue(is_subclass_of($class, $port));
		$this->assertTrue(is_subclass_of($class, $existing));
	}

	public function testTheExistingInterfacesAreThePorts(): void {
		$this->assertTrue(is_subclass_of(NotificationsAnnotationInstallerInterface::class, DeclaredWebhookInstallerInterface::class));
		$this->assertTrue(is_subclass_of(TranslationProjectionServiceInterface::class, TranslationProjectionInterface::class));
		$this->assertTrue(is_subclass_of(WholeSetExportActionInterface::class, BulkActionInterface::class));
		$this->assertSame(['handle'], get_class_methods(NotificationsAnnotationInstallerInterface::class));
		$this->assertSame(['project', 'purge'], get_class_methods(TranslationProjectionServiceInterface::class));
	}

	public function testTheNotifierAnswersToTheLimitsTheValidatorChecks(): void {
		$this->assertTrue(is_subclass_of(ReferencedAddressNotifierInterface::class, ReferencedAddressLimits::class));
		$this->assertTrue(is_subclass_of(ReferencedAddressNotifier::class, ReferencedAddressLimits::class));
		$this->assertSame('email', ReferencedAddressLimits::KIND);
		$this->assertSame(3, ReferencedAddressLimits::MAX_HOPS);
		$this->assertSame(ReferencedAddressLimits::KIND, ReferencedAddressNotifierInterface::KIND);
		$this->assertSame(ReferencedAddressLimits::MAX_HOPS, ReferencedAddressNotifier::MAX_HOPS);
		$this->assertSame([], get_class_methods(ReferencedAddressLimits::class));
	}
}//end class
