<?php

/**
 * The searchable schemas described the way a search scope names them.
 *
 * 🔑 The register slug comes from the register that OWNS the schema, and the
 * apps from the deep-link claims on the (register, schema) PAIR: a schema slug
 * alone is not unique across registers.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Search
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Search;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaCatalogInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Dto\DeepLinkRegistration;
use OCA\OpenRegister\Search\SearchScopeCatalog;
use OCA\OpenRegister\Service\DeepLinkRegistryServiceInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Search\SearchScopeCatalog
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Dto\DeepLinkRegistration
 *
 * @spec openspec/changes/content-search-index/specs/unified-search-provider/spec.md
 */
final class SearchScopeCatalogTest extends TestCase {

	private SchemaMapperInterface&MockObject $schemas;
	private RegisterMapperInterface&MockObject $registers;
	private SchemaCatalogInterface&MockObject $catalog;
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		$this->schemas   = $this->createMock(SchemaMapperInterface::class);
		$this->registers = $this->createMock(RegisterMapperInterface::class);
		$this->catalog   = $this->createMock(SchemaCatalogInterface::class);
		$this->catalog->method('schemas')->willReturn($this->schemas);
		$this->catalog->method('registers')->willReturn($this->registers);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	/**
	 * A schema entity with an id, slug and title.
	 *
	 * @param int         $id    The id.
	 * @param string      $slug  The slug.
	 * @param string|null $title The title.
	 *
	 * @return Schema The schema.
	 */
	private static function schema(int $id, string $slug, ?string $title = null): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setSlug($slug);
		$schema->setTitle($title);

		return $schema;
	}

	/**
	 * A register entity owning the given schema ids.
	 *
	 * @param string     $slug    The slug.
	 * @param string     $title   The title.
	 * @param array<int> $schemas The schema ids it owns.
	 *
	 * @return Register The register.
	 */
	private static function register(string $slug, string $title, array $schemas): Register {
		$register = new Register();
		$register->setSlug($slug);
		$register->setTitle($title);
		$register->setSchemas($schemas);

		return $register;
	}

	/**
	 * Only searchable schemas are described, each with its owning register and claiming apps.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/content-search-index/specs/unified-search-provider/spec.md#requirement-the-provider-accepts-scopes-and-advertises-them
	 */
	public function testDescribesSearchableSchemasWithOwnerAndClaimingApps(): void {
		$case    = self::schema(1, 'case', 'Case');
		$contact = self::schema(2, 'contact', null);
		$hidden  = self::schema(3, 'secret', 'Secret');
		$this->schemas->method('findSearchableIds')->willReturn([1, 2]);
		$this->schemas->method('findAll')->willReturn([$case, $contact, $hidden]);
		$this->registers->method('findAll')->willReturn(
			[
				self::register('zaken', 'Zaken', [1, 3]),
				self::register('crm', 'CRM', [2]),
			]
		);

		$deepLink = $this->createMock(DeepLinkRegistryServiceInterface::class);
		$deepLink->method('registrations')->willReturn(
			[
				new DeepLinkRegistration(appId: 'DossIQ', registerSlug: 'Zaken', schemaSlug: 'case', urlTemplate: '/x'),
				new DeepLinkRegistration(appId: 'procest', registerSlug: 'zaken', schemaSlug: 'case', urlTemplate: '/y'),
				// Same schema slug in ANOTHER register: claims nothing here.
				new DeepLinkRegistration(appId: 'pipelinq', registerSlug: 'other', schemaSlug: 'contact', urlTemplate: '/z'),
			]
		);

		$described = (new SearchScopeCatalog(catalog: $this->catalog, logger: $this->logger, deepLink: $deepLink))->describe();

		self::assertCount(2, $described, 'the non-searchable schema is not described');
		self::assertSame(1, $described[0]['id']);
		self::assertSame('case', $described[0]['slug']);
		self::assertSame('Case', $described[0]['title']);
		self::assertSame('zaken', $described[0]['register']);
		self::assertSame('Zaken', $described[0]['registerTitle']);
		self::assertSame(['dossiq', 'procest'], $described[0]['apps'], 'claims match the pair case-insensitively');
		self::assertSame($case, $described[0]['schema']);

		self::assertSame('contact', $described[1]['title'], 'a schema without a title is labelled by its slug');
		self::assertSame('crm', $described[1]['register']);
		self::assertSame([], $described[1]['apps'], 'a claim on the same slug in another register is not this schema');
	}

	/**
	 * Without a deep-link registry no app claims anything, and the rest is still described.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/content-search-index/specs/unified-search-provider/spec.md#requirement-the-provider-accepts-scopes-and-advertises-them
	 */
	public function testWithoutARegistryNoAppClaimsAnything(): void {
		$this->schemas->method('findSearchableIds')->willReturn([5]);
		$this->schemas->method('findAll')->willReturn([self::schema(5, 'note', 'Note')]);
		$this->registers->method('findAll')->willReturn([]);

		$described = (new SearchScopeCatalog(catalog: $this->catalog, logger: $this->logger))->describe();

		self::assertCount(1, $described);
		self::assertSame('', $described[0]['register'], 'an unowned schema has no register slug');
		self::assertSame([], $described[0]['apps']);
	}

	/**
	 * A registry that throws claims nothing and is reported, rather than failing the description.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/content-search-index/specs/unified-search-provider/spec.md#requirement-the-provider-accepts-scopes-and-advertises-them
	 */
	public function testARegistryThatThrowsClaimsNothingAndIsReported(): void {
		$this->schemas->method('findSearchableIds')->willReturn([5]);
		$this->schemas->method('findAll')->willReturn([self::schema(5, 'note', 'Note')]);
		$this->registers->method('findAll')->willReturn([self::register('crm', 'CRM', [5])]);
		$deepLink = $this->createMock(DeepLinkRegistryServiceInterface::class);
		$deepLink->method('registrations')->willThrowException(new \RuntimeException('boom'));

		$this->logger->expects(self::once())->method('error')
			->with(self::stringContains('Failed to read the deep-link registry'), self::anything());

		$described = (new SearchScopeCatalog(catalog: $this->catalog, logger: $this->logger, deepLink: $deepLink))->describe();

		self::assertCount(1, $described);
		self::assertSame('crm', $described[0]['register']);
		self::assertSame([], $described[0]['apps']);
	}

	/**
	 * A catalog that throws describes nothing and says so at ERROR level.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/content-search-index/specs/unified-search-provider/spec.md#requirement-the-provider-accepts-scopes-and-advertises-them
	 */
	public function testACatalogThatThrowsDescribesNothing(): void {
		$this->schemas->method('findSearchableIds')->willThrowException(new \RuntimeException('db down'));

		$this->logger->expects(self::once())->method('error')
			->with(self::stringContains('Failed to describe searchable schemas'), self::anything());

		self::assertSame([], (new SearchScopeCatalog(catalog: $this->catalog, logger: $this->logger))->describe());
	}
}
