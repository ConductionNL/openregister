<?php

/**
 * Pins what the SchemaScopedProvider trait decides: slug resolution, memoisation, live title and the flag.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\AppHost
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppHost;

use OCA\OpenRegister\AppHost\SchemaScopedProvider;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Drives the trait through a minimal host that only exposes its private helpers.
 */
class SchemaScopedProviderBehaviourTest extends TestCase {

	/**
	 * Register mapper mock.
	 *
	 * @var RegisterMapper&MockObject
	 */
	private RegisterMapper&MockObject $registerMapper;

	/**
	 * Schema mapper mock.
	 *
	 * @var SchemaMapper&MockObject
	 */
	private SchemaMapper&MockObject $schemaMapper;

	/**
	 * Logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Build the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->schemaMapper   = $this->createMock(SchemaMapper::class);
		$this->logger         = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * A host class using the trait, exposing its helpers.
	 *
	 * @return object
	 */
	private function host(): object {
		return new class($this->registerMapper, $this->schemaMapper, $this->logger) {
			use SchemaScopedProvider;

			private const LOG_NAME = 'TestProvider';

			/**
			 * Constructor.
			 *
			 * @param RegisterMapper  $registerMapper Register mapper
			 * @param SchemaMapper    $schemaMapper   Schema mapper
			 * @param LoggerInterface $logger         Logger
			 */
			public function __construct(
				private RegisterMapper $registerMapper,
				private SchemaMapper $schemaMapper,
				private LoggerInterface $logger,
			) {
			}

			/**
			 * Register slug.
			 *
			 * @return string
			 */
			public function getRegisterSlug(): string {
				return 'my-register';
			}

			/**
			 * Schema slug.
			 *
			 * @return string
			 */
			public function getSchemaSlug(): string {
				return 'my-schema';
			}

			/**
			 * Expose the flag.
			 *
			 * @return bool
			 */
			public function flag(): bool {
				return $this->isSmartPickerEnabled();
			}

			/**
			 * Expose the title.
			 *
			 * @return string
			 */
			public function title(): string {
				return $this->schemaTitle();
			}

			/**
			 * Expose register resolution.
			 *
			 * @return int|false
			 */
			public function registerId(): int|false {
				return $this->resolveRegisterId();
			}

			/**
			 * Expose schema resolution.
			 *
			 * @return int|false
			 */
			public function schemaId(): int|false {
				return $this->resolveSchemaId();
			}
		};
	}//end host()

