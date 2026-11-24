<?php

/**
 * The Core settings read ports are bound to the Configuration handlers that keep the settings.
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

use OCA\OpenRegister\Kernel\Contracts\Core\FileSettingsReaderInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\RetentionSettingsInterface;
use OCA\OpenRegister\Service\ContractBindings;
use OCA\OpenRegister\Service\Decision\ReviewPolicy;
use OCA\OpenRegister\Service\Decision\ReviewPolicyFileOwners;
use OCA\OpenRegister\Service\Decision\ReviewPolicyResolver;
use OCA\OpenRegister\Service\Deletion\DeletionWindow;
use OCA\OpenRegister\Service\Deletion\DeletionWindowService;
use OCA\OpenRegister\Service\File\ManagedFileOwnerLookup;
use OCA\OpenRegister\Service\Settings\FileSettingsHandler;
use OCA\OpenRegister\Service\Settings\ObjectRetentionHandler;
use OCA\OpenRegister\Service\Settings\RetentionSettingsReaderInterface;
use OCA\OpenRegister\Db\EntityRelation;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Tests\Unit\AppHost\RecordingRegistrationContext;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Core reads the file and retention settings through its own ports; Configuration answers them.
 */
final class SettingsReadPortsBindingTest extends TestCase {

	public function testThePortsResolveToTheHandlersThatKeepTheSettings(): void {
		$context = new RecordingRegistrationContext();
		ContractBindings::register(context: $context);

		$this->assertContains(['alias' => FileSettingsReaderInterface::class, 'target' => FileSettingsHandler::class], $context->aliases);
		$this->assertContains(['alias' => RetentionSettingsInterface::class, 'target' => ObjectRetentionHandler::class], $context->aliases);
		$this->assertContains(['alias' => RetentionSettingsReaderInterface::class, 'target' => ObjectRetentionHandler::class], $context->aliases);
	}

	public function testTheHandlersAnswerThePorts(): void {
		$this->assertTrue(is_subclass_of(FileSettingsHandler::class, FileSettingsReaderInterface::class));
		$this->assertTrue(is_subclass_of(RetentionSettingsReaderInterface::class, RetentionSettingsInterface::class));
		$this->assertTrue(is_subclass_of(ObjectRetentionHandler::class, RetentionSettingsInterface::class));
	}

	public function testTheDeletionWindowReadsTheInstanceRetentionThroughThePort(): void {
		$settings = $this->createMock(RetentionSettingsInterface::class);
		$settings->expects($this->once())->method('getRetentionSettingsOnly')->willReturn(['objectDeleteRetention' => 2592000000]);

		$service = new DeletionWindowService(retentionHandler: $settings, logger: new NullLogger());

		$this->assertSame(['days' => 30, 'source' => DeletionWindow::SOURCE_INSTANCE], $service->retentionFor(null));
	}

	public function testTheReviewPolicyDefaultIsReadThroughThePort(): void {
		$settings = $this->createMock(FileSettingsReaderInterface::class);
		$declaration = ['ownership' => ReviewPolicy::OWNERSHIP_DECIDER, 'hideOthers' => true];
		$settings->method('getFileSettingsOnly')->willReturn([ReviewPolicy::SETTINGS_KEY => $declaration]);

		$resolver = new ReviewPolicyResolver(
			schemaMapper: $this->createMock(SchemaMapperInterface::class),
			fileSettings: $settings,
			fileOwners: new ReviewPolicyFileOwners($this->createMock(IRootFolder::class), $this->createMock(ManagedFileOwnerLookup::class)),
			logger: new NullLogger(),
		);

		$this->assertTrue($resolver->forRelation(relation: new EntityRelation())['policy']->hidesOthers());
	}
}//end class
