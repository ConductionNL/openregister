<?php

/**
 * GitLabHandler::publishFile: a configuration file is created on a branch,
 * a second publish updates it, and a failed request is reported.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use Exception;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use OCA\OpenRegister\Service\Configuration\GitLabApiClient;
use OCA\OpenRegister\Service\Configuration\GitLabHandler;
use OCA\OpenRegister\Service\Configuration\GitLabProjectRef;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * GitLabHandler publishing.
 *
 * @covers \OCA\OpenRegister\Service\Configuration\GitLabHandler
 * @uses \OCA\OpenRegister\Service\Configuration\GitLabProjectRef
 * @uses \OCA\OpenRegister\Service\Configuration\RepositoryApiCall
 *
 * @spec openspec/changes/capability-rows-verified-b/specs/data-import-export/spec.md
 */
class GitLabHandlerPublishTest extends TestCase {

	private GitLabApiClient&MockObject $api;

	private LoggerInterface&MockObject $logger;

	private const FILE = '/projects/42/repository/files/configurations%2Fafval-meldingen.json';

	protected function setUp(): void {
		parent::setUp();
		$this->api    = $this->createMock(GitLabApiClient::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	private function handler(): GitLabHandler {
		return new GitLabHandler($this->api, $this->logger);
	}//end handler()

	private static function httpError(int $code, string $method = 'GET'): ClientException|ServerException {
		$request = new Request($method, 'https://git.example.nl/api/v4'.self::FILE);
		if ($code >= 500) {
			return new ServerException("HTTP {$code}", $request, new Response($code));
		}

		return new ClientException("HTTP {$code}", $request, new Response($code));
	}//end httpError()

	private function publish(): array {
		return $this->handler()->publishFile(
			new GitLabProjectRef(projectId: 42, ref: 'main'),
			'configurations/afval-meldingen.json',
			'{"x-openregister":{}}',
			'Update configuration: afval-meldingen'
		);
	}//end publish()

	public function testAFileThatIsNotOnTheBranchIsCreatedWithPost(): void {
		$this->api->expects($this->once())->method('getJson')
			->with(self::FILE, ['query' => ['ref' => 'main']])
			->willThrowException(self::httpError(404));
		$this->api->expects($this->once())->method('sendJson')
			->with(
				'POST',
				self::FILE,
				['branch' => 'main', 'content' => '{"x-openregister":{}}', 'commit_message' => 'Update configuration: afval-meldingen']
			)
			->willReturn(['file_path' => 'configurations/afval-meldingen.json', 'branch' => 'main']);

		$this->assertSame(
			['success' => true, 'is_update' => false, 'file_path' => 'configurations/afval-meldingen.json', 'branch' => 'main'],
			$this->publish()
		);
	}//end testAFileThatIsNotOnTheBranchIsCreatedWithPost()

	public function testASecondPublishUpdatesTheSameFileWithPut(): void {
		$this->api->expects($this->once())->method('getJson')
			->with(self::FILE, ['query' => ['ref' => 'main']])
			->willReturn(['file_path' => 'configurations/afval-meldingen.json']);
		$this->api->expects($this->once())->method('sendJson')
			->with('PUT', self::FILE, $this->anything())
			->willReturn(['file_path' => 'configurations/afval-meldingen.json', 'branch' => 'main']);

		$result = $this->publish();

		$this->assertTrue($result['is_update']);
		$this->assertSame('configurations/afval-meldingen.json', $result['file_path']);
	}//end testASecondPublishUpdatesTheSameFileWithPut()

	public function testAnAnswerWithoutPathAndBranchFallsBackToTheRequestedOnes(): void {
		$this->api->method('getJson')->willThrowException(self::httpError(404));
		$this->api->method('sendJson')->willReturn(null);

		$result = $this->publish();

		$this->assertSame(['configurations/afval-meldingen.json', 'main'], [$result['file_path'], $result['branch']]);
	}//end testAnAnswerWithoutPathAndBranchFallsBackToTheRequestedOnes()

	public function testALookupFailureOtherThanNotFoundStopsThePublish(): void {
		$this->api->method('getJson')->willThrowException(self::httpError(403));
		$this->api->expects($this->never())->method('sendJson');
		$this->logger->expects($this->once())->method('error')
			->with('[GitLabHandler] GitLab API publish failed', $this->callback(
				static fn (array $context): bool => $context['project_id'] === 42 && $context['path'] === 'configurations/afval-meldingen.json'
			));

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Failed to publish file: HTTP 403');

		$this->publish();
	}//end testALookupFailureOtherThanNotFoundStopsThePublish()

	public function testAFailedWriteIsReportedAsAFailedPublish(): void {
		$this->api->method('getJson')->willThrowException(self::httpError(404));
		$this->api->method('sendJson')->willThrowException(self::httpError(500, 'POST'));

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Failed to publish file: HTTP 500');

		$this->publish();
	}//end testAFailedWriteIsReportedAsAFailedPublish()
}//end class
