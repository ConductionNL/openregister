<?php

/**
 * HandlesExceptionsTrait error slug fallback tests.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller\Trait
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Trait;

use OCA\OpenRegister\Controller\Trait\HandlesExceptionsTrait;
use OCA\OpenRegister\Tests\Unit\Controller\Trait\Fixture\Exception as PlainNamedException;
use OCP\AppFramework\Http\JSONResponse;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * A typed exception whose class is named just `Exception` leaves no slug after
 * the suffix is stripped, so the response says `error` instead of an empty slug.
 */
class HandlesExceptionsTraitPlainNameTest extends TestCase {

	/**
	 * Translate an exception through a host using the trait.
	 *
	 * @param Throwable $e The exception.
	 *
	 * @return JSONResponse
	 */
	private function translate(Throwable $e): JSONResponse {
		$host = new class($this->createMock(LoggerInterface::class)) {
			use HandlesExceptionsTrait;

			/**
			 * Constructor.
			 *
			 * @param LoggerInterface $logger The logger.
			 */
			public function __construct(protected LoggerInterface $logger) {
			}//end __construct()

			/**
			 * Expose the translation.
			 *
			 * @param Throwable $e The exception.
			 *
			 * @return JSONResponse
			 */
			public function run(Throwable $e): JSONResponse {
				return $this->handleApiException(e: $e);
			}//end run()
		};
		return $host->run($e);
	}//end translate()

	/**
	 * A bare `Exception` class name falls back to the `error` slug.
	 *
	 * @return void
	 */
	public function testABareExceptionNameFallsBackToError(): void {
		$response = $this->translate(new PlainNamedException('bad input'));

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['message' => 'bad input', 'error' => 'error'], $response->getData());
	}//end testABareExceptionNameFallsBackToError()

	/**
	 * A named exception keeps its kebab-case slug.
	 *
	 * @return void
	 */
	public function testANamedExceptionKeepsItsSlug(): void {
		$response = $this->translate(new \InvalidArgumentException('bad input'));

		$this->assertSame(['message' => 'bad input', 'error' => 'invalid-argument'], $response->getData());
	}//end testANamedExceptionKeepsItsSlug()
}//end class
