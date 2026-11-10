<?php

/**
 * The shared master data branch of MultiTenancyTrait::verifyOrganisationAccess().
 *
 * Pins, for a registers or schemas row of another organisation: a declared
 * consumer is refused with the exception that names the holder and one
 * pseudonymous warning; a caller who is not a consumer meets the generic 403;
 * the same organisation and a row without an integer id never reach the resolver
 * for a write check.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\Tenancy
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\Tenancy;

use Exception;
use OCA\OpenRegister\Db\MultiTenancyTrait;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\SharedMasterDataWriteException;
use OCA\OpenRegister\Service\DeclaredRow;
use OCA\OpenRegister\Service\SharedMasterDataServiceInterface;
use OCP\AppFramework\Db\Entity;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * A schemas-table host for the trait with a stand-in resolver.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\Tenancy
 */
final class SharedMasterDataGuardHost {
	use MultiTenancyTrait;

	/**
	 * The connection a real mapper inherits from QBMapper; unused with a resolver set.
	 *
	 * @var IDBConnection
	 */
	protected IDBConnection $db;

	/**
	 * The logger the trait writes its audit lines to.
	 *
	 * @var LoggerInterface|null
	 */
	public ?LoggerInterface $logger = null;

	/**
	 * Constructor.
	 *
	 * @param string                           $activeOrganisation The caller's organisation.
	 * @param SharedMasterDataServiceInterface $resolver           The resolver.
	 * @param LoggerInterface                  $logger             The logger.
	 */
	public function __construct(
		private readonly string $activeOrganisation,
		SharedMasterDataServiceInterface $resolver,
		LoggerInterface $logger,
	) {
		$this->sharedMasterData = $resolver;
		$this->logger = $logger;
	}//end __construct()

	/**
	 * Stand in for the session lookup.
	 *
	 * @return string|null
	 */
	protected function getActiveOrganisationUuid(): ?string {
		return $this->activeOrganisation;
	}//end getActiveOrganisationUuid()

	/**
	 * Expose the guard.
	 *
	 * @param Entity $entity The entity being written.
	 *
	 * @return void
	 */
	public function verify(Entity $entity): void {
		$this->verifyOrganisationAccess(entity: $entity);
	}//end verify()

	/**
	 * The schemas table, which can carry a shared master data declaration.
	 *
	 * @return string
	 */
	public function getTableName(): string {
		return SharedMasterDataServiceInterface::SCHEMAS;
	}//end getTableName()
}//end class

/**
 * Golden outcomes of the shared master data write refusal.
 *
 * @covers \OCA\OpenRegister\Db\MultiTenancyTrait
 */
class SharedMasterDataWriteGuardGoldenTest extends TestCase {

	/**
	 * Warnings written, as [message, context].
	 *
	 * @var array<int, array{0: string, 1: array}>
	 */
	private array $warnings = [];

