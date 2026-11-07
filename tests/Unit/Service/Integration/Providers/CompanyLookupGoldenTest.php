<?php

/**
 * Golden tests of the company lookups: what KvK and OpenCorporates are asked and what they answer.
 *
 * @category Test
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
 * @spec openspec/changes/archive/2026-10-05-integration-kvk-opencorporates/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration\Providers;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- test method names + arrange/act/assert structure make intent obvious; matches KvkProviderTest.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers take positional args by convention.

use OCA\OpenRegister\Exception\ProviderUnavailableException;
use OCA\OpenRegister\Service\Integration\ExternalIntegrationRouter;
use OCA\OpenRegister\Service\Integration\Providers\CompanySearch;
use OCA\OpenRegister\Service\Integration\Providers\KvkProvider;
use OCA\OpenRegister\Service\Integration\Providers\OpenCorporatesProvider;
use OCP\App\IAppManager;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins requests, answers and degraded answers of both company lookups.
 */
final class CompanyLookupGoldenTest extends TestCase {

	/**
	 * The calls the router received: path and options.
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $calls = [];

	/**
	 * What was logged.
	 *
	 * @var array<int, string>
	 */
	private array $logged = [];

	/**
	 * Router, app manager, l10n and logger over a canned answer or failure.
	 *
	 * @param array<string, mixed>|\Throwable $answer The answer.
	 *
	 * @return array<string, mixed> Named constructor arguments.
	 */
	private function deps(array|\Throwable $answer): array {
		$router = $this->createMock(ExternalIntegrationRouter::class);
		$router->method('call')->willReturnCallback(
			function ($provider, string $method, string $path, array $options) use ($answer): array {
				$this->calls[] = [$method . ' ' . $path, $options];
				if ($answer instanceof \Throwable) {
					throw $answer;
				}

				return $answer;
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message): void {
				$this->logged[] = $message;
			}
		);

		return [
			'router' => $router,
			'appManager' => $this->createMock(IAppManager::class),
			'l10n' => $this->createMock(IL10N::class),
			'logger' => $logger,
		];
	}//end deps()

	private function kvk(array|\Throwable $answer = []): KvkProvider {
		return new KvkProvider(...$this->deps($answer));
	}//end kvk()

	private function openCorporates(array|\Throwable $answer = []): OpenCorporatesProvider {
		return new OpenCorporatesProvider(...$this->deps($answer));
	}//end openCorporates()

	private function kvkSearch(KvkProvider $provider, string $query, array $criteria, int $limit, int $page): array {
		return $provider->searchCompanies((new CompanySearch($query, $limit, $page))->narrowedBy($criteria));
	}//end kvkSearch()

	private function openCorporatesSearch(OpenCorporatesProvider $provider, string $query, ?string $area, int $limit, int $page): array {
		return $provider->searchCompanies((new CompanySearch($query, $limit, $page))->narrowedBy(['jurisdiction' => $area]));
	}//end openCorporatesSearch()

	private function options(array $query): array {
		return ['query' => $query, 'headers' => ['Accept' => 'application/json']];
	}//end options()

	public static function kvkListCases(): array {
		return [
			'nothing' => [[], []],
			'everything' => [
				['_page' => 3, '_limit' => 5, '_search' => 'Acme'],
				['naam' => 'Acme', 'aantal' => '5', 'pagina' => '3'],
			],
			'limit capped' => [['_limit' => 500], ['aantal' => '100']],
			'zero and negative are left out' => [['_limit' => 0, '_page' => -1, '_search' => ''], []],
		];
	}//end kvkListCases()

	#[DataProvider('kvkListCases')]
	public function testKvkListAsks(array $filters, array $query): void {
		$this->kvk()->list('', '', '', $filters);

		$this->assertSame([['GET zoeken', $this->options($query)]], $this->calls);
	}//end testKvkListAsks()

