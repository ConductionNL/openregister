<?php

/**
 * Unit tests for EmbeddingGeneratorHandler: provider dispatch, caching and the Fireworks client.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Search\Service\Vectorization\Handlers
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Search\Service\Vectorization\Handlers;

use Exception;
use LLPhant\Embeddings\Document;
use LLPhant\Embeddings\EmbeddingGenerator\Ollama\OllamaEmbeddingGenerator;
use LLPhant\Embeddings\EmbeddingGenerator\OpenAI\OpenAI3LargeEmbeddingGenerator;
use LLPhant\Embeddings\EmbeddingGenerator\OpenAI\OpenAI3SmallEmbeddingGenerator;
use LLPhant\Embeddings\EmbeddingGenerator\OpenAI\OpenAIADA002EmbeddingGenerator;
use LLPhant\Exception\MissingParameterException;
use OCA\OpenRegister\Search\Service\Vectorization\Handlers\EmbeddingGeneratorHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionProperty;

/**
 * The HTTP providers are a local `php -S` stub (Fixtures/embedding-stub-router.php)
 * that records every request it receives.
 *
 * @covers \OCA\OpenRegister\Search\Service\Vectorization\Handlers\EmbeddingGeneratorHandler
 */
class EmbeddingGeneratorHandlerTest extends TestCase
{
    /** @var resource|null */
    private static $server = null;

    private static string $base = '';

    private static string $requestLog = '';

    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    private EmbeddingGeneratorHandler $handler;

    public static function setUpBeforeClass(): void
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $port  = (int) substr((string) strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);

        self::$requestLog = (string) tempnam(sys_get_temp_dir(), 'embedding-stub');
        self::$base       = 'http://127.0.0.1:'.$port;
        $env    = array_merge(getenv(), ['EMBEDDING_STUB_LOG' => self::$requestLog]);
        $server = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:'.$port, __DIR__.'/Fixtures/embedding-stub-router.php'],
            [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
            $pipes,
            null,
            $env
        );
        self::$server = ($server === false) ? null : $server;
        for ($i = 0; $i < 100; $i++) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if ($socket !== false) {
                fclose($socket);
                break;
            }

