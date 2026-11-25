<?php

/**
 * OpenRegister Evaluate Detector Command
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Command
 * @package   OCA\OpenRegister\Command
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 *
 * @spec openspec/changes/anonymisation-discloses-itself/specs/anonymisation-disclosure/spec.md#requirement-precision-and-recall-are-measured-over-a-named-corpus-and-published-with-the-detector-version-req-adi-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Command;

use InvalidArgumentException;
use OCA\OpenRegister\Service\Anonymisation\DetectorEvaluationService;
use OCA\OpenRegister\Service\Anonymisation\EvaluationCorpus;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ openregister:anonymisation:evaluate`: run a detection backend over the
 * evaluation corpus and store precision, recall and support per entity type
 * with the corpus and the detector version (REQ-ADI-002).
 *
 * The service is fetched when the command runs, not when occ starts, so the
 * anonymisation services do not join every occ bootstrap.
 *
 * @category Command
 * @package  OCA\OpenRegister\Command
 *
 * @spec openspec/changes/anonymisation-discloses-itself/specs/anonymisation-disclosure/spec.md#requirement-precision-and-recall-are-measured-over-a-named-corpus-and-published-with-the-detector-version-req-adi-002
 */
class EvaluateDetectorCommand extends Command {

	/**
	 * The command name.
	 */
	public const NAME = 'openregister:anonymisation:evaluate';

	/**
	 * The corpus shipped with the app, relative to the app directory.
	 */
	public const DEFAULT_CORPUS = 'tests/Fixtures/anonymisation-eval/nl-woo-synthetic';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Serves the evaluation service when the command runs.
	 *
	 * @spec openspec/changes/anonymisation-discloses-itself/specs/anonymisation-disclosure/spec.md#requirement-precision-and-recall-are-measured-over-a-named-corpus-and-published-with-the-detector-version-req-adi-002
	 */
	public function __construct(
		private readonly ContainerInterface $container,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Name, description and options.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/anonymisation-discloses-itself/specs/anonymisation-disclosure/spec.md#requirement-precision-and-recall-are-measured-over-a-named-corpus-and-published-with-the-detector-version-req-adi-002
	 */
	protected function configure(): void {
		$this->setName(name: self::NAME)
			->setDescription(
				description: 'Measure precision and recall of a detection backend over an annotated corpus '
					. 'and store them with the detector version'
			)
			->addOption(
				name: 'corpus',
				shortcut: null,
				mode: InputOption::VALUE_REQUIRED,
				description: 'The corpus directory (holding corpus.json); defaults to the shipped nl-woo-synthetic corpus'
			)
			->addOption(
				name: 'method',
				shortcut: null,
				mode: InputOption::VALUE_REQUIRED,
				description: 'The backend to measure (regex, presidio, openanonymiser, llm, hybrid); defaults to the effective one'
			)
			->addOption(name: 'json', shortcut: null, mode: InputOption::VALUE_NONE, description: 'Print the result as JSON');
	}//end configure()

	/**
	 * Run the evaluation and print the figures.
	 *
	 * @param InputInterface  $input  The input.
	 * @param OutputInterface $output The output.
	 *
	 * @return int 0 when measured, 1 when the corpus or the backend is refused.
	 *
	 * @spec openspec/changes/anonymisation-discloses-itself/specs/anonymisation-disclosure/spec.md#requirement-precision-and-recall-are-measured-over-a-named-corpus-and-published-with-the-detector-version-req-adi-002
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$service = $this->container->get(DetectorEvaluationService::class);
		$method  = (string) ($input->getOption('method') ?? '');
		if ($method === '') {
			$method = $service->effectiveMethod();
		}

		try {
			$corpus     = EvaluationCorpus::fromDirectory(directory: $this->corpusDirectory(option: $input->getOption('corpus')));
			$evaluation = $service->evaluate(corpus: $corpus, method: $method);
		} catch (InvalidArgumentException $exception) {
			$output->writeln('<error>' . $exception->getMessage() . '</error>');

			return 1;
		}

		if ($input->getOption('json') === true) {
			$output->writeln((string) json_encode($evaluation, JSON_PRESERVE_ZERO_FRACTION));

			return 0;
		}

		$output->writeln(sprintf('%s over %s version %s, measured %s:', $method, $corpus->name, $corpus->version, $evaluation->measuredAt));
		foreach ($evaluation->types as $type => $figures) {
			$output->writeln(
				sprintf(
					'  %-13s precision %s  recall %s  support %d',
					$type,
					self::share(value: $figures['precision']),
					self::share(value: $figures['recall']),
					$figures['support']
				)
			);
		}

		return 0;
	}//end execute()

	/**
	 * The corpus directory: the option, else the shipped corpus.
	 *
	 * @param mixed $option The --corpus option.
	 *
	 * @return string
	 */
	private function corpusDirectory(mixed $option): string {
		if (is_string($option) === true && $option !== '') {
			return $option;
		}

		return dirname(__DIR__, 2) . '/' . self::DEFAULT_CORPUS;
	}//end corpusDirectory()

	/**
	 * A share as a percentage, or "n/a" when there is none.
	 *
	 * @param mixed $value The share, 0 to 1, or null.
	 *
	 * @return string
	 */
	private static function share(mixed $value): string {
		if (is_float($value) === false && is_int($value) === false) {
			return 'n/a';
		}

		return sprintf('%.1f%%', ($value * 100));
	}//end share()
}//end class
