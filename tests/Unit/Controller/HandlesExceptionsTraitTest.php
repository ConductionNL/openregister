<?php
/**
 * HandlesExceptionsTrait — typed exception→status map + leak-safety tests.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\AppHost\Exception\ConfigurationMissingException;
use OCA\OpenRegister\AppHost\Exception\FoundationUnavailableException;
use OCA\OpenRegister\Controller\Trait\HandlesExceptionsTrait;
use OCA\OpenRegister\Exception\AppendOnlyException;
use OCA\OpenRegister\Exception\CustomValidationException;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Exception\ValidationException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * The ADR-051 typed map must translate, the untyped default must stay a
 * generic leak-safe 500, and the legacy errorResponse() contract must not
 * change for its existing consumers.
 */
class HandlesExceptionsTraitTest extends TestCase
{
    /**
     * Builds an anonymous trait consumer exposing the protected helpers.
     *
     * @param LoggerInterface $logger Logger observed by the trait.
     */
    private function consumer(LoggerInterface $logger): object
    {
        return new class($logger)
        {
            use HandlesExceptionsTrait;

            public function __construct(protected LoggerInterface $logger)
            {
            }//end __construct()

            public function translate(Throwable $e, string $context=''): JSONResponse
            {
                return $this->handleApiException(e: $e, context: $context);
            }//end translate()

            public function legacy(Throwable $e): JSONResponse
            {
                return $this->errorResponse(e: $e);
            }//end legacy()
        };
    }//end consumer()

    /**
     * Typed exception → status expectations (tasks.md 1.2 map).
     *
     * @return array<string, array{0: Throwable, 1: int}>
     */
    public static function typedExceptionProvider(): array
    {
        return [
            'not-found → 404'         => [new DoesNotExistException('gone'), Http::STATUS_NOT_FOUND],
            'forbidden → 403'         => [new NotAuthorizedException('nope'), Http::STATUS_FORBIDDEN],
            'validation → 422'        => [new ValidationException('invalid'), Http::STATUS_UNPROCESSABLE_ENTITY],
            'custom validation → 422' => [new CustomValidationException('invalid', []), Http::STATUS_UNPROCESSABLE_ENTITY],
            'bad argument → 400'      => [new \InvalidArgumentException('bad input'), Http::STATUS_BAD_REQUEST],
            'conflict → 409'          => [new MultipleObjectsReturnedException('two'), Http::STATUS_CONFLICT],
            'append-only → 405'       => [new AppendOnlyException('my-schema'), Http::STATUS_METHOD_NOT_ALLOWED],
            'foundation → 503'        => [new FoundationUnavailableException(appId: 'myapp'), Http::STATUS_SERVICE_UNAVAILABLE],
            'config missing → 503'    => [new ConfigurationMissingException(appId: 'myapp', configKey: 'register'), Http::STATUS_SERVICE_UNAVAILABLE],
        ];
    }//end typedExceptionProvider()

    #[DataProvider('typedExceptionProvider')]
    public function testTypedExceptionsTranslateToMappedStatusWithEnvelope(Throwable $e, int $status): void
    {
        $response = $this->consumer($this->createMock(LoggerInterface::class))->translate($e);

        $this->assertSame($status, $response->getStatus());

        // ADR-050 error envelope: {message, error} — message human readable,
        // error a machine-readable kebab slug.
        $data = $response->getData();
        $this->assertArrayHasKey('message', $data);
        $this->assertArrayHasKey('error', $data);
        $this->assertMatchesRegularExpression('/^[a-z0-9]+(-[a-z0-9]+)*$/', $data['error']);
    }//end testTypedExceptionsTranslateToMappedStatusWithEnvelope()

    public function testUntypedThrowableFallsThroughToGenericLeakSafe500(): void
    {
        // Scenario: Internal detail is not leaked — the detailed message goes
        // to the server log only, the body stays generic.
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('SQLSTATE[42S02] secret detail'), $this->anything());

        $response = $this->consumer($logger)->translate(new \RuntimeException('SQLSTATE[42S02] secret detail'));

        $this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
        $this->assertStringNotContainsString('SQLSTATE', json_encode($response->getData()));
    }//end testUntypedThrowableFallsThroughToGenericLeakSafe500()

    public function testTypedTranslationLogsAtWarningLevel(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('translated to HTTP 404'), $this->anything());

        $this->consumer($logger)->translate(new DoesNotExistException('gone'), 'Fetching object');
    }//end testTypedTranslationLogsAtWarningLevel()

    public function testSubclassesTranslateLikeTheirParents(): void
    {
        // instanceof map: a ValidationException subclass must also hit 422.
        $subclass = new class('circular') extends ValidationException {
        };

        $response = $this->consumer($this->createMock(LoggerInterface::class))->translate($subclass);
        $this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
    }//end testSubclassesTranslateLikeTheirParents()

    public function testLegacyErrorResponseContractUnchanged(): void
    {
        // Backwards compatibility for the 5 pre-existing consumers: always a
        // 500 with the exact legacy body, real message logged server-side.
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        $response = $this->consumer($logger)->legacy(new DoesNotExistException('even typed shapes stay 500 here'));

        $this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
        $this->assertSame(['error' => 'Internal server error'], $response->getData());
    }//end testLegacyErrorResponseContractUnchanged()
}//end class
