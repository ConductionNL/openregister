<?php

/**
 * OpenRegister Resolver List Command
 *
 * OCC command for listing configured register/schema keys for an app.
 *
 * @category Command
 * @package  OCA\OpenRegister\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/register-resolver-service/tasks.md#task-3.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Command;

use OCA\OpenRegister\Service\RegisterResolverService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * OCC command that prints all configured register/schema keys for a given app.
 *
 * Usage:
 *   php occ openregister:resolver:list <app-id>
 *
 * Example:
 *   php occ openregister:resolver:list opencatalogi
 *
 * @category Command
 * @package  OCA\OpenRegister\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */
class ResolverListCommand extends Command
{
    /**
     * Constructor.
     *
     * @param RegisterResolverService $resolverService The register resolver service.
     *
     * @return void
     *
     * @spec openspec/changes/register-resolver-service/tasks.md#task-3.2
     */
    public function __construct(
        private readonly RegisterResolverService $resolverService,
    ) {
        parent::__construct();
    }//end __construct()

    /**
     * Configure the command.
     *
     * @return void
     *
     * @spec openspec/changes/register-resolver-service/tasks.md#task-3.2
     */
    protected function configure(): void
    {
        $this->setName(name: 'openregister:resolver:list')
            ->setDescription('List all configured register/schema keys for a given app')
            ->addArgument(
                name: 'app-id',
                mode: InputArgument::REQUIRED,
                description: 'The app ID to enumerate (e.g. opencatalogi, pipelinq)'
            )
            ->setHelp(
                '<info>List all <context>_register and <context>_schema config keys for an app.</info>

<comment>Usage:</comment>
  <info>php occ openregister:resolver:list opencatalogi</info>
    Print all register/schema config keys configured for the opencatalogi app.

<comment>Output columns:</comment>
  Config Key — the IAppConfig key (e.g. theme_register, listing_schema)
  Value      — the configured slug or UUID
'
            );
    }//end configure()

    /**
     * Execute the command.
     *
     * @param InputInterface  $input  Console input.
     * @param OutputInterface $output Console output.
     *
     * @return int Command exit code.
     *
     * @spec openspec/changes/register-resolver-service/tasks.md#task-3.2
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $appId = $input->getArgument('app-id');

        $output->writeln('');
        $output->writeln('<info>OpenRegister Resolver Config — app: '.$appId.'</info>');
        $output->writeln(str_repeat('-', 60));

        $configs = $this->resolverService->enumerateAppConfigs(appId: $appId);

        if (count($configs) === 0) {
            $output->writeln('<comment>No register/schema config keys found for app "'.$appId.'".</comment>');
            $output->writeln('');
            return self::SUCCESS;
        }

        $maxKeyLen = max(array_map('strlen', array_keys($configs)));
        $maxKeyLen = max($maxKeyLen, 10);

        $output->writeln(sprintf('  %-'.$maxKeyLen.'s  %s', 'Config Key', 'Value'));
        $output->writeln(sprintf('  %-'.$maxKeyLen.'s  %s', str_repeat('-', $maxKeyLen), str_repeat('-', 36)));

        foreach ($configs as $key => $value) {
            $output->writeln(sprintf('  %-'.$maxKeyLen.'s  <comment>%s</comment>', $key, $value));
        }

        $output->writeln('');
        $output->writeln('<info>Total: '.count($configs).' key(s)</info>');
        $output->writeln('');

        return self::SUCCESS;
    }//end execute()
}//end class
