<?php

/**
 * Golden test for MigrationPackErrorResponder: every answer's status and body, and what an unexpected failure logs.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\MigrationPack
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\MigrationPack;

use OCA\OpenRegister\Exception\BuiltinPackSlugLockedException;
use OCA\OpenRegister\Exception\InvalidPackDefinitionException;
use OCA\OpenRegister\Exception\MigrationPackRequestException;
use OCA\OpenRegister\Exception\PackChangedException;
use OCA\OpenRegister\Exception\PackSlugExistsException;
use OCA\OpenRegister\Service\MigrationPack\MigrationPackErrorResponder;
use OCP\AppFramework\Http\JSONResponse;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins the status, error code and message of every migration-pack answer.
 *
 * @covers \OCA\OpenRegister\Service\MigrationPack\MigrationPackErrorResponder
 */
class MigrationPackErrorResponderGoldenTest extends TestCase {
	use PassThroughL10nTrait;

	/**
	 * Status and body of a response.
	 *
	 * @param JSONResponse $response The response.
	 *
	 * @return array{0: int, 1: mixed}
	 */
	private static function shape(JSONResponse $response): array {
		return [$response->getStatus(), $response->getData()];
	}//end shape()

	/**
	 * @return void
	 */
	public function testFixedAnswersAndRefusals(): void {
		$responder = new MigrationPackErrorResponder($this->passThroughL10n('NL:'), $this->createMock(LoggerInterface::class));

		$this->assertSame([401, ['message' => 'NL:Authentication required', 'error' => 'not-authenticated']], self::shape($responder->notAuthenticated()));
		$this->assertSame(
			[403, ['message' => 'NL:Administrator privileges required', 'error' => 'admin-required']],
			self::shape($responder->adminRequired())
		);
		$this->assertSame([404, ['message' => 'NL:Migration pack not found', 'error' => 'pack-not-found']], self::shape($responder->notFound()));
		$this->assertSame(
			[400, ['message' => 'Te veel rijen', 'error' => 'too-many-rows']],
			self::shape($responder->refused(new MigrationPackRequestException('too-many-rows', 'Te veel rijen')))
		);
		$this->assertSame(
			[409, ['message' => 'NL:The migration pack was saved by someone else after it was read', 'error' => 'pack-changed']],
			self::shape($responder->refused(new PackChangedException('changed')))
		);
		$this->assertSame(
			[422, ['message' => 'NL:The id of a built-in migration pack cannot be changed', 'error' => 'builtin-slug-locked']],
			self::shape($responder->refused(new BuiltinPackSlugLockedException('locked')))
		);
		$this->assertSame(
			[422, ['message' => 'NL:A migration pack with this id already exists', 'error' => 'pack-slug-exists']],
			self::shape($responder->refused(new PackSlugExistsException('zaken')))
		);
		$this->assertSame(
			[422, ['message' => 'NL:The pack definition is invalid', 'error' => 'invalid-pack', 'details' => ['errors' => ['a', 'b']]]],
			self::shape($responder->refused(new InvalidPackDefinitionException(['a', 'b'])))
		);
	}//end testFixedAnswersAndRefusals()

	/**
	 * @return void
	 */
	public function testUnexpectedLogsTheFailureAndAnswersStatically(): void {
		$expected = [
			'import' => 'NL:Failed to import migration pack',
			'create' => 'NL:Failed to create migration pack',
			'update' => 'NL:Failed to update migration pack',
			'delete' => 'NL:Failed to delete migration pack',
			'preview' => 'NL:Failed to preview migration pack',
		];
		foreach ($expected as $action => $message) {
			$exception = new RuntimeException('secret detail');
			$logger = $this->createMock(LoggerInterface::class);
			$logger->expects($this->once())->method('error')->with(
				'[MigrationPacksController] Error during migration pack ' . $action . ': secret detail',
				['id' => 7, 'exception' => $exception]
			);
			$responder = new MigrationPackErrorResponder($this->passThroughL10n('NL:'), $logger);

			$this->assertSame(
				[500, ['message' => $message, 'error' => 'migration-pack-error']],
				self::shape($responder->unexpected($action, $exception, ['id' => 7]))
			);
		}
	}//end testUnexpectedLogsTheFailureAndAnswersStatically()

	/**
	 * @return void
	 */
	public function testUnexpectedContextCannotReplaceTheException(): void {
		$exception = new RuntimeException('boom');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			'[MigrationPacksController] Error during migration pack create: boom',
			['exception' => $exception]
		);
		$responder = new MigrationPackErrorResponder($this->passThroughL10n(), $logger);

		$this->assertSame(500, $responder->unexpected('create', $exception, ['exception' => 'spoofed'])->getStatus());
	}//end testUnexpectedContextCannotReplaceTheException()
}//end class
