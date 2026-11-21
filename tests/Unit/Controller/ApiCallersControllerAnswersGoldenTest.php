<?php

/**
 * Golden test pinning the exact answers of ApiCallersController: the caller
 * record listing (period, version narrowing, truncation, read failure) and the
 * declaration write (missing list, refusal, store failure, stored answer).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\ApiCallersController;
use OCA\OpenRegister\Controller\Support\ControllerSession;
use OCA\OpenRegister\Db\ApiCall\ApiCallPeriod;
use OCA\OpenRegister\Db\ApiCallRecord;
use OCA\OpenRegister\Db\ApiCallRecordMapper;
use OCA\OpenRegister\Service\ApiVersion\ApiVersionCatalogue;
use OCA\OpenRegister\Service\ApiVersion\ApiVersionCatalogueInterface;
use OCA\OpenRegister\Tests\Unit\Controller\Support\ApiCallersControllerFactory;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class ApiCallersControllerAnswersGoldenTest extends TestCase {

	private array $periods = [];

	private array $stored = [];

	private array $errors = [];

	private function controller(array $params, ?array $rows, bool $storeFails = false): ApiCallersController {
		$mapper = $this->createMock(ApiCallRecordMapper::class);
		$mapper->method('findInPeriod')->willReturnCallback(
			function (ApiCallPeriod $period) use ($rows): array {
				$this->periods[] = [$period->from->format('c'), $period->to->format('c'), $period->apiVersion, $period->limit];
				if ($rows === null) {
					throw new RuntimeException('db down');
				}

				return $rows;
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value) use ($storeFails): bool {
				if ($storeFails === true) {
					throw new RuntimeException('config down');
				}

				$this->stored[] = [$app, $key, $value];
				return true;
			}
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('beheerder');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(true);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->errors[] = [$message, $context['exception']->getMessage()];
			}
		);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $name, mixed $default = null): mixed => ($params[$name] ?? $default)
		);

		return ApiCallersControllerFactory::create(
			'openregister',
			$request,
			$mapper,
			new ApiVersionCatalogue($appConfig, $this->createMock(LoggerInterface::class)),
			$appConfig,
			new ControllerSession($session, $groups, $logger),
		);
	}

	private static function record(string $principal): ApiCallRecord {
		$record = new ApiCallRecord();
		$record->setPrincipal($principal);
		$record->setRoute('/apps/openregister/api/objects');
		$record->setMethod('GET');
		$record->setApiVersion('1');
		$record->setCallCount(2);

		return $record;
	}

	public function testTheListingAnswersThePeriodTheCountAndTheCallers(): void {
		$rows = [self::record('a'), self::record('b')];
		$response = $this->controller(['from' => '2026-08-01T00:00:00+00:00', 'to' => '2026-09-01T00:00:00+00:00', 'version' => ' 2 '], $rows)->index();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(
			[
				'from' => '2026-08-01T00:00:00+00:00',
				'to' => '2026-09-01T00:00:00+00:00',
				'total' => 2,
				'truncated' => false,
				'callers' => [$rows[0]->jsonSerialize(), $rows[1]->jsonSerialize()],
			],
			$response->getData()
		);
		$this->assertSame([['2026-08-01T00:00:00+00:00', '2026-09-01T00:00:00+00:00', '2', 500]], $this->periods);
	}

	public function testABlankVersionIsNoNarrowingAndAFullPageIsTruncated(): void {
		$rows = array_fill(0, 500, self::record('a'));
		$data = $this->controller(['from' => '2026-08-01T00:00:00+00:00', 'to' => '2026-09-01T00:00:00+00:00', 'version' => '  '], $rows)->index()->getData();

		$this->assertSame(500, $data['total']);
		$this->assertTrue($data['truncated']);
		$this->assertNull($this->periods[0][2]);
	}

	public function testTheDefaultPeriodIsTheLastThirtyDays(): void {
		$this->controller([], [])->index();

		[$from, $to] = $this->periods[0];
		$this->assertSame(30 * 86400, (new \DateTime($to))->getTimestamp() - (new \DateTime($from))->getTimestamp());
	}

	public function testAFailedReadIsLoggedAndAnswers500(): void {
		$response = $this->controller([], null)->index();

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['error' => 'The caller record could not be read.'], $response->getData());
		$this->assertSame([['OpenRegister: could not read the API caller record.', 'db down']], $this->errors);
	}

	public function testTheWriteRefusalsAnswerExactBodies(): void {
		$missing = $this->controller(['versions' => 'nope'], [])->writeDeclaration();
		$this->assertSame(400, $missing->getStatus());
		$this->assertSame(['error' => 'Send a "versions" list of version declarations.'], $missing->getData());

		$broken = $this->controller(['versions' => [['id' => '1', 'status' => 'deprecated']]], [])->writeDeclaration();
		$this->assertSame(400, $broken->getStatus());
		$this->assertSame('This declaration cannot be served, so it was not stored.', $broken->getData()['error']);
		$this->assertSame(['error', 'rejections'], array_keys($broken->getData()));
		$this->assertSame([], $this->stored);
	}

	public function testAStoredDeclarationAnswersTheListAsStored(): void {
		$versions = [3 => ['id' => '1', 'status' => 'supported']];
		$response = $this->controller(['versions' => $versions], [])->writeDeclaration();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['versions' => [['id' => '1', 'status' => 'supported']], 'stored' => true], $response->getData());
		$this->assertSame(
			[['openregister', ApiVersionCatalogueInterface::CONFIG_KEY, '[{"id":"1","status":"supported"}]']],
			$this->stored
		);
	}

	public function testAFailedStoreIsLoggedAndAnswers500(): void {
		$response = $this->controller(['versions' => [['id' => '1', 'status' => 'supported']]], [], true)->writeDeclaration();

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['error' => 'The declaration could not be stored.'], $response->getData());
		$this->assertSame([['OpenRegister: could not store the API version declaration.', 'config down']], $this->errors);
	}
}//end class
