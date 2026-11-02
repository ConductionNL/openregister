<?php

/**
 * Each hub interface resolves to the service that implements it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Kernel;

use OCA\OpenRegister\Kernel\Contracts\AppSettingsInterface;
use OCA\OpenRegister\Kernel\Contracts\ContactMatcherInterface;
use OCA\OpenRegister\Kernel\Contracts\EmbeddingGeneratorInterface;
use OCA\OpenRegister\Kernel\Contracts\NotificationPreferencesInterface;
use OCA\OpenRegister\Kernel\Contracts\NotificationTemplatesInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectCalendarEventsInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectCalendarTasksInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectNameCacheInterface;
use OCA\OpenRegister\Kernel\Contracts\VocabularyImporterInterface;
use OCA\OpenRegister\Service\CalendarEventService;
use OCA\OpenRegister\Service\ContactMatchingService;
use OCA\OpenRegister\Service\ContractBindings;
use OCA\OpenRegister\Service\Notification\NotificationPreferenceService;
use OCA\OpenRegister\Service\Notification\NotificationTemplateRegistry;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\TaskService;
use OCA\OpenRegister\Service\Vectorization\VectorEmbeddings;
use OCA\OpenRegister\Service\VocabularyImportService;
use OCA\OpenRegister\Tests\Unit\AppHost\RecordingRegistrationContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The container hands out the implementation's shared instance wherever the
 * interface is asked for, so a caller that type-hints the interface and one
 * that type-hints the class get the same object.
 *
 * @covers \OCA\OpenRegister\Service\ContractBindings
 */
class HubInterfaceBindingsTest extends TestCase {

	/**
	 * Interface => the service it must resolve to.
	 *
	 * @return array<string, array{0: class-string, 1: class-string}>
	 */
	public static function bindings(): array {
		return [
			'settings'                   => [AppSettingsInterface::class, SettingsService::class],
			'contact matching'           => [ContactMatcherInterface::class, ContactMatchingService::class],
			'embeddings'                 => [EmbeddingGeneratorInterface::class, VectorEmbeddings::class],
			'notification preferences'   => [NotificationPreferencesInterface::class, NotificationPreferenceService::class],
			'notification templates'     => [NotificationTemplatesInterface::class, NotificationTemplateRegistry::class],
			'object calendar events'     => [ObjectCalendarEventsInterface::class, CalendarEventService::class],
			'object calendar tasks'      => [ObjectCalendarTasksInterface::class, TaskService::class],
			'object names'               => [ObjectNameCacheInterface::class, CacheHandler::class],
			'vocabulary import'          => [VocabularyImporterInterface::class, VocabularyImportService::class],
		];
	}//end bindings()

	/**
	 * The interface is registered as an alias of its implementation, which implements it.
	 *
	 * @param class-string $interface      The interface callers type-hint.
	 * @param class-string $implementation The service behind it.
	 *
	 * @return void
	 */
	#[DataProvider('bindings')]
	public function testTheInterfaceResolvesToItsImplementation(string $interface, string $implementation): void {
		$context = new RecordingRegistrationContext();
		ContractBindings::register(context: $context);

		$this->assertContains(needle: ['alias' => $interface, 'target' => $implementation], haystack: $context->aliases);
		$this->assertTrue(is_subclass_of($implementation, $interface));
	}//end testTheInterfaceResolvesToItsImplementation()

	/**
	 * The vocabulary slugs live on the interface and stay reachable through the class.
	 *
	 * @return void
	 */
	public function testTheVocabularySlugsStayOnTheClass(): void {
		$this->assertSame(expected: 'vocabulary', actual: VocabularyImportService::REGISTER);
		$this->assertSame(expected: 'conceptScheme', actual: VocabularyImporterInterface::SCHEMA_SCHEME);
		$this->assertSame(expected: 'concept', actual: VocabularyImportService::SCHEMA_CONCEPT);
	}//end testTheVocabularySlugsStayOnTheClass()
}//end class
