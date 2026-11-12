<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\OpenRegister\Tests\Unit\Service;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Service\ConfigurationService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use Throwable;

/**
 * Records the fetch, mapper update, log lines and result of checkRemoteVersion(), per scenario,
 * against fixtures/configuration-check-remote-version-golden.json. Set UPDATE_GOLDEN=1 to rewrite it.
 *
 * @covers \OCA\OpenRegister\Service\ConfigurationService
 * @uses   \OCA\OpenRegister\Service\Configuration\FetchHandler
 * @uses   \OCA\OpenRegister\Db\Configuration
 */
class ConfigurationServiceCheckRemoteVersionGoldenTest extends TestCase {

	/**
	 * @var list<array>
	 */
	private array $calls = [];

	/**
	 * Builds the service for one scenario.
	 *
	 * @param array $s Scenario settings.
	 *
	 * @return ConfigurationService
	 */
	private function service(array $s): ConfigurationService {
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(function ($message, array $context = []) use ($level) {
				unset($context['file'], $context['line']);
				$this->calls[] = ['log.' . $level, $message, $context];
			});
		}

		$client = $this->createMock(Client::class);
		$client->method('request')->willReturnCallback(function ($method, $url) use ($s) {
			$this->calls[] = ['http', $method, $url];
			if ($s['http'] === 'down') {
				throw new ConnectException('refused', new Request('GET', $url));
			}
			return new Response(200, ['Content-Type' => 'application/json'], $s['http']);
		});

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnMap([[Client::class, $client], [LoggerInterface::class, $logger]]);

		$mapper = $this->createMock(ConfigurationMapper::class);
		$mapper->method('update')->willReturnCallback(function (Configuration $configuration) use ($s) {
			$this->calls[] = ['update', $configuration->getRemoteVersion(), $configuration->getLastChecked() !== null];
			if ($s['updateFails'] === true) {
				throw new Exception('database gone');
			}
			return $configuration;
		});

		$given = ['logger' => $logger, 'container' => $container, 'configurationMapper' => $mapper, 'appDataPath' => '/tmp'];
		$build = function (string $class) use (&$build, $given): object {
			$args = [];
			foreach ((new ReflectionMethod($class, '__construct'))->getParameters() as $param) {
				$name = $param->getName();
				$type = $param->getType()->getName();
				if (isset($given[$name]) === true) {
					$args[$name] = $given[$name];
					continue;
				}
				if ((new \ReflectionClass($type))->isFinal() === true) {
					$args[$name] = $build($type);
					continue;
				}
				$args[$name] = $this->createMock($type);
			}
			return new $class(...$args);
		};
		return $build(ConfigurationService::class);
	}

	/**
	 * Scenario table.
	 *
	 * @return array<string, array>
	 */
	public static function scenarios(): array {
		$base = ['type' => 'url', 'url' => 'https://example.org/c.json', 'http' => '{"version":"1.2.0"}', 'updateFails' => false];
		return [
			'top-level version' => [$base],
			'version under info' => [['http' => '{"info":{"version":"2.0.1"}}'] + $base],
			'top-level wins over info' => [['http' => '{"version":"3","info":{"version":"4"}}'] + $base],
			'no version' => [['http' => '{"title":"x"}'] + $base],
			'unparseable body' => [['http' => 'not json {'] + $base],
			'remote down' => [['http' => 'down'] + $base],
			'update fails' => [['updateFails' => true] + $base],
			'github source' => [['type' => 'github'] + $base],
			'local source' => [['type' => 'local'] + $base],
			'no url' => [['url' => ''] + $base],
			'url is zero' => [['url' => '0'] + $base],
		];
	}

	/**
	 * @dataProvider scenarios
	 */
	public function testCheckRemoteVersionGolden(array $s): void {
		$configuration = new Configuration();
		$configuration->setId(42);
		$configuration->setSourceType($s['type']);
		$configuration->setSourceUrl($s['url']);
		try {
			$this->calls[] = ['result', $this->service($s)->checkRemoteVersion($configuration)];
		} catch (Throwable $e) {
			$this->calls[] = ['thrown', get_class($e), $e->getMessage()];
		}

		$file = __DIR__ . '/fixtures/configuration-check-remote-version-golden.json';
		$name = $this->dataName();
		$all = is_file($file) === true ? json_decode(file_get_contents($file), true) : [];
		$actual = json_decode(json_encode($this->calls, JSON_UNESCAPED_UNICODE), true);
		if (getenv('UPDATE_GOLDEN') === '1') {
			$all[$name] = $actual;
			file_put_contents($file, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
		}
		$this->assertSame($all[$name], $actual);
	}
}