	public static function openCorporatesListCases(): array {
		return [
			'nothing' => [[], []],
			'everything' => [
				['_page' => 3, '_limit' => 5, '_search' => 'Acme'],
				['q' => 'Acme', 'per_page' => '5', 'page' => '3'],
			],
			'limit capped' => [['_limit' => 500], ['per_page' => '100']],
			'zero and negative are left out' => [['_limit' => 0, '_page' => -1, '_search' => ''], []],
		];
	}//end openCorporatesListCases()

	#[DataProvider('openCorporatesListCases')]
	public function testOpenCorporatesListAsks(array $filters, array $query): void {
		$this->openCorporates()->list('', '', '', $filters);

		$this->assertSame([['GET companies/search', $this->options($query)]], $this->calls);
	}//end testOpenCorporatesListAsks()

	public static function kvkRowCases(): array {
		return [
			'wrapped' => [['resultaten' => [['a' => 1], 'junk', ['b' => 2]]], [['a' => 1], ['b' => 2]]],
			'bare list' => [[['a' => 1], 'junk', ['b' => 2]], [['a' => 1], ['b' => 2]]],
			'other envelope' => [['pagina' => 1, 'totaal' => 2], []],
			'wrapper is no list' => [['resultaten' => 'none'], []],
			'empty' => [[], []],
		];
	}//end kvkRowCases()

	#[DataProvider('kvkRowCases')]
	public function testKvkRows(array $answer, array $rows): void {
		$this->assertSame($rows, $this->kvk($answer)->list('', '', '', []));
	}//end testKvkRows()

	public static function openCorporatesRowCases(): array {
		return [
			'wrapped' => [
				['results' => ['companies' => [['company' => ['a' => 1]], 'junk', ['b' => 2], ['company' => 'flat']]]],
				[['a' => 1], ['b' => 2], ['company' => 'flat']],
			],
			'bare list' => [[['a' => 1], 'junk'], [['a' => 1]]],
			'other envelope' => [['results' => ['total' => 0]], []],
			'empty' => [[], []],
		];
	}//end openCorporatesRowCases()

	#[DataProvider('openCorporatesRowCases')]
	public function testOpenCorporatesRows(array $answer, array $rows): void {
		$this->assertSame($rows, $this->openCorporates($answer)->list('', '', '', []));
	}//end testOpenCorporatesRows()

	public function testKvkLookupAsksByNumber(): void {
		$result = $this->kvk(['resultaten' => [['kvkNummer' => '1']]])->lookupByKvkNumber(' 123 ');

		$this->assertSame([['GET zoeken', $this->options(['kvkNummer' => '123'])]], $this->calls);
		$this->assertSame(['results' => [['kvkNummer' => '1']], 'total' => 1], $result);
	}//end testKvkLookupAsksByNumber()

	public function testKvkLookupOfNothingAsksNothing(): void {
		$this->assertSame(['results' => [], 'total' => 0], $this->kvk()->lookupByKvkNumber('  '));
		$this->assertSame([], $this->calls);
	}//end testKvkLookupOfNothingAsksNothing()

	public function testKvkLookupDegrades(): void {
		$missing = new ProviderUnavailableException('m', ProviderUnavailableException::CAUSE_OPENCONNECTOR_SOURCE_MISSING);

		$this->assertSame(
			['unavailable' => true, 'cause' => 'openconnector-source-missing', 'results' => [], 'total' => 0],
			$this->kvk($missing)->lookupByKvkNumber('1')
		);
		$this->assertSame([], $this->logged);

		$this->assertSame(
			['unavailable' => true, 'cause' => 'upstream-service-down', 'results' => [], 'total' => 0],
			$this->kvk(new RuntimeException('boom'))->lookupByKvkNumber('1')
		);
		$this->assertSame(['KvkProvider::lookupByKvkNumber failed: boom'], $this->logged);
	}//end testKvkLookupDegrades()

