<?php

/**
 * Unit test for TrimmedRequestParam.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\Support\TrimmedRequestParam;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * A request parameter read as trimmed, non-empty text.
 *
 * @covers \OCA\OpenRegister\Controller\Support\TrimmedRequestParam
 */
class TrimmedRequestParamTest extends TestCase {
	private function request(array $params): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => $params[$key] ?? $default);
		return $request;
	}

	public function testTextIsTrimmed(): void {
		$this->assertSame('main', TrimmedRequestParam::read($this->request(['branch' => "  main\n"]), 'branch'));
	}

	public function testBlankAbsentOrNonTextIsNull(): void {
		$request = $this->request(['blank' => '   ', 'number' => 5, 'list' => ['a']]);

		$this->assertNull(TrimmedRequestParam::read($request, 'blank'));
		$this->assertNull(TrimmedRequestParam::read($request, 'number'));
		$this->assertNull(TrimmedRequestParam::read($request, 'list'));
		$this->assertNull(TrimmedRequestParam::read($request, 'missing'));
	}
}
