<?php

/**
 * Unit tests for AutomationRecordsController, the read endpoint over the
 * automation records (notifications and lifecycle actions).
 *
 * The contract under test: 401 without a session and nothing read, the
 * request's query turned into the filter the service reads with (unknown
 * kinds dropped, the limit defaulted and capped, a negative offset raised to
 * zero), the page the filter settled on echoed back, and a failing read
 * answered 500 without its message.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.
// phpcs:disable PEAR.Commenting.FunctionComment.MissingReturn -- PHPUnit fixtures and tests; the signature IS the contract.
// phpcs:disable Squiz.Commenting.VariableComment.Missing -- typed mock fixtures; the declaration IS the description.

use OCA\OpenRegister\Controller\AutomationRecordsController;
use OCA\OpenRegister\Db\AutomationRecord;
use OCA\OpenRegister\Db\AutomationRecordFilter;
use OCA\OpenRegister\Service\AutomationRecordService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Controller\AutomationRecordsController
 * @uses   \OCA\OpenRegister\Db\AutomationRecordFilter
 */
class AutomationRecordsControllerTest extends TestCase {

	private AutomationRecordService&MockObject $records;

	/** @var array<string, mixed> */
	private array $params = [];

	private ?IUser $user = null;

	protected function setUp(): void {
		parent::setUp();

		$this->records = $this->createMock(AutomationRecordService::class);
		$this->user    = $this->createMock(IUser::class);
	}

	private function controller(): AutomationRecordsController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturnCallback(fn (): array => $this->params);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(fn (): ?IUser => $this->user);

		return new AutomationRecordsController(request: $request, records: $this->records, userSession: $session);
	}

	public function testWithoutASessionNothingIsRead(): void {
		$this->user = null;
		$this->records->expects($this->never())->method('find');

		$response = $this->controller()->index();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'Authentication required'], $response->getData());
	}

	public function testTheQueryBecomesTheFilterAndThePageIsEchoed(): void {
		$this->params = [
			'kind' => ' ' . AutomationRecord::KIND_NOTIFICATION . ' ',
			'key' => ' welcome ',
			'schema' => 'zaak',
			'object' => 'obj-1',
			'since' => '2026-11-01T10:00:00+02:00',
			'until' => 'not a date',
			'limit' => '20',
			'offset' => '40',
		];
		$seen = null;
		$this->records->expects($this->once())->method('find')->willReturnCallback(
			function (AutomationRecordFilter $filter) use (&$seen): array {
				$seen = $filter;
				return [['id' => 1], ['id' => 2]];
			}
		);

		$response = $this->controller()->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['results' => [['id' => 1], ['id' => 2]], 'limit' => 20, 'offset' => 40], $response->getData());
		$this->assertSame(AutomationRecord::KIND_NOTIFICATION, $seen->kind);
		$this->assertSame('welcome', $seen->keyPrefix);
		$this->assertSame('zaak', $seen->schema);
		$this->assertSame('obj-1', $seen->object);
		$this->assertSame('2026-11-01T08:00:00+00:00', $seen->since?->format('c'));
		$this->assertNull($seen->until);
	}

	public function testAnUnknownKindIsDroppedAndThePageIsBounded(): void {
		$this->params = ['kind' => 'everything', 'limit' => '100000', 'offset' => '-5'];
		$this->records->method('find')->willReturnCallback(
			function (AutomationRecordFilter $filter): array {
				$this->assertSame('', $filter->kind);
				return [];
			}
		);

		$response = $this->controller()->index();

		$this->assertSame(['results' => [], 'limit' => AutomationRecordFilter::MAX_LIMIT, 'offset' => 0], $response->getData());
	}

	public function testNoOrAZeroLimitTakesTheDefault(): void {
		$this->records->method('find')->willReturn([]);

		$this->assertSame(AutomationRecordFilter::DEFAULT_LIMIT, $this->controller()->index()->getData()['limit']);

		$this->params = ['limit' => '0'];
		$this->assertSame(AutomationRecordFilter::DEFAULT_LIMIT, $this->controller()->index()->getData()['limit']);
	}

	public function testAFailingReadAnswers500WithoutItsMessage(): void {
		$this->records->method('find')->willThrowException(new RuntimeException('SQLSTATE[42S02] table missing'));

		$response = $this->controller()->index();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'Automation records could not be read'], $response->getData());
	}
}//end class
