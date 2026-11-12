<?php

/**
 * Golden test pinning the MappingsController listing window and the fields
 * create and update hand the mapper, plus the logged failures.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use Exception;
use OCA\OpenRegister\Controller\MappingsController;
use OCA\OpenRegister\Db\Mapping;
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Service\MappingService;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\MappingsControllerFactory;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class MappingsControllerWritesGoldenTest extends TestCase {

	private array $calls = [];

	private array $errors = [];

	private function controller(array $params, bool $fails = false): MappingsController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);

		$mapping = $this->createMock(Mapping::class);
		$mapping->method('jsonSerialize')->willReturn(['id' => 1]);

		$mapper = $this->createMock(MappingMapper::class);
		$mapper->method('findAll')->willReturnCallback(
			function (?int $limit = null, ?int $offset = null): array {
				$this->calls[] = ['findAll', $limit, $offset];
				return [];
			}
		);
		$mapper->method('createFromArray')->willReturnCallback(
			function (array $data) use ($mapping, $fails): Mapping {
				$this->calls[] = ['create', $data];
				if ($fails === true) {
					throw new Exception('no db', 7);
				}

				return $mapping;
			}
		);
		$mapper->method('updateFromArray')->willReturnCallback(
			function (int $id, array $data) use ($mapping, $fails): Mapping {
				$this->calls[] = ['update', $id, $data];
				if ($fails === true) {
					throw new Exception('no db', 8);
				}

				return $mapping;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context = []): void {
				unset($context['file'], $context['line']);
				$this->errors[] = [$message, $context];
			}
		);

		return MappingsControllerFactory::create(
			'openregister',
			$request,
			$this->createMock(IAppConfig::class),
			$mapper,
			$this->createMock(MappingService::class),
			$this->createMock(OrganisationService::class),
			$logger
		);
	}

	public function testTheListingWindowFollowsLimitOffsetAndPage(): void {
		$this->controller([])->index();
		$this->controller(['_offset' => '5'])->index();
		$this->controller(['_page' => '3', '_offset' => '5'])->index();
		$this->controller(['_limit' => '10', '_page' => '3', '_offset' => '5'])->index();

		$this->assertSame(
			[['findAll', null, null], ['findAll', null, 5], ['findAll', null, 5], ['findAll', 10, 20]],
			$this->calls
		);
	}

	public function testCreateAndUpdateDropInternalAndImmutableFields(): void {
		$params = ['_route' => 'x', 'id' => 3, 'name' => 'n', 'organisation' => 'o', 'created' => 'c'];
		$this->assertSame(201, $this->controller($params)->create()->getStatus());
		$this->assertSame(200, $this->controller($params)->update(4)->getStatus());

		$this->assertSame(
			[
				['create', ['name' => 'n', 'organisation' => 'o', 'created' => 'c']],
				['update', 4, ['name' => 'n']],
			],
			$this->calls
		);
	}

	public function testFailedWritesAreLoggedAndAnswer500(): void {
		$created = $this->controller(['name' => 'n'], true)->create();
		$updated = $this->controller(['name' => 'n'], true)->update(4);

		$this->assertSame([500, ['error' => 'no db']], [$created->getStatus(), $created->getData()]);
		$this->assertSame([500, ['error' => 'no db']], [$updated->getStatus(), $updated->getData()]);
		$this->assertSame(
			[
				['[MappingsController] Mapping creation failed', ['error_message' => 'no db', 'error_code' => 7]],
				['[MappingsController] Mapping update failed', ['mapping_id' => 4, 'error_message' => 'no db', 'error_code' => 8]],
			],
			$this->errors
		);
	}
}//end class