	/**
	 * Build a schema entity.
	 *
	 * @param int         $id      Database id
	 * @param string|null $title   Title
	 * @param bool        $enabled Smart Picker flag
	 *
	 * @return Schema
	 */
	private function schema(int $id, ?string $title, bool $enabled): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setTitle($title);
		$schema->setSmartPickerEnabled($enabled);
		return $schema;
	}//end schema()

	/**
	 * The register slug resolves once to its id, without RBAC or multitenancy, and is memoised.
	 *
	 * @return void
	 */
	public function testRegisterSlugResolvesOnceAndIsMemoised(): void {
		$register = new Register();
		$register->setId(42);
		$this->registerMapper->expects($this->once())
			->method('find')
			->with('my-register', false, false)
			->willReturn($register);

		$host = $this->host();
		$this->assertSame(42, $host->registerId());
		$this->assertSame(42, $host->registerId());
	}//end testRegisterSlugResolvesOnceAndIsMemoised()

	/**
	 * An unresolvable register slug yields false, logs the slug, and is not retried.
	 *
	 * @return void
	 */
	public function testUnresolvableRegisterSlugIsFalseLoggedAndMemoised(): void {
		$this->registerMapper->expects($this->once())
			->method('find')
			->willThrowException(new DoesNotExistException('gone'));
		$this->logger->expects($this->once())
			->method('debug')
			->with(
				'[TestProvider] Failed to resolve register slug "{slug}": {error}',
				['slug' => 'my-register', 'error' => 'gone']
			);

		$host = $this->host();
		$this->assertFalse($host->registerId());
		$this->assertFalse($host->registerId());
	}//end testUnresolvableRegisterSlugIsFalseLoggedAndMemoised()

	/**
	 * An unresolvable schema slug yields false, logs the slug, and is not retried.
	 *
	 * @return void
	 */
	public function testUnresolvableSchemaSlugIsFalseLoggedAndMemoised(): void {
		$this->schemaMapper->expects($this->once())
			->method('find')
			->willThrowException(new DoesNotExistException('nope'));
		$this->logger->expects($this->once())
			->method('debug')
			->with(
				'[TestProvider] Failed to resolve schema slug "{slug}": {error}',
				['slug' => 'my-schema', 'error' => 'nope']
			);

		$host = $this->host();
		$this->assertFalse($host->schemaId());
		$this->assertFalse($host->schemaId());
	}//end testUnresolvableSchemaSlugIsFalseLoggedAndMemoised()

	/**
	 * The flag is read from the schema found by its resolved id.
	 *
	 * @return void
	 */
	public function testFlagIsReadLiveFromResolvedSchema(): void {
		$this->schemaMapper->expects($this->exactly(2))
			->method('find')
			->willReturnCallback(
				function (string|int $id, ?array $_extend, bool $_rbac, bool $_multitenancy) {
					$this->assertFalse($_multitenancy);
					$this->assertFalse($_rbac);
					if ($id === 'my-schema') {
						return $this->schema(7, 'T', false);
					}

					$this->assertSame(7, $id);
					return $this->schema(7, 'T', true);
				}
			);

		$this->assertTrue($this->host()->flag());
	}//end testFlagIsReadLiveFromResolvedSchema()

	/**
	 * A schema whose flag is off reports false.
	 *
	 * @return void
	 */
	public function testFlagOffReportsFalse(): void {
		$this->schemaMapper->method('find')->willReturn($this->schema(7, 'T', false));

		$this->assertFalse($this->host()->flag());
	}//end testFlagOffReportsFalse()

	/**
	 * An unresolvable schema slug fails closed without a second lookup.
	 *
	 * @return void
	 */
	public function testFlagFailsClosedForUnresolvableSlug(): void {
		$this->schemaMapper->expects($this->once())
			->method('find')
			->willThrowException(new DoesNotExistException('x'));

		$this->assertFalse($this->host()->flag());
	}//end testFlagFailsClosedForUnresolvableSlug()

	/**
	 * A schema that vanishes between resolution and the live read fails closed.
	 *
	 * @return void
	 */
	public function testFlagFailsClosedWhenLiveReadThrows(): void {
		$this->schemaMapper->method('find')->willReturnCallback(
			function (string|int $id) {
				if ($id === 'my-schema') {
					return $this->schema(7, 'T', true);
				}

				throw new DoesNotExistException('deleted');
			}
		);

		$this->assertFalse($this->host()->flag());
	}//end testFlagFailsClosedWhenLiveReadThrows()

	/**
	 * The title comes from the live schema when it has one.
	 *
	 * @return void
	 */
	public function testTitleIsReadLive(): void {
		$this->schemaMapper->method('find')->willReturnCallback(
			fn (string|int $id) => $id === 'my-schema' ? $this->schema(7, 'Old', false) : $this->schema(7, 'Renamed', false)
		);

		$this->assertSame('Renamed', $this->host()->title());
	}//end testTitleIsReadLive()

	/**
	 * Null and empty titles fall back to the slug.
	 *
	 * @return void
	 */
	public function testMissingTitleFallsBackToSlug(): void {
		foreach ([null, ''] as $title) {
			$this->schemaMapper = $this->createMock(SchemaMapper::class);
			$this->schemaMapper->method('find')->willReturn($this->schema(7, $title, false));
			$this->assertSame('my-schema', $this->host()->title());
		}
	}//end testMissingTitleFallsBackToSlug()

	/**
	 * An unresolvable slug or a failing live read falls back to the slug.
	 *
	 * @return void
	 */
	public function testTitleFallsBackToSlugWhenSchemaCannotBeRead(): void {
		$this->schemaMapper->method('find')->willThrowException(new DoesNotExistException('x'));
		$this->assertSame('my-schema', $this->host()->title());

		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->schemaMapper->method('find')->willReturnCallback(
			function (string|int $id) {
				if ($id === 'my-schema') {
					return $this->schema(7, 'T', false);
				}

				throw new DoesNotExistException('deleted');
			}
		);
		$this->assertSame('my-schema', $this->host()->title());
	}//end testTitleFallsBackToSlugWhenSchemaCannotBeRead()
}//end class
