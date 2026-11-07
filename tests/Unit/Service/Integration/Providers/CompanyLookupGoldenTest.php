<?php

/**
 * Golden record of the KvK and OpenCorporates company-lookup providers.
 *
 * list() over search/limit/page filters (and a router that throws, which
 * list() lets through), KvkProvider::lookupByKvkNumber() and both
 * searchCompanies() over clamped limits and pages, criteria, jurisdictions,
 * every response shape the row extraction knows, and the router throwing
 * ProviderUnavailableException or any other Throwable. Every router call
 * and log line is recorded. The recording lives in
 * fixtures/company-lookup-golden.json; set COMPANY_LOOKUP_GOLDEN_RECORD=1
 * to write it.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration\Providers
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
 * @spec exclude Pins the company-lookup leaves.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration\Providers;

use OCA\OpenRegister\Exception\ProviderUnavailableException;
use OCA\OpenRegister\Service\Integration\ExternalIntegrationRouterInterface;
use OCA\OpenRegister\Service\Integration\Providers\KvkProvider;
use OCA\OpenRegister\Service\Integration\Providers\OpenCorporatesProvider;
use OCP\App\IAppManager;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;

/**
 * The company-lookup leaves, recorded.
 *
 * @covers \OCA\OpenRegister\Service\Integration\Providers\KvkProvider
 * @covers \OCA\OpenRegister\Service\Integration\Providers\OpenCorporatesProvider
 */
class CompanyLookupGoldenTest extends TestCase {

	private const FIXTURE = __DIR__.'/fixtures/company-lookup-golden.json';

	/**
	 * Everything observed: router calls and log lines.
	 *
	 * @var list<mixed>
	 */
	private array $log = [];

	/**
	 * The constructor arguments, with a router answering $answer (a Throwable is thrown).
	 *
	 * @param mixed $answer The router's answer.
	 *
	 * @return array<int, object> The arguments.
	 */
	private function dependencies(mixed $answer): array {
		$router = $this->createMock(ExternalIntegrationRouterInterface::class);
		$router->method('call')->willReturnCallback(
			function (object $provider, string $method, string $path, array $options = []) use ($answer): array {
				$this->log[] = ['call', $provider->getId(), $method, $path, $options];
				if ($answer instanceof \Throwable) {
					throw $answer;
				}
				return $answer;
			}
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$log = &$this->log;
		$logger = new class($log) extends AbstractLogger {
			public function __construct(private array &$log) {
			}
			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->log[] = ['log', $level, (string)$message];
			}
		};
		return [$router, $this->createMock(IAppManager::class), $l10n, $logger];
	}//end dependencies()

	/**
	 * A KvK provider.
	 *
	 * @param mixed $answer The router's answer.
	 *
	 * @return KvkProvider The provider.
	 */
	private function kvk(mixed $answer): KvkProvider {
		return new KvkProvider(...$this->dependencies($answer));
	}//end kvk()

	/**
	 * An OpenCorporates provider.
	 *
	 * @param mixed $answer The router's answer.
	 *
	 * @return OpenCorporatesProvider The provider.
	 */
	private function oc(mixed $answer): OpenCorporatesProvider {
		return new OpenCorporatesProvider(...$this->dependencies($answer));
	}//end oc()

