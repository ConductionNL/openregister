<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\File
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/file-actions/spec.md#requirement-every-openregister-file-path-honours-the-restriction-req-pfrr-003
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\File;

use OCA\OpenRegister\Db\FileMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\TranslationMapper;
use OCA\OpenRegister\Service\Calculation\CalculationEvaluator;
use OCA\OpenRegister\Service\File\FileRestrictionGuard;
use OCA\OpenRegister\Service\File\FileRestrictionStore;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\LanguageService;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Object\LinkedEntityEnricher;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\RenderObject;
use OCA\OpenRegister\Service\Object\SaveObject\ComputedFieldHandler;
use OCA\OpenRegister\Service\Object\TranslationHandler;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCA\OpenRegister\Service\Sharing\AccessLinkReader;
use OCA\OpenRegister\Service\TranslationStatusService;
use OCA\OpenRegister\Service\UrnService;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use ReflectionMethod;

/**
 * The rendered object (`@self.files`, file-typed properties) and an access link leave a restricted file out.
 *
 * @spec openspec/specs/file-actions/spec.md#requirement-every-openregister-file-path-honours-the-restriction-req-pfrr-003
 */
class FileRestrictionRenderAndLinkTest extends TestCase {

	private const RESTRICTED = 42;

	private const OPEN = 43;

	/**
	 * A FileMapper record.
	 *
	 * @param int $fileId The file id.
	 *
	 * @return array<string, mixed>
	 */
	private function record(int $fileId): array {
		return [
			'fileid' => (string)$fileId,
			'path' => '/files/' . $fileId . '.pdf',
			'name' => $fileId . '.pdf',
			'mimetype' => 'application/pdf',
			'size' => 1,
			'etag' => 'e',
			'mtime' => 1,
			'accessUrl' => 'https://example.com/s/token-' . $fileId,
			'downloadUrl' => 'https://example.com/s/token-' . $fileId . '/download',
			'published' => null,
		];
	}//end record()

	/**
	 * A renderer for a caller who is not admitted to the restricted file.
	 *
	 * @return RenderObject
	 */
	private function renderer(): RenderObject {
		$fileMapper = $this->createMock(FileMapper::class);
		$fileMapper->method('getFilesForObject')->willReturn([$this->record(self::RESTRICTED), $this->record(self::OPEN)]);
		$fileMapper->method('getFile')->willReturnCallback(fn (int $id): array => $this->record($id));

		$guard = $this->createMock(FileRestrictionGuard::class);
		$guard->method('readable')->willReturnCallback(
			static fn (array $ids): array => array_combine($ids, array_map(static fn (int $id): bool => $id !== self::RESTRICTED, $ids))
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(static fn (string $id): object => $guard);

		return new RenderObject(
			fileMapper: $fileMapper,
			objectEntityMapper: $this->createMock(MagicMapper::class),
			registerMapper: $this->createMock(RegisterMapper::class),
			schemaMapper: $this->createMock(SchemaMapper::class),
			systemTagManager: $this->createMock(ISystemTagManager::class),
			systemTagMapper: $this->createMock(ISystemTagObjectMapper::class),
			cacheHandler: $this->createMock(CacheHandler::class),
			objectCacheService: $this->createMock(CacheHandler::class),
			propertyRbacHandler: $this->createMock(PropertyRbacHandler::class),
			logger: new NullLogger(),
			fileService: $this->createMock(FileService::class),
			computedFieldHandler: $this->createMock(ComputedFieldHandler::class),
			translationHandler: $this->createMock(TranslationHandler::class),
			linkedEntityEnricher: $this->createMock(LinkedEntityEnricher::class),
			calculationEvaluator: $this->createMock(CalculationEvaluator::class),
			urnService: $this->createMock(UrnService::class),
			translationStatusService: $this->createMock(TranslationStatusService::class),
			translationMapper: $this->createMock(TranslationMapper::class),
			languageService: $this->createMock(LanguageService::class),
			container: $container,
		);
	}//end renderer()

	/**
	 * Call a private method.
	 *
	 * @param object       $target The object.
	 * @param string       $name   The method.
	 * @param array<mixed> $args   The arguments.
	 *
	 * @return mixed
	 */
	private function call(object $target, string $name, array $args): mixed {
		$method = new ReflectionMethod($target, $name);
		$method->setAccessible(true);

		return $method->invokeArgs($target, $args);
	}//end call()

	public function testTheRenderedFilesLeaveTheRestrictedFileOut(): void {
		$object = new ObjectEntity();
		$object->setUuid('c0ffee00-0000-4000-8000-000000000001');

		$rendered = $this->call($this->renderer(), 'renderFiles', [$object]);

		$this->assertSame([(string)self::OPEN], array_column($rendered->getFiles(), 'id'));
	}//end testTheRenderedFilesLeaveTheRestrictedFileOut()

	public function testAFileTypedPropertyDoesNotHydrateTheRestrictedFile(): void {
		$renderer = $this->renderer();

		$this->assertNull($this->call($renderer, 'getFileObject', [self::RESTRICTED]));
		$this->assertSame((string)self::OPEN, $this->call($renderer, 'getFileObject', [self::OPEN])['id']);
	}//end testAFileTypedPropertyDoesNotHydrateTheRestrictedFile()

	public function testAnAccessLinkNeverDescribesARestrictedFile(): void {
		$store = $this->createMock(FileRestrictionStore::class);
		$store->method('find')->willReturnCallback(static fn (int $id): ?array => $id === self::RESTRICTED ? ['fileId' => $id] : null);
		$reader = (new \ReflectionClass(AccessLinkReader::class))->newInstanceWithoutConstructor();
		$property = new \ReflectionProperty(AccessLinkReader::class, 'restrictions');
		$property->setAccessible(true);
		$property->setValue($reader, $store);

		$object = new ObjectEntity();
		$object->setFiles([['id' => (string)self::RESTRICTED], ['id' => (string)self::OPEN]]);

		$this->assertNull($this->call($reader, 'fileDescriptor', [$object, (string)self::RESTRICTED]));
		$this->assertSame(['id' => (string)self::OPEN], $this->call($reader, 'fileDescriptor', [$object, (string)self::OPEN]));
	}//end testAnAccessLinkNeverDescribesARestrictedFile()
}//end class
