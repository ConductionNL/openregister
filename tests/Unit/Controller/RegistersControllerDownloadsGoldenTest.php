<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use Exception;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\ExportTooLargeException;
use OCA\OpenRegister\Exception\OfficeServerRequiredException;
use OCA\OpenRegister\Kernel\Contracts\GitHubClientInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectImporterInterface;
use OCA\OpenRegister\Service\ConfigurationService;
use OCA\OpenRegister\Service\ExportService;
use OCA\OpenRegister\Service\MigrationPackService;
use OCA\OpenRegister\Service\OasService;
use OCA\OpenRegister\Service\RegisterService;
use OCA\OpenRegister\Service\Registers\RegisterCacheHandler;
use OCA\OpenRegister\Service\Serializer\RegisterSerializer;
use OCA\OpenRegister\Files\Service\UploadService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\RegistersControllerFactory;
use OCP\App\IAppManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\Response;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden test for the downloads of RegistersController: export() and importTemplate().
 *
 * Records status, type, file name, content and the collaborator calls per case and
 * compares them with fixtures/registers-downloads-golden.json. The moment in a file
 * name is masked; xlsx content is recorded as its zip signature. Set UPDATE_GOLDEN=1
 * to rewrite the fixture.
 */
class RegistersControllerDownloadsGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/registers-downloads-golden.json';

	/** @var list<array<int, mixed>> */
	private array $calls = [];

	/**
	 * Cases: request parameters, slugs, and what the exporter does.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$named = ['slug' => 'reg', 'schemaSlug' => 'sch'];
		$bare  = ['slug' => null, 'schemaSlug' => null];
		$out   = [];
		foreach (['named' => $named, 'bare' => $bare] as $label => $slugs) {
			$out["excel $label"]         = [$slugs + ['params' => ['format' => 'excel']]];
			$out["csv $label"]           = [$slugs + ['params' => ['format' => 'csv', 'schema' => '5']]];
			$out["pdf $label"]           = [$slugs + ['params' => ['format' => 'pdf', 'schema' => '5']]];
			$out["configuration $label"] = [$slugs + ['params' => []]];
			$out["template csv $label"]  = [$slugs + ['template' => true, 'params' => ['format' => 'CSV']]];
			$out["template xlsx $label"] = [$slugs + ['template' => true, 'params' => []]];
		}

		return $out + [
			'unknown format is a configuration' => [$named + ['params' => ['format' => 'yaml', 'includeObjects' => 'yes']]],
			'configuration without objects' => [$named + ['params' => ['format' => 'configuration', 'includeObjects' => '0']]],
			'csv without schema' => [$named + ['params' => ['format' => 'csv']]],
			'pdf empty schema' => [$named + ['params' => ['format' => 'pdf', 'schema' => '']]],
			'pdf too large' => [$named + ['params' => ['format' => 'pdf', 'schema' => '5'], 'pdf' => 'large']],
			'pdf needs office' => [$named + ['params' => ['format' => 'pdf', 'schema' => '5'], 'pdf' => 'office']],
			'pdf fails' => [$named + ['params' => ['format' => 'pdf', 'schema' => '5'], 'pdf' => 'fails']],
			'register missing' => [$named + ['params' => ['format' => 'excel'], 'missing' => 'register']],
			'schema missing' => [$named + ['params' => ['format' => 'csv', 'schema' => '5'], 'missing' => 'schema']],
			'template bad format' => [$named + ['template' => true, 'params' => ['format' => 'pdf']]],
			'template register missing' => [$named + ['template' => true, 'params' => [], 'missing' => 'register']],
			'template schema missing' => [$named + ['template' => true, 'params' => ['format' => 'csv'], 'missing' => 'schema']],
			'template fails' => [$named + ['template' => true, 'params' => ['format' => 'csv'], 'templateFails' => true]],
		];
	}//end cases()

	/**
	 * A download answers as recorded.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testDownloadMatchesTheRecording(array $case): void {
		$this->calls = [];
		$controller  = RegistersControllerFactory::create(...$this->collaborators(case: $case));
		$response    = match ($case['template'] ?? false) {
			true  => $controller->importTemplate('reg', 'sch'),
			false => $controller->export(1),
		};
		$actual      = ['response' => $this->describe(response: $response), 'calls' => $this->calls];

		$all = is_file(self::FIXTURE) === true ? json_decode((string) file_get_contents(self::FIXTURE), true) : [];
		if (getenv('UPDATE_GOLDEN') === '1') {
			$all[$this->dataName()] = $actual;
			ksort($all);
			file_put_contents(self::FIXTURE, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertSame($all[$this->dataName()], json_decode((string) json_encode($actual), true));
	}//end testDownloadMatchesTheRecording()

	/**
	 * What a response says, with the moment in its file name masked.
	 *
	 * @param Response $response The response.
	 *
	 * @return array<string, mixed>
	 */
	private function describe(Response $response): array {
		$headers = $response->getHeaders();
		if ($response instanceof DataDownloadResponse === false) {
			return ['status' => $response->getStatus(), 'data' => $response->getData()];
		}

		$content = $response->render();
		$content = str_starts_with($content, 'PK') === true ? 'xlsx' : $content;
		return [
			'status'  => $response->getStatus(),
			'type'    => $headers['Content-Type'],
			'name'    => preg_replace('/\d{4}-\d{2}-\d{2}_\d{6}/', 'MOMENT', $headers['Content-Disposition']),
			'content' => $content,
		];
	}//end describe()

	/**
	 * The arguments for RegistersControllerFactory::create().
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return array<int, mixed>
	 */
	private function collaborators(array $case): array {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->createConfiguredMock(IUser::class, ['getUID' => 'alice']));
		[$registers, $mapper] = $this->registers(case: $case);
		return [
			'openregister',
			$this->request(params: $case['params']),
			$registers,
			$this->createMock(MagicMapper::class),
			$this->createMock(UploadService::class),
			$this->createMock(LoggerInterface::class),
			$session,
			$this->configuration(),
			$this->createMock(AuditTrailMapper::class),
			$this->exporter(case: $case),
			$this->createMock(ObjectImporterInterface::class),
			$this->schemas(case: $case),
			$mapper,
			$this->createMock(GitHubClientInterface::class),
			$this->createMock(IAppManager::class),
			$this->createMock(OasService::class),
			$this->createMock(ContainerInterface::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(RegisterCacheHandler::class),
			new RegisterSerializer($this->createMock(SchemaMapper::class), $this->createMock(LoggerInterface::class)),
			$this->createMock(MigrationPackService::class),
		];
	}//end collaborators()

	/**
	 * A request answering the case's parameters.
	 *
	 * @param array<string, mixed> $params The parameters.
	 *
	 * @return IRequest
	 */
	private function request(array $params): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => $params[$key] ?? $default
		);
		return $request;
	}//end request()

	/**
	 * The register service and mapper, one per lookup path.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return array{0: RegisterService, 1: RegisterMapper}
	 */
	private function registers(array $case): array {
		$find = function (mixed $id) use ($case): Register {
			$this->calls[] = ['findRegister', $id];
			if (($case['missing'] ?? null) === 'register') {
				throw new DoesNotExistException('no register');
			}

			$register = new Register();
			$register->setSlug($case['slug']);
			return $register;
		};
		$service = $this->createMock(RegisterService::class);
		$service->method('find')->willReturnCallback($find);
		$mapper = $this->createMock(RegisterMapper::class);
		$mapper->method('find')->willReturnCallback($find);
		return [$service, $mapper];
	}//end registers()

	/**
	 * The schema mapper.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return SchemaMapper
	 */
	private function schemas(array $case): SchemaMapper {
		$mapper = $this->createMock(SchemaMapper::class);
		$mapper->method('find')->willReturnCallback(
			function (mixed $id) use ($case): Schema {
				$this->calls[] = ['findSchema', $id];
				if (($case['missing'] ?? null) === 'schema') {
					throw new DoesNotExistException('no schema');
				}

				$schema = new Schema();
				$schema->setSlug($case['schemaSlug']);
				return $schema;
			}
		);
		return $mapper;
	}//end schemas()

	/**
	 * The configuration service, noting what it is asked for.
	 *
	 * @return ConfigurationService
	 */
	private function configuration(): ConfigurationService {
		$service = $this->createMock(ConfigurationService::class);
		$service->method('exportConfig')->willReturnCallback(
			function (mixed $input, bool $includeObjects): array {
				$this->calls[] = ['exportConfig', $input::class, $includeObjects];
				return ['openapi' => '3.0.0', 'url' => 'a/b'];
			}
		);
		return $service;
	}//end configuration()

	/**
	 * The exporter, noting the user and schema of every call.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return ExportService
	 */
	private function exporter(array $case): ExportService {
		$export = $this->createMock(ExportService::class);
		$note   = function (string $method, array $args): void {
			$user          = array_pop($args);
			$this->calls[] = [$method, $args[1]?->getSlug(), $args[2] ?? 'no filters', $user?->getUID()];
		};
		$export->method('exportToExcel')->willReturnCallback(
			function (...$args) use ($note): Spreadsheet {
				$note('excel', $args);
				return new Spreadsheet();
			}
		);
		$export->method('exportToCsv')->willReturnCallback(
			function (...$args) use ($note): string {
				$note('csv', $args);
				return "a,b\n";
			}
		);
		$export->method('exportToPdf')->willReturnCallback(
			function (...$args) use ($note, $case): string {
				$note('pdf', $args);
				return match ($case['pdf'] ?? null) {
					'large' => throw new ExportTooLargeException(6000, 5000),
					'office' => throw new OfficeServerRequiredException(),
					'fails' => throw new RuntimeException('pdf broke'),
					default => '%PDF',
				};
			}
		);
		$export->method('buildTemplateCsv')->willReturnCallback(
			function (...$args) use ($note, $case): string {
				$note('templateCsv', $args);
				if (($case['templateFails'] ?? false) === true) {
					throw new Exception('template broke');
				}

				return "a\n";
			}
		);
		$export->method('buildTemplateSpreadsheet')->willReturnCallback(
			function (...$args) use ($note): Spreadsheet {
				$note('templateXlsx', $args);
				return new Spreadsheet();
			}
		);
		return $export;
	}//end exporter()
}//end class
