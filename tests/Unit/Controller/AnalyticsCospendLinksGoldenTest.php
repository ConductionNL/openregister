<?php

/**
 * Golden test pinning the answers of AnalyticsLinksController and
 * CospendLinksController: availability, required params, created links,
 * unlinking, picker listings and the mapping of service failures.
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

use Exception;
use OCA\OpenRegister\Controller\AnalyticsLinksController;
use OCA\OpenRegister\Controller\CospendLinksController;
use OCA\OpenRegister\Controller\Support\AnalyticsLinksControllerServices;
use OCA\OpenRegister\Controller\Support\CospendLinksControllerServices;
use OCA\OpenRegister\Db\AnalyticsLink;
use OCA\OpenRegister\Db\CospendLink;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\AnalyticsLinkServiceInterface;
use OCA\OpenRegister\Service\AnalyticsReportDraft;
use OCA\OpenRegister\Service\CospendLinkServiceInterface;
use OCA\OpenRegister\Service\CospendProjectDraft;
use OCA\OpenRegister\Service\LinkTarget;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

class AnalyticsCospendLinksGoldenTest extends TestCase {

	private array $params = [];

	private array $calls = [];

	private function request(): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			fn (string $key, mixed $default = null) => array_key_exists($key, $this->params) === true ? $this->params[$key] : $default
		);
		return $request;
	}

	private function objects(): ObjectService {
		$object = new ObjectEntity();
		$object->setUuid('obj-uuid');
		$object->setRegister(3);
		$object->setSchema(4);
		$objects = $this->createMock(ObjectService::class);
		$objects->method('setSchema')->willReturnSelf();
		$objects->method('setRegister')->willReturnSelf();
		$objects->method('setObject')->willReturnSelf();
		$objects->method('getObject')->willReturn($object);
		return $objects;
	}

	private static function target(LinkTarget $target): string {
		return $target->objectUuid . '/' . $target->registerId . '/' . $target->schemaId();
	}

	private function analyticsLink(): AnalyticsLink {
		$link = $this->createMock(AnalyticsLink::class);
		$link->method('jsonSerialize')->willReturn(['link' => 'analytics']);
		return $link;
	}

	private function cospendLink(): CospendLink {
		$link = $this->createMock(CospendLink::class);
		$link->method('jsonSerialize')->willReturn(['link' => 'cospend']);
		return $link;
	}

	private function analytics(bool $available = true, ?Exception $failure = null): AnalyticsLinksController {
		$service = $this->createMock(AnalyticsLinkServiceInterface::class);
		$service->method('isAnalyticsAvailable')->willReturn($available);
		$service->method('linkReport')->willReturnCallback(
			function (LinkTarget $target, int $reportId) use ($failure): AnalyticsLink {
				$this->calls[] = ['linkReport', self::target($target), $reportId];
				if ($failure !== null) {
					throw $failure;
				}

				return $this->analyticsLink();
			}
		);
		$service->method('createAndLinkReport')->willReturnCallback(
			function (LinkTarget $target, AnalyticsReportDraft $draft): AnalyticsLink {
				$this->calls[] = ['createAndLinkReport', self::target($target), $draft->name, $draft->type];
				return $this->analyticsLink();
			}
		);
		$service->method('unlinkReport')->willReturnCallback(
			function (string $uuid, int $reportId) use ($failure): void {
				$this->calls[] = ['unlinkReport', $uuid, $reportId];
				if ($failure !== null) {
					throw $failure;
				}
			}
		);
		$service->method('getAvailableReports')->willReturnCallback(
			function (?string $search): array {
				$this->calls[] = ['getAvailableReports', $search];
				return [['id' => 1], ['id' => 2]];
			}
		);

		return new AnalyticsLinksController(
			$this->request(),
			new AnalyticsLinksControllerServices(analyticsLinkService: $service, objectService: $this->objects())
		);
	}

	private function cospend(bool $available = true, ?Exception $failure = null): CospendLinksController {
		$service = $this->createMock(CospendLinkServiceInterface::class);
		$service->method('isCospendAvailable')->willReturn($available);
		$service->method('linkProject')->willReturnCallback(
			function (LinkTarget $target, string $projectId) use ($failure): CospendLink {
				$this->calls[] = ['linkProject', self::target($target), $projectId];
				if ($failure !== null) {
					throw $failure;
				}

				return $this->cospendLink();
			}
		);
		$service->method('linkBill')->willReturnCallback(
			function (LinkTarget $target, string $projectId, int $billId): CospendLink {
				$this->calls[] = ['linkBill', self::target($target), $projectId, $billId];
				return $this->cospendLink();
			}
		);
		$service->method('createAndLinkProject')->willReturnCallback(
			function (LinkTarget $target, CospendProjectDraft $draft): CospendLink {
				$this->calls[] = ['createAndLinkProject', self::target($target), $draft->name, $draft->currency];
				return $this->cospendLink();
			}
		);
		$service->method('unlink')->willReturnCallback(
			function (string $uuid, int $entryId) use ($failure): void {
				$this->calls[] = ['unlink', $uuid, $entryId];
				if ($failure !== null) {
					throw $failure;
				}
			}
		);
		$service->method('getAvailableProjects')->willReturnCallback(
			function (?string $search): array {
				$this->calls[] = ['getAvailableProjects', $search];
				return [['id' => 'p1']];
			}
		);

		return new CospendLinksController(
			$this->request(),
			new CospendLinksControllerServices(cospendLinkService: $service, objectService: $this->objects())
		);
	}

	private static function answer(\OCP\AppFramework\Http\JSONResponse $response): array {
		return [$response->getStatus(), $response->getData()];
	}

	public function testUnavailableAppsAnswer501OnEveryAct(): void {
		$analytics = $this->analytics(false);
		$cospend = $this->cospend(false);
		$analyticsOff = [501, ['error' => 'NC Analytics app is not installed', 'code' => 'APP_NOT_AVAILABLE']];
		$cospendOff = [501, ['error' => 'NC Cospend app is not installed', 'code' => 'APP_NOT_AVAILABLE']];

		$this->assertSame($analyticsOff, self::answer($analytics->index('r', 's', 'o')));
		$this->assertSame($analyticsOff, self::answer($analytics->link('r', 's', 'o')));
		$this->assertSame($analyticsOff, self::answer($analytics->createAndLink('r', 's', 'o')));
		$this->assertSame($analyticsOff, self::answer($analytics->destroy('r', 's', 'o', '5')));
		$this->assertSame($analyticsOff, self::answer($analytics->available()));
		$this->assertSame($cospendOff, self::answer($cospend->index('r', 's', 'o')));
		$this->assertSame($cospendOff, self::answer($cospend->link('r', 's', 'o')));
		$this->assertSame($cospendOff, self::answer($cospend->createAndLink('r', 's', 'o')));
		$this->assertSame($cospendOff, self::answer($cospend->destroy('r', 's', 'o', '5')));
		$this->assertSame($cospendOff, self::answer($cospend->available()));
		$this->assertSame([], $this->calls);
	}

	public function testAnalyticsLinkNeedsAReportIdAndAnswersTheCreatedLink(): void {
		$this->assertSame([400, ['error' => 'reportId is required']], self::answer($this->analytics()->link('r', 's', 'o')));
		$this->params = ['reportId' => '0'];
		$this->assertSame([400, ['error' => 'reportId is required']], self::answer($this->analytics()->link('r', 's', 'o')));
		$this->assertSame([], $this->calls);

		$this->params = ['reportId' => '12'];
		$this->assertSame([201, ['link' => 'analytics']], self::answer($this->analytics()->link('r', 's', 'o')));
		$this->assertSame([['linkReport', 'obj-uuid/3/4', 12]], $this->calls);
	}

	public function testAnalyticsCreateAndLinkNeedsANameAndPassesTheType(): void {
		$this->params = ['name' => '  '];
		$this->assertSame([400, ['error' => 'name is required']], self::answer($this->analytics()->createAndLink('r', 's', 'o')));

		$this->params = ['name' => 'Report', 'type' => ''];
		$this->assertSame([201, ['link' => 'analytics']], self::answer($this->analytics()->createAndLink('r', 's', 'o')));
		$this->params = ['name' => 'Report', 'type' => '3'];
		$this->assertSame([201, ['link' => 'analytics']], self::answer($this->analytics()->createAndLink('r', 's', 'o')));
		$this->params = ['name' => 'Report'];
		$this->analytics()->createAndLink('r', 's', 'o');

		$this->assertSame(
			[
				['createAndLinkReport', 'obj-uuid/3/4', 'Report', null],
				['createAndLinkReport', 'obj-uuid/3/4', 'Report', 3],
				['createAndLinkReport', 'obj-uuid/3/4', 'Report', null],
			],
			$this->calls
		);
	}

	public function testCospendLinkChoosesProjectOrBill(): void {
		$this->assertSame([400, ['error' => 'projectId is required']], self::answer($this->cospend()->link('r', 's', 'o')));
		$this->params = ['projectId' => ' '];
		$this->assertSame([400, ['error' => 'projectId is required']], self::answer($this->cospend()->link('r', 's', 'o')));
		$this->assertSame([], $this->calls);

		$this->params = ['projectId' => 'p1'];
		$this->assertSame([201, ['link' => 'cospend']], self::answer($this->cospend()->link('r', 's', 'o')));
		$this->params = ['projectId' => 'p1', 'billId' => '0'];
		$this->cospend()->link('r', 's', 'o');
		$this->params = ['projectId' => 'p1', 'billId' => '9'];
		$this->assertSame([201, ['link' => 'cospend']], self::answer($this->cospend()->link('r', 's', 'o')));
		$this->params = ['projectId' => 'p1', 'entryType' => 'bill'];
		$this->cospend()->link('r', 's', 'o');

		$this->assertSame(
			[
				['linkProject', 'obj-uuid/3/4', 'p1'],
				['linkProject', 'obj-uuid/3/4', 'p1'],
				['linkBill', 'obj-uuid/3/4', 'p1', 9],
				['linkBill', 'obj-uuid/3/4', 'p1', 0],
			],
			$this->calls
		);
	}

	public function testCospendCreateAndLinkNeedsANameAndPassesTheCurrency(): void {
		$this->assertSame([400, ['error' => 'name is required']], self::answer($this->cospend()->createAndLink('r', 's', 'o')));

		$this->params = ['name' => 'Trip', 'currency' => 'EUR'];
		$this->assertSame([201, ['link' => 'cospend']], self::answer($this->cospend()->createAndLink('r', 's', 'o')));
		$this->params = ['name' => 'Trip'];
		$this->cospend()->createAndLink('r', 's', 'o');

		$this->assertSame(
			[
				['createAndLinkProject', 'obj-uuid/3/4', 'Trip', 'EUR'],
				['createAndLinkProject', 'obj-uuid/3/4', 'Trip', ''],
			],
			$this->calls
		);
	}

	public function testDestroyUnlinksByTheNumericId(): void {
		$this->assertSame([200, ['success' => true]], self::answer($this->analytics()->destroy('r', 's', 'o', '17')));
		$this->assertSame([200, ['success' => true]], self::answer($this->cospend()->destroy('r', 's', 'o', '18')));
		$this->assertSame([['unlinkReport', 'obj-uuid', 17], ['unlink', 'obj-uuid', 18]], $this->calls);
	}

	public function testAvailableListsThePickerWithTheOptionalSearch(): void {
		$this->assertSame([200, ['results' => [['id' => 1], ['id' => 2]], 'total' => 2]], self::answer($this->analytics()->available()));
		$this->assertSame([200, ['results' => [['id' => 'p1']], 'total' => 1]], self::answer($this->cospend()->available()));
		$this->params = ['search' => 42];
		$this->analytics()->available();
		$this->cospend()->available();

		$this->assertSame(
			[['getAvailableReports', null], ['getAvailableProjects', null], ['getAvailableReports', '42'], ['getAvailableProjects', '42']],
			$this->calls
		);
	}

	public static function failures(): array {
		return [
			'400 passes' => [400, 400],
			'401 passes' => [401, 401],
			'404 passes' => [404, 404],
			'409 passes' => [409, 409],
			'503 passes' => [503, 503],
			'500 becomes 400' => [500, 400],
			'no code becomes 400' => [0, 400],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('failures')]
	public function testServiceFailuresAnswerTheirCodeOr400(int $code, int $status): void {
		$failure = new Exception('service said no', $code);
		$this->params = ['reportId' => '12', 'projectId' => 'p1'];

		$this->assertSame([$status, ['error' => 'service said no']], self::answer($this->analytics(true, $failure)->link('r', 's', 'o')));
		$this->assertSame([$status, ['error' => 'service said no']], self::answer($this->analytics(true, $failure)->destroy('r', 's', 'o', '1')));
		$this->assertSame([$status, ['error' => 'service said no']], self::answer($this->cospend(true, $failure)->link('r', 's', 'o')));
		$this->assertSame([$status, ['error' => 'service said no']], self::answer($this->cospend(true, $failure)->destroy('r', 's', 'o', '1')));
	}
}//end class
