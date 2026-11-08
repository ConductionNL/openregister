<?php

/**
 * Golden outcomes of the processing-log inquiry, allowed and refused.
 *
 * Pins status and body of every access path of ProcessingLogController:
 * authentication, the admin / privacy-officer gate, organisation scoping,
 * the confidential flag and the subject-extract refusals.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/avg-verwerkingsregister/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use DateTime;
use OCA\OpenRegister\Controller\ProcessingLogController;
use OCA\OpenRegister\Controller\Support\ControllerSession;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\ProcessingLogMapper;
use OCA\OpenRegister\Db\ProcessingLogReadScope;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Controller\Support\ProcessingLogControllerServices;

/**
 * Allowed and refused outcomes of the processing-log API.
 */
final class ProcessingLogControllerA24GoldenTest extends TestCase {

	/**
	 * The log mapper of the controller under test.
	 *
	 * @var ProcessingLogMapper&MockObject
	 */
	private $logMapper;

	/**
	 * The arguments every mapper call was made with.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $calls = [];

	protected function setUp(): void {
		$this->logMapper = $this->createMock(ProcessingLogMapper::class);
		$this->logMapper->method('findFiltered')->willReturnCallback(
			function (array $filters, ProcessingLogReadScope $scope, int $limit, int $offset): array {
				$this->calls[] = [$filters, $scope->from, $scope->to, $scope->organisationId, $scope->includeConfidential, $limit, $offset];

				return [['id' => 1]];
			}
		);
		$this->logMapper->method('findBySubject')->willReturnCallback(
			function (string $idType, string $idValue, ProcessingLogReadScope $scope): array {
				$this->calls[] = [$idType, $idValue, $scope->from, $scope->to, $scope->organisationId, $scope->includeConfidential];

				return [['id' => 2], ['id' => 3]];
			}
		);
	}//end setUp()

	/**
	 * The controller for one caller.
	 *
	 * @param string|null $uid      The caller, null when anonymous.
	 * @param array       $groupIds The caller's groups.
	 * @param array       $params   Request parameters.
	 * @param array       $orgs     The caller's organisation uuids.
	 * @param string      $fgGroup  The configured privacy-officer group.
	 *
	 * @return ProcessingLogController The controller.
	 */
	private function controller(
		?string $uid,
		array $groupIds = [],
		array $params = [],
		array $orgs = [],
		string $fgGroup = 'fg',
	): ProcessingLogController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, $default = null) => ($params[$key] ?? $default));

		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('getUserGroupIds')->willReturn($groupIds);
		$groups->method('isAdmin')->willReturn(false);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn($fgGroup);
		$config->method('getValueInt')->willReturn(30);

		$organisations = $this->createMock(OrganisationService::class);
		$organisations->method('getUserOrganisations')->willReturn(
			array_map(
				static function (string $uuid): Organisation {
					$organisation = new Organisation();
					$organisation->setUuid($uuid);

					return $organisation;
				},
				$orgs
			)
		);

		return new ProcessingLogController(
			$request,
			new ProcessingLogControllerServices($this->logMapper, $config),
			new ControllerSession($session, $groups, $this->createMock(LoggerInterface::class)),
			$organisations
		);
	}//end controller()

	private function outcome($response): array {
		return [$response->getStatus(), $response->getData()];
	}//end outcome()

	public function testAnonymousIsRefusedWith401(): void {
		$refusal = [401, ['error' => 'Authentication required']];
		$this->assertSame($refusal, $this->outcome($this->controller(null)->index()));
		$this->assertSame($refusal, $this->outcome($this->controller(null)->involvedParty()));
		$this->assertSame([], $this->calls);
	}//end testAnonymousIsRefusedWith401()

	public function testNeitherAdminNorPrivacyOfficerIsRefusedWith403(): void {
		$refusal = [403, ['error' => 'Privacy-officer or admin privileges required']];
		$this->assertSame($refusal, $this->outcome($this->controller('jan', ['users', 'privacy-officer'])->index()));
		$this->assertSame($refusal, $this->outcome($this->controller('jan', ['users'])->involvedParty()));
		$this->assertSame([], $this->calls);
	}//end testNeitherAdminNorPrivacyOfficerIsRefusedWith403()

	public function testAnEmptyFgSettingFallsBackToPrivacyOfficer(): void {
		$response = $this->controller('fg', ['privacy-officer'], [], ['org-a'], '')->index();

		$this->assertSame([200, ['count' => 1, 'results' => [['id' => 1]]]], $this->outcome($response));
	}//end testAnEmptyFgSettingFallsBackToPrivacyOfficer()

	public function testAPrivacyOfficerWithoutOrganisationGetsNothing(): void {
		$empty = [200, ['count' => 0, 'results' => []]];
		$this->assertSame($empty, $this->outcome($this->controller('fg', ['fg'], [], [''])->index()));
		$this->assertSame($empty, $this->outcome($this->controller('fg', ['fg'], ['subjectIdType' => 'bsn', 'subjectIdValue' => '1'])->involvedParty()));
		$this->assertSame([], $this->calls);
	}//end testAPrivacyOfficerWithoutOrganisationGetsNothing()

	public function testAPrivacyOfficerIsScopedToTheirFirstOrganisation(): void {
		$params = ['register' => 'r', 'schema' => '', 'actor' => 'a', 'limit' => '5000', 'offset' => '-3', 'from' => '2026-01-01', 'to' => 'nonsense'];
		$response = $this->controller('fg', ['fg'], $params, ['', 'org-a', 'org-b'])->index();

		$this->assertSame([200, ['count' => 1, 'results' => [['id' => 1]]]], $this->outcome($response));
		[$filters, $from, $to, $organisationId, $confidential, $limit, $offset] = $this->calls[0];
		$this->assertSame(
			[
				'register_id' => 'r',
				'schema_id' => null,
				'activity_id' => null,
				'actor' => 'a',
				'action' => null,
				'subject_id_type' => null,
				'subject_id_value' => null,
			],
			$filters
		);
		$this->assertInstanceOf(DateTime::class, $from);
		$this->assertSame([null, 'org-a', true, 1000, 0], [$to, $organisationId, $confidential, $limit, $offset]);
	}//end testAPrivacyOfficerIsScopedToTheirFirstOrganisation()

	public function testAnAdminIsUnscopedWithDefaultPaging(): void {
		$this->controller('root', ['admin'], ['limit' => 'abc'], ['org-a'])->index();

		$this->assertSame([null, true, 100, 0], array_slice($this->calls[0], 3));
	}//end testAnAdminIsUnscopedWithDefaultPaging()

	public function testTheExtractRequiresBothIdentifiers(): void {
		$refusal = [400, ['error' => 'subjectIdType and subjectIdValue are required']];
		$this->assertSame($refusal, $this->outcome($this->controller('root', ['admin'], ['subjectIdType' => 'bsn'])->involvedParty()));
		$this->assertSame($refusal, $this->outcome($this->controller('root', ['admin'], ['subjectIdValue' => '1'])->involvedParty()));
		$this->assertSame([], $this->calls);
	}//end testTheExtractRequiresBothIdentifiers()

	public function testTheExtractRefusesATooWideRangeWith422(): void {
		$params = ['subjectIdType' => 'bsn', 'subjectIdValue' => '1', 'from' => '2026-01-01T00:00:00+00:00', 'to' => '2026-03-01T00:00:00+00:00'];

		$response = $this->controller('root', ['admin'], $params)->involvedParty();

		$this->assertSame(
			[422, ['error' => 'Requested period exceeds the maximum extract range', 'maxDays' => 30]],
			$this->outcome($response)
		);
		$this->assertSame([], $this->calls);
	}//end testTheExtractRefusesATooWideRangeWith422()

	public function testTheExtractWithinRange(): void {
		$params = ['subjectIdType' => 'bsn', 'subjectIdValue' => '1', 'from' => '2026-01-01T00:00:00+00:00', 'to' => '2026-01-31T00:00:00+00:00'];

		$response = $this->controller('fg', ['fg'], $params, ['org-a'])->involvedParty();

		$this->assertSame(200, $response->getStatus());
		$data = $response->getData();
		$this->assertSame(['subject', 'period', 'count', 'reads'], array_keys($data));
		$this->assertSame(['idType' => 'bsn', 'idValue' => '1'], $data['subject']);
		$this->assertSame(['from', 'to'], array_keys($data['period']));
		$this->assertSame([2, [['id' => 2], ['id' => 3]]], [$data['count'], $data['reads']]);
		$this->assertSame(['bsn', '1'], array_slice($this->calls[0], 0, 2));
		$this->assertSame(['org-a', true], array_slice($this->calls[0], 4));
	}//end testTheExtractWithinRange()

	public function testTheExtractWithoutAPeriod(): void {
		$response = $this->controller('root', ['admin'], ['subjectIdType' => 'bsn', 'subjectIdValue' => '1'])->involvedParty();

		$this->assertSame(['from' => null, 'to' => null], $response->getData()['period']);
		$this->assertSame([null, null, null, true], array_slice($this->calls[0], 2));
	}//end testTheExtractWithoutAPeriod()
}//end class
