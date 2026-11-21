<?php

/**
 * ChatProxiedResponseExceptionTest — the proxied response survives the throw.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Middleware\Exception
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Middleware\Exception;

use OCA\OpenRegister\Middleware\Exception\ChatProxiedResponseException;
use OCP\AppFramework\Http\JSONResponse;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Middleware\Exception\ChatProxiedResponseException
 */
class ChatProxiedResponseExceptionTest extends TestCase {

	public function testCarriesTheExactResponseAndAFixedMessage(): void {
		$response = new JSONResponse(['answer' => 42], 207);

		$exception = new ChatProxiedResponseException($response);

		$this->assertSame($response, $exception->getResponse());
		$this->assertSame(207, $exception->getResponse()->getStatus());
		$this->assertSame('Chat request served by the hermiq compat proxy', $exception->getMessage());
		$this->assertSame(0, $exception->getCode());
		$this->assertNull($exception->getPrevious());
	}

	public function testChainsThePreviousThrowable(): void {
		$previous = new RuntimeException('upstream');

		$exception = new ChatProxiedResponseException(new JSONResponse([]), $previous);

		$this->assertSame($previous, $exception->getPrevious());
	}
}