	public function testKvkSearchAsksAndAnswers(): void {
		$result = $this->kvkSearch($this->kvk(['resultaten' => [['a' => 1]]]), ' Acme ', ['plaats' => 'Utrecht'], 0, -2);

		$this->assertSame(
			[['GET zoeken', $this->options(['plaats' => 'Utrecht', 'naam' => 'Acme', 'aantal' => '1', 'pagina' => '1'])]],
			$this->calls
		);
		$this->assertSame(['results' => [['a' => 1]], 'total' => 1, 'limit' => 1, 'page' => 1], $result);
	}//end testKvkSearchAsksAndAnswers()

	public function testKvkSearchWithoutTextOmitsTheName(): void {
		$this->kvkSearch($this->kvk(), '  ', [], 500, 2);

		$this->assertSame([['GET zoeken', $this->options(['aantal' => '100', 'pagina' => '2'])]], $this->calls);
	}//end testKvkSearchWithoutTextOmitsTheName()

	public function testKvkSearchDegrades(): void {
		$missing = new ProviderUnavailableException('m', ProviderUnavailableException::CAUSE_OPENCONNECTOR_SOURCE_MISSING);

		$this->assertSame(
			[
				'unavailable' => true,
				'cause' => 'openconnector-source-missing',
				'results' => [],
				'total' => 0,
				'limit' => 7,
				'page' => 2,
			],
			$this->kvkSearch($this->kvk($missing), 'x', [], 7, 2)
		);
		$this->assertSame(
			[
				'unavailable' => true,
				'cause' => 'upstream-service-down',
				'results' => [],
				'total' => 0,
				'limit' => 100,
				'page' => 1,
			],
			$this->kvkSearch($this->kvk(new RuntimeException('boom')), 'x', [], 999, 0)
		);
		$this->assertSame(['KvkProvider::searchCompanies failed: boom'], $this->logged);
	}//end testKvkSearchDegrades()

	public function testOpenCorporatesSearchAsksAndAnswers(): void {
		$answer = ['results' => ['companies' => [['company' => ['a' => 1]]]]];
		$result = $this->openCorporatesSearch($this->openCorporates($answer), ' Acme ', ' nl ', 0, -2);

		$this->assertSame(
			[
				[
					'GET companies/search',
					$this->options(
						['per_page' => '1', 'page' => '1', 'order' => 'score', 'q' => 'Acme', 'jurisdiction_code' => 'nl']
					),
				],
			],
			$this->calls
		);
		$this->assertSame(['results' => [['a' => 1]], 'total' => 1, 'limit' => 1, 'page' => 1], $result);
	}//end testOpenCorporatesSearchAsksAndAnswers()

	public static function idleAreaCases(): array {
		return [
			'none' => [null],
			'blank' => ['  '],
		];
	}//end idleAreaCases()

	#[DataProvider('idleAreaCases')]
	public function testOpenCorporatesSearchWithoutTextOrArea(?string $area): void {
		$this->openCorporatesSearch($this->openCorporates(), ' ', $area, 500, 2);

		$this->assertSame(
			[['GET companies/search', $this->options(['per_page' => '100', 'page' => '2', 'order' => 'score'])]],
			$this->calls
		);
	}//end testOpenCorporatesSearchWithoutTextOrArea()

	public function testOpenCorporatesSearchDegrades(): void {
		$missing = new ProviderUnavailableException('m', ProviderUnavailableException::CAUSE_OPENCONNECTOR_SOURCE_MISSING);

		$this->assertSame(
			[
				'unavailable' => true,
				'cause' => 'openconnector-source-missing',
				'results' => [],
				'total' => 0,
				'limit' => 7,
				'page' => 2,
			],
			$this->openCorporatesSearch($this->openCorporates($missing), 'x', null, 7, 2)
		);
		$this->openCorporatesSearch($this->openCorporates(new RuntimeException('boom')), 'x', null, 7, 2);
		$this->assertSame(['OpenCorporatesProvider::searchCompanies failed: boom'], $this->logged);
	}//end testOpenCorporatesSearchDegrades()
}//end class
