<?php

/**
 * ObjectCacheInvalidator: the caches cleared after a save.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\ContactMatchingService;
use OCA\OpenRegister\Service\DeepLinkRegistryService;
use OCA\OpenRegister\Service\Object\ObjectCacheInvalidator;
use OCA\OpenRegister\Service\Object\SavedObjectScope;
use OCA\OpenRegister\Service\Reference\ObjectPreviewFormatter;
use OCA\OpenRegister\Service\Reference\ObjectPreviewSources;
use OCP\AppFramework\IAppContainer;
use OCP\Collaboration\Reference\IReferenceManager;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Both caches are cleared after a save, and a failing one is logged without stopping the other.
 *
 * @covers \OCA\OpenRegister\Service\Object\ObjectCacheInvalidator
 * @uses \OCA\OpenRegister\Service\Object\ReferencePreviewInvalidation
 * @uses \OCA\OpenRegister\Service\Object\SavedObjectScope
 * @uses \OCA\OpenRegister\Service\Reference\ObjectPreviewFormatter
 * @uses \OCA\OpenRegister\Service\Reference\ObjectPreviewSources
 * @uses \OCA\OpenRegister\Kernel\Contracts\ObjectRef
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\BulkBatchDefaults
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\BulkSaveOptions
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\SaveActor
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\SavePolicy
 */
final class ObjectCacheInvalidatorTest extends TestCase {

	private const UUID = '0b7c2f1e-4d3a-4b5c-9e8f-1a2b3c4d5e6f';

	/**
	 * The reference cache prefixes that were invalidated.
	 *
	 * @var list<string>
	 */
	private array $cleared = [];

	/**
	 * The object bodies dropped from the contact-match cache.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $contactDrops = [];

	/**
	 * The warnings logged, as message and context.
	 *
	 * @var list<array{0: string, 1: array<string, mixed>}>
	 */
	private array $warnings = [];

	/**
	 * An invalidator whose container answers the services, or throws for the failing ones.
	 *
	 * @param list<class-string> $failing The services the container cannot resolve.
	 *
	 * @return ObjectCacheInvalidator
	 */
	private function invalidator(array $failing = []): ObjectCacheInvalidator {
		$contacts = $this->createMock(originalClassName: ContactMatchingService::class);
		$contacts->method('invalidateCacheForObject')->willReturnCallback(
			function (array $object): void {
				$this->contactDrops[] = $object;
			}
		);

		$references = $this->createMock(originalClassName: IReferenceManager::class);
		$references->method('invalidateCache')->willReturnCallback(
			function (string $cachePrefix): void {
				$this->cleared[] = $cachePrefix;
			}
		);

		$urls = $this->createMock(originalClassName: IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturn('https://cloud.example/');
		$deepLinks = $this->createMock(originalClassName: DeepLinkRegistryService::class);
		$deepLinks->method('resolveUrl')->willReturn(null);
		$formatter = new ObjectPreviewFormatter(
			urlGenerator: $urls,
			l10n: $this->createMock(originalClassName: IL10N::class),
			sources: new ObjectPreviewSources(
				objectService: $this->createMock(originalClassName: ObjectOperationsInterface::class),
				deepLinkRegistry: $deepLinks,
				schemaMapper: $this->createMock(originalClassName: SchemaMapperInterface::class),
				registerMapper: $this->createMock(originalClassName: RegisterMapperInterface::class)
			),
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);

		$services = [
			ContactMatchingService::class  => $contacts,
			IReferenceManager::class       => $references,
			ObjectPreviewFormatter::class  => $formatter,
			DeepLinkRegistryService::class => $deepLinks,
		];
		$container = $this->createMock(originalClassName: IAppContainer::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($services, $failing): mixed {
				if (in_array(needle: $id, haystack: $failing, strict: true) === true) {
					throw new RuntimeException('cannot resolve '.$id);
				}

				return $services[$id];
			}
		);

		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->warnings[] = [$message, $context];
			}
		);

		return new ObjectCacheInvalidator(container: $container, logger: $logger);
	}//end invalidator()

	/**
	 * A saved object in register 3, schema 5.
	 *
	 * @return SavedObjectScope
	 */
	private static function saved(): SavedObjectScope {
		$object = new ObjectEntity();
		$object->setUuid(self::UUID);
		$object->setObject(['title' => 'Case']);

		return new SavedObjectScope(object: $object, registerId: 3, schemaId: 5);
	}//end saved()

	/**
	 * A save drops the object from the contact-match cache and clears its reference preview.
	 *
	 * @return void
	 */
	public function testBothCachesAreCleared(): void {
		$this->invalidator()->afterSave(saved: self::saved());

		$this->assertSame(expected: [['id' => self::UUID, 'title' => 'Case']], actual: $this->contactDrops);
		$this->assertSame(expected: ['3/5/'.self::UUID], actual: $this->cleared);
		$this->assertSame(expected: [], actual: $this->warnings);
	}//end testBothCachesAreCleared()

	/**
	 * A failing contact-match cache is logged with the object's identity and the previews are still cleared.
	 *
	 * @return void
	 */
	public function testAFailingContactCacheIsLoggedAndThePreviewsStillCleared(): void {
		$this->invalidator(failing: [ContactMatchingService::class])->afterSave(saved: self::saved());

		$this->assertSame(expected: ['3/5/'.self::UUID], actual: $this->cleared);
		$this->assertCount(expectedCount: 1, haystack: $this->warnings);
		[$message, $context] = $this->warnings[0];
		$this->assertSame(expected: '[ObjectService] Skipped contact-match cache invalidation: invalidation failed', actual: $message);
		$this->assertSame(
			expected: ['exception' => 'cannot resolve '.ContactMatchingService::class, 'uuid' => self::UUID, 'register' => 3, 'schema' => 5],
			actual: array_diff_key($context, ['file' => true, 'line' => true])
		);
	}//end testAFailingContactCacheIsLoggedAndThePreviewsStillCleared()

	/**
	 * A failing reference cache is logged and does not undo the contact-match drop.
	 *
	 * @return void
	 */
	public function testAFailingReferenceCacheIsLogged(): void {
		$this->invalidator(failing: [IReferenceManager::class])->afterSave(saved: self::saved());

		$this->assertSame(expected: [['id' => self::UUID, 'title' => 'Case']], actual: $this->contactDrops);
		$this->assertSame(expected: [], actual: $this->cleared);
		$this->assertCount(expectedCount: 1, haystack: $this->warnings);
		$this->assertSame(expected: '[ObjectService] Skipped Smart Picker cache invalidation: invalidation failed', actual: $this->warnings[0][0]);
		$this->assertSame(expected: self::UUID, actual: $this->warnings[0][1]['uuid']);
	}//end testAFailingReferenceCacheIsLogged()
}//end class