	/**
	 * A logger that records its warnings.
	 *
	 * @return LoggerInterface
	 */
	private function logger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->warnings[] = [$message, $context];
			}
		);
		return $logger;
	}//end logger()

	/**
	 * A schema of org-a with the given id.
	 *
	 * @param int|null $id The id, or null for an unsaved row.
	 *
	 * @return Schema
	 */
	private function foreignSchema(?int $id): Schema {
		$schema = new Schema();
		if ($id !== null) {
			$schema->setId($id);
		}

		$schema->setOrganisation('org-a');
		return $schema;
	}//end foreignSchema()

	/**
	 * A declared consumer is refused with the holder-naming exception and one warning; the cache is kept.
	 *
	 * @return void
	 */
	public function testAConsumerIsRefusedNamingTheHolder(): void {
		$resolver = $this->createMock(SharedMasterDataServiceInterface::class);
		$resolver->expects($this->once())->method('assertWritable')
			->with(new DeclaredRow(SharedMasterDataServiceInterface::SCHEMAS, 5), ['org-b'])
			->willThrowException(new SharedMasterDataWriteException('org-a', 'Holder', 'schema'));
		$resolver->expects($this->never())->method('clearCache');

		$host = new SharedMasterDataGuardHost('org-b', $resolver, $this->logger());

		$thrown = null;
		try {
			$host->verify($this->foreignSchema(id: 5));
		} catch (SharedMasterDataWriteException $e) {
			$thrown = $e;
		}

		$this->assertNotNull($thrown);
		$this->assertSame('org-a', $thrown->getHolderUuid());
		$this->assertCount(1, $this->warnings);
		$this->assertSame('[MultiTenancyTrait] Shared master data is read-only to a consumer', $this->warnings[0][0]);
		$context = $this->warnings[0][1];
		$this->assertSame('shared_master_data_write_denied', $context['type']);
		$this->assertSame('org-b', $context['sourceOrganisation']);
		$this->assertSame('org-a', $context['holderOrganisation']);
		$this->assertSame('schema', $context['resourceType']);
		$this->assertSame(5, $context['entityId']);
	}//end testAConsumerIsRefusedNamingTheHolder()

	/**
	 * A caller who is not a consumer is let past the resolver, which drops its cache, and meets the generic 403.
	 *
	 * @return void
	 */
	public function testANonConsumerMeetsTheGenericRefusal(): void {
		$resolver = $this->createMock(SharedMasterDataServiceInterface::class);
		$resolver->expects($this->once())->method('assertWritable')
			->with(new DeclaredRow(SharedMasterDataServiceInterface::SCHEMAS, 7), ['org-b']);
		$resolver->expects($this->once())->method('clearCache');

		$host = new SharedMasterDataGuardHost('org-b', $resolver, $this->logger());

		$code = null;
		try {
			$host->verify($this->foreignSchema(id: 7));
		} catch (Exception $e) {
			$this->assertNotInstanceOf(SharedMasterDataWriteException::class, $e);
			$code = $e->getCode();
		}

		$this->assertSame(Response::HTTP_FORBIDDEN, $code);
		$this->assertCount(1, $this->warnings);
		$this->assertSame('cross_tenant_access_denied', $this->warnings[0][1]['type']);
		$this->assertSame('org-b', $this->warnings[0][1]['sourceOrganisation']);
		$this->assertSame('org-a', $this->warnings[0][1]['targetOrganisation']);
		$this->assertSame(7, $this->warnings[0][1]['entityId']);
	}//end testANonConsumerMeetsTheGenericRefusal()

	/**
	 * A row without an integer id skips the write check and meets the generic 403.
	 *
	 * @return void
	 */
	public function testARowWithoutAnIdSkipsTheWriteCheck(): void {
		$resolver = $this->createMock(SharedMasterDataServiceInterface::class);
		$resolver->expects($this->never())->method('assertWritable');
		$resolver->expects($this->never())->method('clearCache');

		$host = new SharedMasterDataGuardHost('org-b', $resolver, $this->logger());

		$code = null;
		try {
			$host->verify($this->foreignSchema(id: null));
		} catch (Exception $e) {
			$code = $e->getCode();
		}

		$this->assertSame(Response::HTTP_FORBIDDEN, $code);
		$this->assertSame('cross_tenant_access_denied', $this->warnings[0][1]['type']);
	}//end testARowWithoutAnIdSkipsTheWriteCheck()

	/**
	 * The same organisation is allowed without asking the resolver or logging.
	 *
	 * @return void
	 */
	public function testTheSameOrganisationIsAllowed(): void {
		$resolver = $this->createMock(SharedMasterDataServiceInterface::class);
		$resolver->expects($this->never())->method('assertWritable');

		$host = new SharedMasterDataGuardHost('org-a', $resolver, $this->logger());
		$host->verify($this->foreignSchema(id: 5));

		$this->assertSame([], $this->warnings);
	}//end testTheSameOrganisationIsAllowed()
}//end class
