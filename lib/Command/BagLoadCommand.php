<?php

/**
 * OpenRegister BAG load command
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Command;

use InvalidArgumentException;
use OCA\OpenRegister\Service\Bag\BagBulkLoader;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ openregister:bag:load [--source=]`: load a BAG address extract into the bag register.
 *
 * Without --source the app config `bag_bulk_url` names the extract.
 *
 * @spec openspec/changes/bag-address-lookup-by-postcode/specs/bag-address-lookup/spec.md#requirement-the-bag-register-holds-the-national-address-set-req-bag-001
 */
class BagLoadCommand extends Command {

	/**
	 * The name an administrator types.
	 *
	 * @var string
	 */
	public const NAME = 'openregister:bag:load';

	/**
	 * The app config key naming the extract.
	 *
	 * @var string
	 */
	public const CONFIG_URL = 'bag_bulk_url';

	/**
	 * Constructor.
	 *
	 * The loader is resolved when the command runs, not when occ lists its
	 * commands: it depends on the object service, which is too heavy to build
	 * for every occ call.
	 *
	 * @param ContainerInterface $container Resolves the loader on execute.
	 * @param IAppConfig         $appConfig Holds the default source.
	 *
	 * @spec openspec/changes/bag-address-lookup-by-postcode/specs/bag-address-lookup/spec.md#requirement-the-bag-register-holds-the-national-address-set-req-bag-001
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Configure the command.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bag-address-lookup-by-postcode/specs/bag-address-lookup/spec.md#requirement-the-bag-register-holds-the-national-address-set-req-bag-001
	 */
	protected function configure(): void {
		$this->setName(name: self::NAME)
			->setDescription(description: 'Load a BAG address extract (CSV, GeoJSON or NDJSON) into the bag register')
			->addOption(
				name: 'source',
				shortcut: null,
				mode: InputOption::VALUE_REQUIRED,
				description: 'A file path or http(s) URL of the extract; defaults to the app config bag_bulk_url'
			);
	}//end configure()

	/**
	 * Load and print the counts.
	 *
	 * @param InputInterface  $input  Input.
	 * @param OutputInterface $output Output.
	 *
	 * @return int 0 on a load, 1 when the source cannot be read.
	 *
	 * @spec openspec/changes/bag-address-lookup-by-postcode/specs/bag-address-lookup/spec.md#requirement-the-bag-register-holds-the-national-address-set-req-bag-001
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$source = trim((string) ($input->getOption('source') ?? ''));
		if ($source === '') {
			$source = $this->appConfig->getValueString('openregister', self::CONFIG_URL, '');
		}

		try {
			$counts = $this->container->get(BagBulkLoader::class)->loadFrom(source: $source);
		} catch (InvalidArgumentException $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');

			return 1;
		}

		$output->writeln(
			sprintf(
				'Created %d, updated %d, withdrawn %d, unchanged %d, invalid %d, failed %d.',
				$counts['created'],
				$counts['updated'],
				$counts['withdrawn'],
				$counts['unchanged'],
				$counts['invalid'],
				$counts['failed']
			)
		);

		return 0;
	}//end execute()
}//end class
