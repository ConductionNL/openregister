<?php

/**
 * The OpenAnonymiser branch speaks anonymiq's entity names (or#4115).
 *
 * The branch sent Presidio's names (`EMAIL_ADDRESS`, `IBAN_CODE`) for a
 * filtered request. anonymiq accepts only its own vocabulary (`PERSON`,
 * `LOCATION`, `PHONE_NUMBER`, `EMAIL`, `ORGANIZATION`, `IBAN`, `DATE_TIME`,
 * `ADDRESS`) and rejects the whole request with 422. Open Register then
 * logged "OpenAnonymiser unreachable, falling back to regex", and every name,
 * organisation and place in the document went undetected.
 *
 * The branch now maps a filter to anonymiq's names, and a 4xx answer is a
 * request error, not an unreachable backend: it is not papered over with the
 * regex fallback.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\TextExtraction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/text-extraction/spec.md#requirement-file-and-object-chunk-extraction-lifecycle
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\TextExtraction;

use OCA\OpenRegister\Db\ChunkMapper;
use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Db\GdprEntityMapper;
use OCA\OpenRegister\Exception\AnalyzeRequestRejectedException;
use OCA\OpenRegister\Service\Anonymisation\AnonymisationBackendService;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\TextExtraction\EntityRecognitionHandler;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * @covers \OCA\OpenRegister\Service\TextExtraction\EntityRecognitionHandler
 */
class OpenAnonymiserEntityTypesTest extends TestCase {

	/**
	 * The backend double (real class, real method names).
	 *
	 * @var AnonymisationBackendService&MockObject
	 */
	private AnonymisationBackendService $backend;

	/**
	 * Every message logged, by level.
	 *
	 * @var array<int, string>
	 */
	private array $logged = [];

	/**
	 * Run the OpenAnonymiser branch over a text with a filter.
	 *
	 * @param array|null $entityTypes The type filter.
	 *
	 * @return array The detected entities.
	 */
	private function detect(?array $entityTypes): array {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getFileSettingsOnly')->willReturn(['openAnonymiserSource' => 'internal']);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'error', 'info', 'debug'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message) use ($level): void {
					$this->logged[] = $level . ': ' . $message;
				}
			);
		}

		$handler = new EntityRecognitionHandler(
			$this->createMock(ChunkMapper::class),
			$this->createMock(GdprEntityMapper::class),
			$this->createMock(EntityRelationMapper::class),
			$this->createMock(IDBConnection::class),
			$logger,
			$settings,
			$this->backend
		);

		$method = new ReflectionMethod(EntityRecognitionHandler::class, 'detectWithOpenAnonymiser');

		return $method->invoke($handler, 'Jan de Vries, jan@example.nl, NL91ABNA0417164300', $entityTypes, 0.5);

	}//end detect()

	/**
	 * Build the backend double.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->backend = $this->createMock(AnonymisationBackendService::class);

	}//end setUp()

	/**
	 * THE DEFECT: a filter is sent in anonymiq's names, not Presidio's.
	 *
	 * @return void
	 */
	public function testAFilterIsSentInAnonymiqsNames(): void {
		$sent = null;
		$this->backend->method('requestOpenAnonymiser')->willReturnCallback(
			function (string $route, array $params) use (&$sent): array {
				$sent = $params;
				return ['pii_entities' => []];
			}
		);

		$this->detect(['PERSON', 'EMAIL', 'IBAN', 'PHONE', 'DATE', 'LOCATION', 'ORGANIZATION', 'ADDRESS']);

		$this->assertSame(
			['PERSON', 'EMAIL', 'IBAN', 'PHONE_NUMBER', 'DATE_TIME', 'LOCATION', 'ORGANIZATION', 'ADDRESS'],
			($sent['entities'] ?? null)
		);

	}//end testAFilterIsSentInAnonymiqsNames()

	/**
	 * A type anonymiq does not know is left out rather than sent.
	 *
	 * @return void
	 */
	public function testATypeAnonymiqDoesNotKnowIsLeftOut(): void {
		$sent = null;
		$this->backend->method('requestOpenAnonymiser')->willReturnCallback(
			function (string $route, array $params) use (&$sent): array {
				$sent = $params;
				return ['pii_entities' => []];
			}
		);

		$this->detect(['PERSON', 'SSN', 'IP_ADDRESS']);

		$this->assertSame(['PERSON'], ($sent['entities'] ?? null));

	}//end testATypeAnonymiqDoesNotKnowIsLeftOut()

	/**
	 * A filter of only types anonymiq does not know asks it for nothing.
	 *
	 * Sending no `entities` list would ask for EVERY type, the opposite of
	 * what the operator switched on.
	 *
	 * @return void
	 */
	public function testAFilterWithNothingAnonymiqKnowsAsksForNothing(): void {
		$this->backend->expects($this->never())->method('requestOpenAnonymiser');

		$this->assertSame([], $this->detect(['SSN']));

	}//end testAFilterWithNothingAnonymiqKnowsAsksForNothing()

	/**
	 * Unfiltered answers in anonymiq's names come back as our types.
	 *
	 * @return void
	 */
	public function testAnswersAreMappedBack(): void {
		$this->backend->method('requestOpenAnonymiser')->willReturn(
			[
				'pii_entities' => [
					['entity_type' => 'PHONE_NUMBER', 'text' => '0612345678', 'start' => 0, 'end' => 10, 'score' => 0.9],
					['entity_type' => 'EMAIL', 'text' => 'jan@example.nl', 'start' => 14, 'end' => 28, 'score' => 0.9],
				],
			]
		);

		$types = array_column($this->detect(null), 'type');

		$this->assertContains(EntityRecognitionHandler::ENTITY_TYPE_PHONE, $types);
		$this->assertContains(EntityRecognitionHandler::ENTITY_TYPE_EMAIL, $types);

	}//end testAnswersAreMappedBack()

	/**
	 * A 4xx answer is a request error: no regex fallback, no "unreachable".
	 *
	 * @return void
	 */
	public function testARejectedRequestIsNotTreatedAsUnreachable(): void {
		$this->backend->method('requestOpenAnonymiser')->willThrowException(
			new AnalyzeRequestRejectedException(service: 'OpenAnonymiser', status: 422, detail: 'Unsupported entities')
		);

		try {
			$this->detect(['PERSON']);
			$this->fail('a rejected request must surface as an error');
		} catch (AnalyzeRequestRejectedException $e) {
			$this->assertSame(422, $e->getStatus());
		}

		foreach ($this->logged as $line) {
			$this->assertStringNotContainsString('falling back to regex', $line);
			$this->assertStringNotContainsString('unreachable', $line);
		}

	}//end testARejectedRequestIsNotTreatedAsUnreachable()

}//end class
