<?php

/**
 * HandlesExceptionsTrait — golden test of what the two log paths write.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller\Trait
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Trait;

use OCA\OpenRegister\Controller\Trait\HandlesExceptionsTrait;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;
use Throwable;

/**
 * Pins the log lines, log context and response bodies of both entry points,
 * with and without a context label, and with no usable logger at all.
 */
class HandlesExceptionsTraitLoggingGoldenTest extends TestCase {
	/**
	 * A logger that records every call.
	 *
	 * @return HandlesExceptionsTraitRecordingLogger The recording logger.
	 */
	private function recorder(): HandlesExceptionsTraitRecordingLogger {
		return new HandlesExceptionsTraitRecordingLogger();
	}//end recorder()

	/**
	 * A trait consumer whose `logger` property holds the given value.
	 *
	 * @param mixed $logger The logger property's value.
	 *
	 * @return object The consumer.
	 */
	private function consumer(mixed $logger): object {
		return new class($logger) {
			use HandlesExceptionsTrait;

			public function __construct(
				public mixed $logger,
			) {
			}//end __construct()

			public function translate(Throwable $e, string $context = ''): JSONResponse {
				return $this->handleApiException(e: $e, context: $context);
			}//end translate()

			public function legacy(Throwable $e, string $context = ''): JSONResponse {
				return $this->errorResponse(e: $e, context: $context);
			}//end legacy()
		};
	}//end consumer()

	/**
	 * A trait consumer without any `logger` property.
	 *
	 * @return object The consumer.
	 */
	private function loggerlessConsumer(): object {
		return new class {
			use HandlesExceptionsTrait;

			public function translate(Throwable $e): JSONResponse {
				return $this->handleApiException(e: $e);
			}//end translate()

			public function legacy(Throwable $e): JSONResponse {
				return $this->errorResponse(e: $e);
			}//end legacy()
		};
	}//end loggerlessConsumer()

	public function testTypedTranslationWarnsWithContextLabel(): void {
		$logger = $this->recorder();
		$consumer = $this->consumer($logger);
		$e = new NotAuthorizedException('nope');

		$response = $consumer->translate($e, 'Updating object');

		$this->assertSame(403, $response->getStatus());
		$this->assertSame(['message' => 'nope', 'error' => 'not-authorized'], $response->getData());
		$this->assertCount(1, $logger->records);
		$this->assertSame('warning', $logger->records[0][0]);
		$this->assertSame('[' . get_class($consumer) . '] Updating object: nope (translated to HTTP 403)', $logger->records[0][1]);
		$this->assertSame(['exception' => $e, 'status' => 403], $logger->records[0][2]);
	}//end testTypedTranslationWarnsWithContextLabel()

	public function testTypedTranslationWarnsWithoutContextLabel(): void {
		$logger = $this->recorder();
		$consumer = $this->consumer($logger);

		$consumer->translate(new DoesNotExistException('gone'));

		$this->assertSame('[' . get_class($consumer) . '] gone (translated to HTTP 404)', $logger->records[0][1]);
	}//end testTypedTranslationWarnsWithoutContextLabel()

	public function testGeneric500LogsErrorWithContextLabel(): void {
		$logger = $this->recorder();
		$consumer = $this->consumer($logger);
		$e = new RuntimeException('SQLSTATE secret');

		$response = $consumer->legacy($e, 'Saving');

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['error' => 'Internal server error'], $response->getData());
		$this->assertCount(1, $logger->records);
		$this->assertSame('error', $logger->records[0][0]);
		$this->assertSame('[' . get_class($consumer) . '] Saving: SQLSTATE secret', $logger->records[0][1]);
		$this->assertSame(['file', 'line', 'exception'], array_keys($logger->records[0][2]));
		$this->assertStringEndsWith('HandlesExceptionsTrait.php', $logger->records[0][2]['file']);
		$this->assertIsInt($logger->records[0][2]['line']);
		$this->assertSame($e, $logger->records[0][2]['exception']);
	}//end testGeneric500LogsErrorWithContextLabel()

	public function testUntypedTranslationLogsErrorWithoutContextLabel(): void {
		$logger = $this->recorder();
		$consumer = $this->consumer($logger);

		$response = $consumer->translate(new RuntimeException('boom'));

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['error' => 'Internal server error'], $response->getData());
		$this->assertCount(1, $logger->records);
		$this->assertSame('[' . get_class($consumer) . '] boom', $logger->records[0][1]);
	}//end testUntypedTranslationLogsErrorWithoutContextLabel()

	public function testANonLoggerPropertyLogsNothingAndStillAnswers(): void {
		$consumer = $this->consumer('not a logger');

		$this->assertSame(403, $consumer->translate(new NotAuthorizedException('nope'))->getStatus());
		$this->assertSame(500, $consumer->legacy(new RuntimeException('boom'))->getStatus());
	}//end testANonLoggerPropertyLogsNothingAndStillAnswers()

	public function testNoLoggerPropertyLogsNothingAndStillAnswers(): void {
		$consumer = $this->loggerlessConsumer();

		$this->assertSame(['message' => 'nope', 'error' => 'not-authorized'], $consumer->translate(new NotAuthorizedException('nope'))->getData());
		$this->assertSame(['error' => 'Internal server error'], $consumer->legacy(new RuntimeException('boom'))->getData());
	}//end testNoLoggerPropertyLogsNothingAndStillAnswers()
}//end class

/**
 * A logger that records every call, for the golden test above.
 */
final class HandlesExceptionsTraitRecordingLogger extends AbstractLogger {
	/**
	 * The recorded calls: level, message, context.
	 *
	 * @var list<array{0: mixed, 1: string, 2: array<mixed>}>
	 */
	public array $records = [];

	/**
	 * Record one call.
	 *
	 * @param mixed             $level   The level.
	 * @param string|Stringable $message The message.
	 * @param array<mixed>      $context The context.
	 *
	 * @return void
	 */
	public function log($level, string|Stringable $message, array $context = []): void {
		$this->records[] = [$level, (string)$message, $context];
	}//end log()
}//end class