            usleep(50000);
        }
    }//end setUpBeforeClass()

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server) === true) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }

        @unlink(self::$requestLog);
    }//end tearDownAfterClass()

    protected function setUp(): void
    {
        file_put_contents(self::$requestLog, '');
        $this->logger  = $this->createMock(LoggerInterface::class);
        $this->handler = new EmbeddingGeneratorHandler($this->logger);
    }//end setUp()

    /**
     * @return list<array<string, mixed>>
     */
    private function requests(): array
    {
        $lines = array_filter(explode("\n", (string) file_get_contents(self::$requestLog)));
        return array_values(array_map(static fn(string $l): array => json_decode($l, true), $lines));
    }//end requests()

    private function readPrivate(object $object, string $class, string $property): mixed
    {
        return (new ReflectionProperty($class, $property))->getValue($object);
    }//end readPrivate()

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function dimensionProvider(): array
    {
        return [
            'ada'           => ['text-embedding-ada-002', 1536],
            'small'         => ['text-embedding-3-small', 1536],
            'large'         => ['text-embedding-3-large', 3072],
            'nomic'         => ['nomic-embed-text', 768],
            'mxbai'         => ['mxbai-embed-large', 1024],
            'minilm'        => ['all-minilm', 384],
            'ollamaDefault' => ['ollama-default', 384],
            'unknownModel'  => ['some-unknown-model', 1536],
        ];
    }//end dimensionProvider()

    #[DataProvider('dimensionProvider')]
    public function testGetDefaultDimensionsPerModelWithFallback(string $model, int $expected): void
    {
        $this->assertSame($expected, $this->handler->getDefaultDimensions($model));
    }//end testGetDefaultDimensionsPerModelWithFallback()

    /**
     * @return array<string, array{0: string, 1: class-string, 2: int}>
     */
    public static function openAiModelProvider(): array
    {
        return [
            'ada'   => ['text-embedding-ada-002', OpenAIADA002EmbeddingGenerator::class, 1536],
            'small' => ['text-embedding-3-small', OpenAI3SmallEmbeddingGenerator::class, 1536],
            'large' => ['text-embedding-3-large', OpenAI3LargeEmbeddingGenerator::class, 3072],
        ];
    }//end openAiModelProvider()

    #[DataProvider('openAiModelProvider')]
    public function testOpenAiModelBuildsMatchingGeneratorWithKeyAndUrl(string $model, string $class, int $length): void
    {
        $this->logger->expects($this->once())->method('debug');
        $this->logger->expects($this->once())
            ->method('info')
            ->with(
                $this->anything(),
                $this->callback(
                    static fn(array $ctx): bool => $ctx['provider'] === 'openai'
                        && $ctx['model'] === $model
                        && $ctx['dimensions'] === $length
                )
            );

        $generator = $this->handler->getGenerator(
            ['provider' => 'openai', 'model' => $model, 'api_key' => 'sk-test', 'base_url' => 'https://llm.example.test/v1']
        );

        $this->assertInstanceOf($class, $generator);
        $this->assertSame('sk-test', $generator->apiKey);
        $this->assertSame(
            'https://llm.example.test/v1/embeddings',
            $this->readPrivate($generator, $class, 'uri')
        );
    }//end testOpenAiModelBuildsMatchingGeneratorWithKeyAndUrl()

    public function testOpenAiWithoutBaseUrlKeepsLlphantDefaultUrl(): void
    {
        $generator = $this->handler->getGenerator(
            ['provider' => 'openai', 'model' => 'text-embedding-3-small', 'api_key' => 'sk-test', 'base_url' => '']
        );

        $this->assertSame(
            'https://api.openai.com/v1/embeddings',
            $this->readPrivate($generator, OpenAI3SmallEmbeddingGenerator::class, 'uri')
        );
    }//end testOpenAiWithoutBaseUrlKeepsLlphantDefaultUrl()

    public function testOpenAiWithoutApiKeyIsRefusedByLlphant(): void
    {
        $previous = getenv('OPENAI_API_KEY');
        putenv('OPENAI_API_KEY');
        try {
            $this->expectException(MissingParameterException::class);
            $this->handler->getGenerator(['provider' => 'openai', 'model' => 'text-embedding-3-small', 'api_key' => '']);
        } finally {
            if ($previous !== false) {
                putenv('OPENAI_API_KEY='.$previous);
            }
        }
    }//end testOpenAiWithoutApiKeyIsRefusedByLlphant()

    public function testOpenAiUnsupportedModelThrows(): void
    {
        $this->logger->expects($this->never())->method('info');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Unsupported OpenAI model: gpt-4o');
        $this->handler->getGenerator(['provider' => 'openai', 'model' => 'gpt-4o', 'api_key' => 'sk-test']);
    }//end testOpenAiUnsupportedModelThrows()

    public function testUnsupportedProviderThrowsAndCachesNothing(): void
    {
        $this->logger->expects($this->once())->method('debug');
        $this->logger->expects($this->never())->method('info');

        try {
            $this->handler->getGenerator(['provider' => 'mistral', 'model' => 'm']);
            $this->fail('An unsupported provider must throw.');
        } catch (Exception $e) {
            $this->assertSame('Unsupported embedding provider: mistral', $e->getMessage());
        }

        $this->assertSame([], $this->readPrivate($this->handler, EmbeddingGeneratorHandler::class, 'generatorCache'));
    }//end testUnsupportedProviderThrowsAndCachesNothing()

    public function testSameProviderAndModelIsServedFromCache(): void
    {
        $this->logger->expects($this->once())->method('debug');
        $this->logger->expects($this->once())->method('info');

        $first  = $this->handler->getGenerator(['provider' => 'openai', 'model' => 'text-embedding-3-small', 'api_key' => 'a']);
        $second = $this->handler->getGenerator(['provider' => 'openai', 'model' => 'text-embedding-3-small', 'api_key' => 'b']);

        $this->assertSame($first, $second);
        $this->assertSame('a', $second->apiKey);
    }//end testSameProviderAndModelIsServedFromCache()

    public function testDifferentModelsGetDifferentGenerators(): void
    {
        $small = $this->handler->getGenerator(['provider' => 'openai', 'model' => 'text-embedding-3-small', 'api_key' => 'a']);
        $large = $this->handler->getGenerator(['provider' => 'openai', 'model' => 'text-embedding-3-large', 'api_key' => 'a']);

        $this->assertNotSame($small, $large);
        $this->assertSame(3072, $large->getEmbeddingLength());
        $this->assertSame(1536, $small->getEmbeddingLength());
    }//end testDifferentModelsGetDifferentGenerators()

    public function testOllamaBuildsApiUrlFromTrimmedBaseUrlAndAsksShowForLength(): void
    {
        $this->logger->expects($this->once())
            ->method('info')
            ->with($this->anything(), $this->callback(static fn(array $ctx): bool => $ctx['dimensions'] === 512));

        $generator = $this->handler->getGenerator(
            ['provider' => 'ollama', 'model' => 'nomic-embed-text', 'base_url' => self::$base.'/ollama/']
        );

        $this->assertInstanceOf(OllamaEmbeddingGenerator::class, $generator);
        $this->assertSame(
            self::$base.'/ollama/api/',
            $this->readPrivate($generator, OllamaEmbeddingGenerator::class, 'baseUri')
        );
        $this->assertSame('nomic-embed-text', $this->readPrivate($generator, OllamaEmbeddingGenerator::class, 'model'));
        $requests = $this->requests();
        $this->assertCount(1, $requests);
        $this->assertSame('/ollama/api/show', $requests[0]['path']);
        $this->assertSame(['model' => 'nomic-embed-text'], $requests[0]['body']);
    }//end testOllamaBuildsApiUrlFromTrimmedBaseUrlAndAsksShowForLength()

    public function testOllamaWithoutBaseUrlDefaultsToLocalhost(): void
    {
        $generator = (fn(): OllamaEmbeddingGenerator => $this->createOllamaGenerator(model: 'all-minilm', config: []))
            ->call($this->handler);

        $this->assertSame(
            'http://localhost:11434/api/',
            $this->readPrivate($generator, OllamaEmbeddingGenerator::class, 'baseUri')
        );
    }//end testOllamaWithoutBaseUrlDefaultsToLocalhost()

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function fireworksLengthProvider(): array
    {
        return [
            'nomic'   => ['nomic-ai/nomic-embed-text-v1.5', 768],
            'gteBase' => ['thenlper/gte-base', 768],
            'gteLarg' => ['thenlper/gte-large', 1024],
            'uae'     => ['WhereIsAI/UAE-Large-V1', 1024],
            'other'   => ['acme/other-model', 768],
        ];
    }//end fireworksLengthProvider()

    #[DataProvider('fireworksLengthProvider')]
    public function testFireworksEmbeddingLengthPerModel(string $model, int $expected): void
    {
        $this->logger->expects($this->once())
            ->method('info')
            ->with($this->anything(), $this->callback(static fn(array $ctx): bool => $ctx['dimensions'] === $expected));

        $generator = $this->handler->getGenerator(['provider' => 'fireworks', 'model' => $model, 'api_key' => 'fw']);

        $this->assertSame($expected, $generator->getEmbeddingLength());
        $this->assertSame([], $this->requests());
    }//end testFireworksEmbeddingLengthPerModel()

    public function testFireworksEmbedTextPostsModelAndInputWithBearerKey(): void
    {
        $generator = $this->handler->getGenerator(
            ['provider' => 'fireworks', 'model' => 'thenlper/gte-base', 'api_key' => 'fw-key', 'base_url' => self::$base.'/ok/v1/']
        );

        $this->assertSame([0.25, 0.5, 0.75], $generator->embedText('hello world'));

        $requests = $this->requests();
        $this->assertCount(1, $requests);
        $this->assertSame('POST', $requests[0]['method']);
        $this->assertSame('/ok/v1/embeddings', $requests[0]['path']);
        $this->assertSame('Bearer fw-key', $requests[0]['auth']);
        $this->assertSame('application/json', $requests[0]['contentType']);
        $this->assertSame(['model' => 'thenlper/gte-base', 'input' => 'hello world'], $requests[0]['body']);
    }//end testFireworksEmbedTextPostsModelAndInputWithBearerKey()

    public function testFireworksNon200ResponseThrowsWithStatusAndBody(): void
    {
        $generator = $this->handler->getGenerator(
            ['provider' => 'fireworks', 'model' => 'm', 'api_key' => 'k', 'base_url' => self::$base.'/fail']
        );

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Fireworks API returned HTTP 500: stub failure');
        $generator->embedText('x');
    }//end testFireworksNon200ResponseThrowsWithStatusAndBody()

    public function testFireworksResponseWithoutEmbeddingThrows(): void
    {
        $generator = $this->handler->getGenerator(
            ['provider' => 'fireworks', 'model' => 'm', 'api_key' => 'k', 'base_url' => self::$base.'/badformat']
        );

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Unexpected Fireworks API response format: {"data":[]}');
        $generator->embedText('x');
    }//end testFireworksResponseWithoutEmbeddingThrows()

    public function testFireworksTransportErrorThrows(): void
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $port  = (int) substr((string) strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);

        $generator = $this->handler->getGenerator(
            ['provider' => 'fireworks', 'model' => 'm', 'api_key' => 'k', 'base_url' => 'http://127.0.0.1:'.$port]
        );

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Fireworks API request failed: ');
        $generator->embedText('x');
    }//end testFireworksTransportErrorThrows()

    public function testFireworksEmbedDocumentAndDocumentsFillEmbeddings(): void
    {
        $generator = $this->handler->getGenerator(
            ['provider' => 'fireworks', 'model' => 'm', 'api_key' => 'k', 'base_url' => self::$base.'/ok']
        );

        $one          = new Document();
        $one->content = 'first';
        $this->assertSame($one, $generator->embedDocument($one));
        $this->assertSame([0.25, 0.5, 0.75], $one->embedding);

        $a          = new Document();
        $a->content = 'a';
        $b          = new Document();
        $b->content = 'b';
        $result     = $generator->embedDocuments([$a, $b]);

        $this->assertSame([$a, $b], $result);
        $this->assertSame([0.25, 0.5, 0.75], $b->embedding);
        $this->assertSame(
            ['first', 'a', 'b'],
            array_map(static fn(array $r): string => $r['body']['input'], $this->requests())
        );
        $this->assertSame([], $generator->embedDocuments([]));
    }//end testFireworksEmbedDocumentAndDocumentsFillEmbeddings()
}//end class
