<?php

/**
 * TmloService validation golden tests
 *
 * Pins the exact error lists validateFieldValues() and validateStatusTransition()
 * return for every branch, so a refactor cannot change them.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\Edepot\MdtoXmlGenerator;
use OCA\OpenRegister\Service\TmloService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden tests for the TMLO validators.
 */
class TmloServiceValidationGoldenTest extends TestCase {

	/**
	 * The service under test.
	 *
	 * @var TmloService
	 */
	private TmloService $service;

	/**
	 * Build the service.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->service = new TmloService(
			$this->createMock(LoggerInterface::class),
			$this->createMock(MdtoXmlGenerator::class)
		);
	}//end setUp()

	/**
	 * Field-value cases: input and the exact error list.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: list<string>}>
	 */
	public static function fieldValueCases(): array {
		return [
			'empty'                 => [[], []],
			'all valid'             => [
				[
					'archiefnominatie'  => 'vernietigen',
					'archiefstatus'     => 'actief',
					'bewaarTermijn'     => 'P5Y6M',
					'archiefactiedatum' => '2030-02-28',
				],
				[],
			],
			'null values are unset' => [
				[
					'archiefnominatie'  => null,
					'archiefstatus'     => null,
					'bewaarTermijn'     => null,
					'archiefactiedatum' => null,
				],
				[],
			],
			'all invalid'           => [
				[
					'archiefnominatie'  => 'bogus',
					'archiefstatus'     => 'weg',
					'bewaarTermijn'     => '7 jaar',
					'archiefactiedatum' => '2030-02-30',
				],
				[
					'archiefnominatie must be one of: blijvend_bewaren, vernietigen. Got: bogus',
					'archiefstatus must be one of: actief, semi_statisch, overgebracht, vernietigd. Got: weg',
					'bewaarTermijn must be a valid ISO-8601 duration (e.g., P7Y, P5Y6M). Got: 7 jaar',
					'archiefactiedatum must be a valid ISO-8601 date (YYYY-MM-DD). Got: 2030-02-30',
				],
			],
			'unparseable date'      => [
				['archiefactiedatum' => 'morgen'],
				['archiefactiedatum must be a valid ISO-8601 date (YYYY-MM-DD). Got: morgen'],
			],
		];
	}//end fieldValueCases()

	/**
	 * Every field-value branch returns exactly the pinned errors.
	 *
	 * @param array<string, mixed> $tmlo     Input
	 * @param list<string>         $expected Exact error list
	 *
	 * @dataProvider fieldValueCases
	 *
	 * @return void
	 */
	public function testFieldValues(array $tmlo, array $expected): void {
		$this->assertSame($expected, $this->service->validateFieldValues($tmlo));
	}//end testFieldValues()

	/**
	 * Transition cases: input, old status and the exact error list.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string, 2: list<string>}>
	 */
	public static function transitionCases(): array {
		$transfer = [
			'archiefstatus'     => 'overgebracht',
			'archiefactiedatum' => '2030-01-01',
			'classification'    => '1.1',
			'archiefnominatie'  => 'blijvend_bewaren',
		];
		$destroy  = [
			'archiefstatus'          => 'vernietigd',
			'archiefactiedatum'      => '2030-01-01',
			'classification'         => '1.1',
			'archiefnominatie'       => 'vernietigen',
			'vernietigingsCategorie' => 'A',
		];
		return [
			'no new status'             => [[], 'actief', []],
			'same status'               => [['archiefstatus' => 'actief'], 'actief', []],
			'allowed simple'            => [['archiefstatus' => 'semi_statisch'], 'actief', []],
			'not allowed'               => [
				['archiefstatus' => 'overgebracht'],
				'actief',
				["Transition from 'actief' to 'overgebracht' is not allowed. Allowed transitions from 'actief': semi_statisch"],
			],
			'not allowed two targets'   => [
				['archiefstatus' => 'actief'],
				'semi_statisch',
				["Transition from 'semi_statisch' to 'actief' is not allowed. Allowed transitions from 'semi_statisch': overgebracht, vernietigd"],
			],
			'terminal state'            => [
				['archiefstatus' => 'actief'],
				'vernietigd',
				["Transition from 'vernietigd' to 'actief' is not allowed. Allowed transitions from 'vernietigd': none (terminal state)"],
			],
			'unknown old status'        => [
				['archiefstatus' => 'actief'],
				'mystery',
				["Transition from 'mystery' to 'actief' is not allowed. Allowed transitions from 'mystery': none (terminal state)"],
			],
			'transfer complete'         => [$transfer, 'semi_statisch', []],
			'transfer missing all'      => [
				['archiefstatus' => 'overgebracht', 'classification' => ''],
				'semi_statisch',
				[
					"Field 'archiefactiedatum' is required for transition to 'overgebracht'",
					"Field 'classification' is required for transition to 'overgebracht'",
					"Field 'archiefnominatie' is required for transition to 'overgebracht'",
					"archiefnominatie must be 'blijvend_bewaren' for transition to 'overgebracht'",
				],
			],
			'transfer wrong nominatie'  => [
				array_merge($transfer, ['archiefnominatie' => 'vernietigen']),
				'semi_statisch',
				["archiefnominatie must be 'blijvend_bewaren' for transition to 'overgebracht'"],
			],
			'destroy complete'          => [$destroy, 'semi_statisch', []],
			'destroy missing all'       => [
				['archiefstatus' => 'vernietigd', 'archiefactiedatum' => ''],
				'semi_statisch',
				[
					"Field 'archiefactiedatum' is required for transition to 'vernietigd'",
					"Field 'classification' is required for transition to 'vernietigd'",
					"Field 'archiefnominatie' is required for transition to 'vernietigd'",
					"Field 'vernietigingsCategorie' is required for transition to 'vernietigd'",
					"archiefnominatie must be 'vernietigen' for transition to 'vernietigd'",
				],
			],
			'destroy wrong nominatie'   => [
				array_merge($destroy, ['archiefnominatie' => 'blijvend_bewaren']),
				'semi_statisch',
				["archiefnominatie must be 'vernietigen' for transition to 'vernietigd'"],
			],
			'destroy zero is present'   => [
				array_merge($destroy, ['vernietigingsCategorie' => 0]),
				'semi_statisch',
				[],
			],
		];
	}//end transitionCases()

	/**
	 * Every transition branch returns exactly the pinned errors.
	 *
	 * @param array<string, mixed> $tmlo      Input
	 * @param string               $oldStatus Current status
	 * @param list<string>         $expected  Exact error list
	 *
	 * @dataProvider transitionCases
	 *
	 * @return void
	 */
	public function testStatusTransition(array $tmlo, string $oldStatus, array $expected): void {
		$this->assertSame($expected, $this->service->validateStatusTransition($tmlo, $oldStatus));
	}//end testStatusTransition()
}//end class
