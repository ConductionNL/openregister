<?php

/**
 * Characterisation tests for ContentProvider's opted-in pair resolution.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\ContextChat
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\ContextChat;

use OCA\OpenRegister\ContextChat\ContentProvider;
use OCA\OpenRegister\ContextChat\Support\ContentProviderLinks;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaCatalog;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Mcp\Listener\ContextChatSubmissionListener;
use OCA\OpenRegister\Service\DeepLinkRegistryService;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Pins resolveOptedInPairs(): the schema enumeration failure, the opt-in and
 * schema-id filters, the register-id filter, a register that cannot be loaded,
 * and the order of the pairs.
 */
class ContentProviderOptedInPairsTest extends TestCase {

	private SchemaMapper&MockObject $schemaMapper;

	private RegisterMapper&MockObject $registerMapper;

	private LoggerInterface&MockObject $logger;

	private ContentProvider $provider;

	protected function setUp(): void {
		parent::setUp();

		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->provider = new ContentProvider(
			$this->createMock(ContextChatSubmissionListener::class),
			new SchemaCatalog($this->registerMapper, $this->schemaMapper, $this->createMock(MagicMapper::class)),
			new ContentProviderLinks($this->createMock(DeepLinkRegistryService::class), $this->createMock(IURLGenerator::class)),
			$this->logger
		);
	}

	/**
	 * Call the private resolveOptedInPairs() and reduce the pairs to [registerId, schemaId].
	 *
	 * @param int|null $registerId The register filter.
	 * @param int|null $schemaId   The schema filter.
	 *
	 * @return array<int, array{0: int, 1: int}> The pairs as ids.
	 */
	private function pairs(?int $registerId, ?int $schemaId): array {
		$method = new ReflectionMethod(ContentProvider::class, 'resolveOptedInPairs');
		$pairs = $method->invoke($this->provider, $registerId, $schemaId);

		return array_map(
			fn (array $pair): array => [$pair[0]->getId(), $pair[1]->getId()],
			$pairs
		);
	}

	private function schema(int $id, bool $optedIn): Schema {
		$schema = new Schema();
		$schema->setId($id);
		if ($optedIn === true) {
			$schema->setConfiguration(['x-openregister-contextchat' => true]);
		}

		return $schema;
	}

	/**
	 * Schemas 1 (opted in, registers 10, 11, 12), 2 (opted out, register 10),
	 * 3 (opted in, registers 10 and 13). Register 12 cannot be loaded.
	 */
	private function catalogue(): void {
		$this->schemaMapper->expects($this->once())->method('findAll')
			->willReturn([$this->schema(1, true), $this->schema(2, false), $this->schema(3, true)]);
		$this->registerMapper->method('getAllRegisterIdsWithSchema')
			->willReturnMap([[1, [10, '11', 12]], [2, [10]], [3, [10, 13]]]);
		$this->registerMapper->method('find')->willReturnCallback(
			function (string|int $id, bool $_rbac, bool $_multitenancy): Register {
				$this->assertFalse($_rbac);
				$this->assertFalse($_multitenancy);
				if ((int) $id === 12) {
					throw new \RuntimeException('gone');
				}

				$register = new Register();
				$register->setId((int) $id);
				return $register;
			}
		);
	}

	public function testEveryOptedInPairInOrderWithoutAFilter(): void {
		$this->catalogue();

		$this->assertSame([[10, 1], [11, 1], [10, 3], [13, 3]], $this->pairs(null, null));
	}

	public function testTheSchemaFilterKeepsOnlyThatSchema(): void {
		$this->catalogue();

		$this->assertSame([[10, 3], [13, 3]], $this->pairs(null, 3));
	}

	public function testTheSchemaFilterDoesNotLetAnOptedOutSchemaIn(): void {
		$this->catalogue();

		$this->assertSame([], $this->pairs(null, 2));
	}

	public function testTheRegisterFilterComparesAsIntegers(): void {
		$this->catalogue();

		$this->assertSame([[11, 1]], $this->pairs(11, null));
	}

	public function testTheRegisterAndSchemaFiltersCombine(): void {
		$this->catalogue();

		$this->assertSame([[10, 3]], $this->pairs(10, 3));
	}

	public function testARegisterThatCannotBeLoadedIsSkipped(): void {
		$this->catalogue();

		$this->assertSame([], $this->pairs(12, null));
	}

	public function testASchemaEnumerationFailureIsLoggedAndYieldsNoPairs(): void {
		$this->schemaMapper->method('findAll')->willThrowException(new \RuntimeException('db down'));
		$this->registerMapper->expects($this->never())->method('getAllRegisterIdsWithSchema');
		$this->logger->expects($this->once())->method('warning')->with(
			'[ContentProvider] Failed to enumerate schemas for reindex',
			$this->callback(fn (array $context): bool => $context['error'] === 'db down' && $context['file'] !== '')
		);

		$this->assertSame([], $this->pairs(null, null));
	}
}//end class
