<?php

/**
 * ReferencePreviewInvalidation: clearing the reference previews of a saved object.
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
use OCA\OpenRegister\Service\DeepLinkRegistryService;
use OCA\OpenRegister\Service\Object\ReferencePreviewInvalidation;
use OCA\OpenRegister\Service\Object\SavedObjectScope;
use OCA\OpenRegister\Service\Reference\ObjectPreviewFormatter;
use OCA\OpenRegister\Service\Reference\ObjectPreviewSources;
use OCP\Collaboration\Reference\IReferenceManager;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Every canonical URL collapses to one cache prefix that is cleared once; the deep link is cleared when there is one.
 *
 * @covers \OCA\OpenRegister\Service\Object\ReferencePreviewInvalidation
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
final class ReferencePreviewInvalidationTest extends TestCase {

	private const UUID = '0b7c2f1e-4d3a-4b5c-9e8f-1a2b3c4d5e6f';

	/**
	 * The cache prefixes that were invalidated, in order.
	 *
	 * @var list<string>
	 */
	private array $cleared = [];

	/**
	 * The arguments the deep link registry was asked to resolve.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $resolved = [];

	/**
	 * An invalidation whose deep link registry answers that URL.
	 *
	 * @param string|null $deepLink The deep link, or null when none is registered.
	 *
	 * @return ReferencePreviewInvalidation
	 */
	private function invalidation(?string $deepLink): ReferencePreviewInvalidation {
		$references = $this->createMock(originalClassName: IReferenceManager::class);
		$references->method('invalidateCache')->willReturnCallback(
			function (string $cachePrefix): void {
				$this->cleared[] = $cachePrefix;
			}
		);

		$urls = $this->createMock(originalClassName: IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturn('https://cloud.example/');

		$deepLinks = $this->createMock(originalClassName: DeepLinkRegistryService::class);
		$deepLinks->method('resolveUrl')->willReturnCallback(
			function (int $registerId, int $schemaId, array $objectData) use ($deepLink): ?string {
				$this->resolved[] = ['registerId' => $registerId, 'schemaId' => $schemaId, 'objectData' => $objectData];
				return $deepLink;
			}
		);

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

		return new ReferencePreviewInvalidation(references: $references, formatter: $formatter, deepLinks: $deepLinks);
	}//end invalidation()

	/**
	 * A saved object in register 3, schema 5.
	 *
	 * @param array<string, mixed> $data The object data.
	 *
	 * @return SavedObjectScope
	 */
	private static function saved(array $data): SavedObjectScope {
		$object = new ObjectEntity();
		$object->setUuid(self::UUID);
		$object->setObject($data);

		return new SavedObjectScope(object: $object, registerId: 3, schemaId: 5);
	}//end saved()

	/**
	 * The three canonical URL shapes share one prefix, cleared once, then the deep link is cleared.
	 *
	 * @return void
	 */
	public function testThePrefixIsClearedOnceAndTheDeepLinkAfterIt(): void {
		$this->invalidation(deepLink: 'https://cloud.example/apps/zaken/#/case/1')->invalidate(saved: self::saved(data: ['title' => 'Case']));

		$this->assertSame(expected: ['3/5/'.self::UUID, 'https://cloud.example/apps/zaken/#/case/1'], actual: $this->cleared);
	}//end testThePrefixIsClearedOnceAndTheDeepLinkAfterIt()

	/**
	 * The deep link is resolved from the object's fields with the identity triple laid over them.
	 *
	 * @return void
	 */
	public function testTheDeepLinkIsResolvedWithTheIdentityTriple(): void {
		$this->invalidation(deepLink: null)->invalidate(saved: self::saved(data: ['title' => 'Case', 'uuid' => 'stale', 'register' => 99]));

		$this->assertSame(
			expected: [
				[
					'registerId' => 3,
					'schemaId'   => 5,
					'objectData' => ['id' => self::UUID, 'title' => 'Case', 'uuid' => self::UUID, 'register' => 3, 'schema' => 5],
				],
			],
			actual: $this->resolved
		);
	}//end testTheDeepLinkIsResolvedWithTheIdentityTriple()

	/**
	 * Without a registered deep link only the canonical prefix is cleared.
	 *
	 * @return void
	 */
	public function testNoDeepLinkClearsOnlyThePrefix(): void {
		$this->invalidation(deepLink: null)->invalidate(saved: self::saved(data: []));

		$this->assertSame(expected: ['3/5/'.self::UUID], actual: $this->cleared);
	}//end testNoDeepLinkClearsOnlyThePrefix()
}//end class