	/**
	 * Run every scenario and record it.
	 *
	 * @return array<string, mixed> The recording.
	 */
	private function recordAll(): array {
		$kvkRows = ['resultaten' => [['kvkNummer' => '1'], 'skip', ['kvkNummer' => '2']], 'totaal' => 2];
		$kvkList = [['kvkNummer' => '3'], 7, ['kvkNummer' => '4']];
		$kvkNotList = ['resultaten' => 'nope', 'x' => ['kvkNummer' => '5']];
		$ocRows = ['results' => ['companies' => [
			['company' => ['name' => 'Wrapped']],
			['company' => 'not-an-array', 'name' => 'Kept whole'],
			'skip',
			['name' => 'Bare'],
		]]];
		$ocList = [['name' => 'L1'], null, ['name' => 'L2']];
		$ocOther = ['results' => ['companies' => 'nope'], 'name' => 'x'];
		$down = new ProviderUnavailableException('down', ProviderUnavailableException::CAUSE_OPENCONNECTOR_DOWN);
		$boom = new RuntimeException('boom');

		$scenarios = [
			'kvk list no filters' => fn () => $this->kvk($kvkRows)->list('r', 's', 'o'),
			'kvk list all filters' => fn () => $this->kvk($kvkList)->list('r', 's', 'o', ['_search' => 'Acme', '_limit' => '500', '_page' => '3']),
			'kvk list small limit, zero page' => fn () => $this->kvk($kvkNotList)->list('r', 's', 'o', ['_search' => '', '_limit' => 5, '_page' => 0]),
			'kvk list router throws' => fn () => $this->kvk($boom)->list('r', 's', 'o', ['_search' => 'x']),
			'kvk lookup blank' => fn () => $this->kvk($kvkRows)->lookupByKvkNumber('   '),
			'kvk lookup hit' => fn () => $this->kvk($kvkRows)->lookupByKvkNumber(' 12345678 '),
			'kvk lookup list response' => fn () => $this->kvk($kvkList)->lookupByKvkNumber('1'),
			'kvk lookup unavailable' => fn () => $this->kvk($down)->lookupByKvkNumber('1'),
			'kvk lookup throws' => fn () => $this->kvk($boom)->lookupByKvkNumber('1'),
			'kvk search defaults' => fn () => $this->kvk($kvkRows)->searchCompanies('  Acme  '),
			'kvk search criteria and clamps' => fn () => $this->kvk($kvkList)->searchCompanies('', ['plaats' => 'Utrecht', 'naam' => 'old', 'aantal' => '9'], 500, 0),
			'kvk search criteria naam overwritten' => fn () => $this->kvk($kvkNotList)->searchCompanies('New', ['naam' => 'old'], 0, 4),
			'kvk search unavailable' => fn () => $this->kvk($down)->searchCompanies('x', [], 10, 2),
			'kvk search throws' => fn () => $this->kvk($boom)->searchCompanies('x', [], -5, -1),
			'oc list no filters' => fn () => $this->oc($ocRows)->list('r', 's', 'o'),
			'oc list all filters' => fn () => $this->oc($ocList)->list('r', 's', 'o', ['_search' => 'Acme', '_limit' => 150, '_page' => '2']),
			'oc list other shape' => fn () => $this->oc($ocOther)->list('r', 's', 'o', ['_limit' => -1, '_page' => 'x']),
			'oc list router throws' => fn () => $this->oc($down)->list('r', 's', 'o'),
			'oc search defaults' => fn () => $this->oc($ocRows)->searchCompanies(' Acme '),
			'oc search jurisdiction' => fn () => $this->oc($ocList)->searchCompanies('', ' nl ', 500, 0),
			'oc search blank jurisdiction' => fn () => $this->oc($ocOther)->searchCompanies('x', '   ', 0, 3),
			'oc search unavailable' => fn () => $this->oc($down)->searchCompanies('x', null, 10, 2),
			'oc search throws' => fn () => $this->oc($boom)->searchCompanies('x', 'gb', -5, -1),
		];

		$recording = [];
		foreach ($scenarios as $name => $run) {
			$this->log = [];
			try {
				$result = $run();
			} catch (\Throwable $e) {
				$result = ['threw' => get_class($e), 'message' => $e->getMessage()];
			}
			$recording[$name] = ['result' => $result, 'observed' => $this->log];
		}
		return $recording;
	}//end recordAll()

	/**
	 * Every scenario matches the recording.
	 *
	 * @return void
	 */
	public function testMatchesTheGoldenRecord(): void {
		$recording = $this->recordAll();
		if (getenv('COMPANY_LOOKUP_GOLDEN_RECORD') === '1') {
			file_put_contents(self::FIXTURE, json_encode($recording, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
		}
		$expected = json_decode((string)file_get_contents(self::FIXTURE), true);
		$this->assertSame($expected, json_decode((string)json_encode($recording), true));
	}//end testMatchesTheGoldenRecord()
}//end class
