<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\TranslationMapper;
use OCA\OpenRegister\Service\BulkTranslationService;
use OCA\OpenRegister\Service\Object\TranslationHandler;
use OCA\OpenRegister\Service\ObjectTranslationRequest;
use OCA\OpenRegister\Service\Translation\TranslationProviderInterface;
use OCA\OpenRegister\Service\TranslationStores;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins what a failing sidecar write does: the translation still counts, and
 * the failure is logged with the object, property and target language.
 *
 * @covers \OCA\OpenRegister\Service\BulkTranslationService
 */
class BulkTranslationServiceSidecarFailureTest extends TestCase {

	/**
	 * A sidecar upsert that throws is logged and does not undo the translation.
	 *
	 * @return void
	 */
	public function testAFailingSidecarWriteIsLoggedAndTheTranslationStillCounts(): void {
		$provider = $this->createMock(TranslationProviderInterface::class);
		$provider->method('getIdentifier')->willReturn('fake');
		$provider->method('translate')->willReturn('HELLO');

		$mapper = $this->createMock(TranslationMapper::class);
		$mapper->method('upsert')->willThrowException(new Exception('sidecar down'));

		$handler = $this->createMock(TranslationHandler::class);
		$handler->method('getTranslatableProperties')->willReturn(['title']);

		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturn(new Schema());

		$warnings = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			static function ($message) use (&$warnings): void {
				$warnings[] = $message;
			}
		);

		$object = new ObjectEntity();
		$object->setUuid('obj-1');
		$object->setSchema('7');
		$object->setObject(['title' => ['nl' => 'hallo']]);

		$service = new BulkTranslationService($provider, new TranslationStores($mapper, $handler), $schemaMapper, $logger);
		$result = $service->translate(new ObjectTranslationRequest($object, 'nl', 'en'), null);

		$this->assertSame(['[BulkTranslationService] sidecar upsert failed for obj-1/title/en: sidecar down'], $warnings);
		$this->assertSame(['translated' => ['title' => 'HELLO'], 'skipped' => []], $result);
	}//end testAFailingSidecarWriteIsLoggedAndTheTranslationStillCounts()
}//end class
