<?php

/**
 * The attach picker's route: what it reads from the request and what it answers.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Files\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/files-leaf-save-to-object/specs/file-actions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Files\Controller;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Files\Controller\AttachTargetsController;
use OCA\OpenRegister\Files\Service\Integration\AttachTargetSearch;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The attach picker's route.
 *
 * @spec openspec/changes/files-leaf-save-to-object/specs/file-actions/spec.md
 */
#[CoversClass(AttachTargetsController::class)]
class AttachTargetsControllerTest extends TestCase {

	private function controller(AttachTargetSearch $search, array $params): AttachTargetsController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(fn (string $key, $default = null) => ($params[$key] ?? $default));

		return new AttachTargetsController(request: $request, search: $search, logger: $this->createMock(LoggerInterface::class));
	}//end controller()

	public function testTheSearchAndTheDeclaredTargetsArePassedOn(): void {
		$answer = ['targets' => [['schema' => 'case', 'register' => 'cases', 'label' => 'Case']], 'results' => []];
		$search = $this->createMock(AttachTargetSearch::class);
		$search->expects($this->once())->method('find')->with('bouw', ['case', 'permit'])->willReturn($answer);

		$response = $this->controller($search, ['search' => 'bouw', 'targets' => 'case, permit,'])->index();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame($answer, $response->getData());
	}//end testTheSearchAndTheDeclaredTargetsArePassedOn()

	public function testAFailureIsA500WithoutItsDetail(): void {
		$search = $this->createMock(AttachTargetSearch::class);
		$search->method('find')->willThrowException(new RuntimeException('secret table name'));

		$response = $this->controller($search, [])->index();

		$this->assertSame(500, $response->getStatus());
		$this->assertStringNotContainsString('secret', (string)json_encode($response->getData()));
	}//end testAFailureIsA500WithoutItsDetail()

	public function testDeclaredTargetsAreReadFromAListOrAString(): void {
		$this->assertSame(['case', 'permit'], AttachTargetsController::declared(['case', ' permit ', '', ['x']]));
		$this->assertSame(['case'], AttachTargetsController::declared('case'));
		$this->assertNull(AttachTargetsController::declared(null));
		$this->assertNull(AttachTargetsController::declared(''));
		$this->assertNull(AttachTargetsController::declared(42));
	}//end testDeclaredTargetsAreReadFromAListOrAString()
}//end class
