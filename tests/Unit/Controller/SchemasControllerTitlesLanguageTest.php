<?php

/**
 * GET /api/schemas/{id} answers each property's title in the language the read names.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\SchemasController;
use OCA\OpenRegister\Tests\Unit\Controller\Support\SchemasControllerFactory;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\Schema\SchemaVersioningService;
use OCA\OpenRegister\Service\Schemas\FacetResultCacheClearer;
use OCA\OpenRegister\Service\Schemas\SchemaCacheHandler;
use OCA\OpenRegister\Service\SchemaService;
use OCA\OpenRegister\Files\Service\UploadService;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SchemasControllerTitlesLanguageTest extends TestCase {

	private IRequest $request;

	private SchemaMapper $schemaMapper;

	private RegisterMapper $registerMapper;

	private SchemasController $controller;

	protected function setUp(): void {
		$this->request        = $this->createMock(IRequest::class);
		$this->schemaMapper   = $this->createMock(SchemaMapper::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);

		$userSession = $this->createMock(\OCP\IUserSession::class);
		$user        = $this->createMock(\OCP\IUser::class);
		$user->method('getUID')->willReturn('admin');
		$userSession->method('getUser')->willReturn($user);

		$groupManager = $this->createMock(\OCP\IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn(['admin']);
		$groupManager->method('isAdmin')->willReturn(true);

		$container = $this->createMock(\Psr\Container\ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function ($id) use ($userSession, $groupManager) {
				if ($id === \OCP\IUserSession::class) {
					return $userSession;
				}

				if ($id === \OCP\IGroupManager::class) {
					return $groupManager;
				}

				return null;
			}
		);

		$this->controller = SchemasControllerFactory::create(
			'openregister',
			$this->request,
			$this->createMock(IAppConfig::class),
			$this->schemaMapper,
			$this->registerMapper,
			$this->createMock(MagicMapper::class),
			$this->createMock(UploadService::class),
			$this->createMock(AuditTrailMapper::class),
			$this->createMock(OrganisationService::class),
			$this->createMock(SchemaCacheHandler::class),
			$this->createMock(FacetResultCacheClearer::class),
			$this->createMock(SchemaService::class),
			$this->createMock(LoggerInterface::class),
			$container,
			$this->createMock(SchemaVersioningService::class)
		);
	}//end setUp()

	/**
	 * The stored properties of schema `klant`.
	 *
	 * @return array
	 */
	private static function properties(): array {
		return [
			'contractEnd' => ['type' => 'string', 'title' => 'Contract end', 'titles' => ['nl' => 'Einddatum contract', 'en' => 'Contract end date']],
			'name' => ['type' => 'string', 'title' => 'Name'],
		];
	}//end properties()

	/**
	 * Read schema `klant` with the given `_lang` and `Accept-Language`.
	 *
	 * @param string|null $lang   The `_lang` value, or null for absent.
	 * @param string      $header The `Accept-Language` header, '' for absent.
	 *
	 * @return array The response data.
	 */
	private function read(?string $lang, string $header): array {
		$this->request->method('getParam')->willReturnCallback(
			fn (string $key, $default = null) => ($key === '_lang' ? $lang : $default)
		);
		$this->request->method('getHeader')->willReturnCallback(
			fn (string $name) => (strtolower($name) === 'accept-language' ? $header : '')
		);

		$schema = new Schema();
		$schema->setId(5);
		$schema->setSlug('klant');
		$schema->setTitle('Klant');
		$schema->setProperties(self::properties());
		$this->schemaMapper->method('find')->willReturn($schema);
		$this->schemaMapper->method('findExtendedBy')->willReturn([]);

		$response = $this->controller->show('klant');
		$this->assertSame(200, $response->getStatus());

		return $response->getData();
	}//end read()

	/**
	 * Reads and the title of `contractEnd` they answer.
	 *
	 * @return array<string, array{0: string|null, 1: string, 2: string}>
	 */
	public static function reads(): array {
		return [
			'_lang=nl' => ['nl', '', 'Einddatum contract'],
			'_lang=en' => ['en', 'nl', 'Contract end date'],
			'_lang=nl-BE falls back to nl' => ['nl-BE', '', 'Einddatum contract'],
			'_lang=de keeps the stored title' => ['de', '', 'Contract end'],
			'Accept-Language nl-BE' => [null, 'nl-BE,en;q=0.5', 'Einddatum contract'],
		];
	}//end reads()

	#[DataProvider('reads')]
	public function testAReadInALanguageAnswersThatName(?string $lang, string $header, string $title): void {
		$data = $this->read(lang: $lang, header: $header);

		$this->assertSame($title, $data['properties']['contractEnd']['title']);
		$this->assertSame(['nl' => 'Einddatum contract', 'en' => 'Contract end date'], $data['properties']['contractEnd']['titles']);
		$this->assertSame('Name', $data['properties']['name']['title']);
	}//end testAReadInALanguageAnswersThatName()

	public function testAReadWithoutALanguageReturnsTheStoredProperties(): void {
		$data = $this->read(lang: null, header: '');

		$this->assertSame(self::properties(), $data['properties']);
	}//end testAReadWithoutALanguageReturnsTheStoredProperties()
}//end class
