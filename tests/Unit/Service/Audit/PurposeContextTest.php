<?php

/**
 * Unit tests for how a caller declares the purpose of its read.
 *
 * Covers the two ways a purpose arrives, their precedence, and the separation
 * that matters most: what the caller CLAIMED is never what gets written.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Audit
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/verwerkingsregister-api/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Audit;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\ProcessingPurpose;
use OCA\OpenRegister\Service\Audit\PurposeContext;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

final class PurposeContextTest extends TestCase {
	private function context(string $header = '', mixed $param = null): PurposeContext {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(
			static function (string $name) use ($header): string {
				if ($name === PurposeContext::HEADER) {
					return $header;
				}

				return '';
			}
		);
		$request->method('getParam')->willReturnCallback(
			static function (string $key, $default = null) use ($param) {
				if ($key === PurposeContext::PARAM) {
					return $param;
				}

				return $default;
			}
		);

		return new PurposeContext($request);
	}//end context()

	public function testAKoppelingDeclaresItsPurposeInTheHeader(): void {
		self::assertSame('brp-adresonderzoek', $this->context(header: 'brp-adresonderzoek')->declared());
	}//end testAKoppelingDeclaresItsPurposeInTheHeader()

	public function testAClientMayDeclareItInTheUnderscoredParameterInstead(): void {
		// Underscored like every other control parameter, so a schema stays
		// free to declare a property called `purpose`.
		self::assertSame('kvk-controle', $this->context(param: 'kvk-controle')->declared());
	}//end testAClientMayDeclareItInTheUnderscoredParameterInstead()

	public function testTheHeaderWinsOverTheParameter(): void {
		$context = $this->context(header: 'from-header', param: 'from-param');

		self::assertSame('from-header', $context->declared());
	}//end testTheHeaderWinsOverTheParameter()

	public function testAPurposeSetInCodeWinsOverBoth(): void {
		// A background job or a flow has no request headers to carry one.
		$context = $this->context(header: 'from-header', param: 'from-param');
		$context->setDeclared('from-code');

		self::assertSame('from-code', $context->declared());
	}//end testAPurposeSetInCodeWinsOverBoth()

	public function testWhitespaceOnlyCountsAsNoPurposeAtAll(): void {
		self::assertNull($this->context(header: '   ')->declared());
		self::assertNull($this->context(param: "\t")->declared());
	}//end testWhitespaceOnlyCountsAsNoPurposeAtAll()

	public function testNothingDeclaredIsNull(): void {
		self::assertNull($this->context()->declared());
	}//end testNothingDeclaredIsNull()

	public function testWhatTheCallerClaimedIsNotWhatGetsAccepted(): void {
		// The claim is an unvalidated string off the wire. Collapsing the two
		// would let a caller write any string it liked into the trail simply
		// by naming it.
		$context = $this->context(header: 'anything-i-like');

		self::assertSame('anything-i-like', $context->declared());
		self::assertNull($context->accepted());

		$purpose = new ProcessingPurpose();
		$purpose->setCode('brp-adresonderzoek');
		$context->accept($purpose);

		self::assertSame('brp-adresonderzoek', $context->accepted()?->getCode());
	}//end testWhatTheCallerClaimedIsNotWhatGetsAccepted()

	public function testClearingForgetsBoth(): void {
		$context = $this->context(header: 'brp-adresonderzoek');
		$purpose = new ProcessingPurpose();
		$purpose->setCode('brp-adresonderzoek');
		$context->accept($purpose);

		$context->clear();

		self::assertNull($context->accepted());
		self::assertNull($context->acceptedActivity());
		// The header is still on the request, so `declared()` reads it again.
		self::assertSame('brp-adresonderzoek', $context->declared());
	}//end testClearingForgetsBoth()

	public function testWithdrawingTheCodeAlsoWithdrawsTheAcceptance(): void {
		$context = $this->context();
		$purpose = new ProcessingPurpose();
		$purpose->setCode('brp-adresonderzoek');
		$context->setDeclared('brp-adresonderzoek');
		$context->accept($purpose);

		$context->setDeclared(null);

		self::assertNull($context->accepted());
	}//end testWithdrawingTheCodeAlsoWithdrawsTheAcceptance()
}//end class
