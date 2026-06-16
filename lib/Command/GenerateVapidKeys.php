<?php

/**
 * OpenRegister web-push:generate-vapid command
 *
 * Generates a VAPID (Voluntary Application Server Identification) keypair used
 * to sign every Web Push payload, and persists it in app config
 * (`vapid_public_key` / `vapid_private_key`). The public key is exposable to
 * the browser (it is the `applicationServerKey` the client subscribes with);
 * the private key never leaves the server. Re-running rotates the keypair —
 * existing subscriptions signed with the old key then fail and are pruned on
 * 404/410 (see WebPushService).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
 * @spec openspec/changes/openregister-web-push-engine/specs/web-push-delivery/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Command;

use Minishlink\WebPush\VAPID;
use OCA\OpenRegister\Service\WebPush\WebPushService;
use OCP\IAppConfig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Generate + store the instance VAPID keypair for Web Push.
 *
 * @spec openspec/changes/openregister-web-push-engine/specs/web-push-delivery/spec.md
 */
class GenerateVapidKeys extends Command
{

    /**
     * App id used for IAppConfig writes.
     *
     * @var string
     */
    private const APP_ID = 'openregister';

    /**
     * Wire the command against app config.
     *
     * @param IAppConfig $appConfig App-config writer for the keypair.
     */
    public function __construct(
        private readonly IAppConfig $appConfig,
    ) {
        parent::__construct();

    }//end __construct()

    /**
     * Define the command name + options.
     *
     * @return void
     *
     * @spec openspec/changes/openregister-web-push-engine/specs/web-push-delivery/spec.md
     */
    protected function configure(): void
    {
        $this->setName(name: 'openregister:web-push:generate-vapid')
            ->setDescription('Generate and store the VAPID keypair used to sign Web Push payloads.')
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Rotate the keypair even when one already exists (existing subscriptions will be re-keyed on next subscribe).'
            );

    }//end configure()

    /**
     * Generate the keypair and persist it to app config.
     *
     * @param InputInterface  $input  Console input.
     * @param OutputInterface $output Console output stream.
     *
     * @return int Symfony command exit code.
     *
     * @spec openspec/changes/openregister-web-push-engine/specs/web-push-delivery/spec.md
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $force    = (bool) $input->getOption('force');
        $existing = $this->appConfig->getValueString(self::APP_ID, WebPushService::VAPID_PUBLIC_KEY, '');

        if ($existing !== '' && $force === false) {
            $output->writeln('<comment>A VAPID keypair already exists. Re-run with --force to rotate it.</comment>');
            $output->writeln(sprintf('<info>Public key:</info> %s', $existing));
            return Command::SUCCESS;
        }

        try {
            $keys = VAPID::createVapidKeys();
        } catch (\Throwable $e) {
            $output->writeln(sprintf('<error>Failed to generate VAPID keypair: %s</error>', $e->getMessage()));
            return Command::FAILURE;
        }

        $this->appConfig->setValueString(self::APP_ID, WebPushService::VAPID_PUBLIC_KEY, (string) $keys['publicKey']);
        $this->appConfig->setValueString(self::APP_ID, WebPushService::VAPID_PRIVATE_KEY, (string) $keys['privateKey'], false, true);

        $output->writeln('<info>VAPID keypair generated and stored in app config.</info>');
        $output->writeln(sprintf('<info>Public key:</info> %s', (string) $keys['publicKey']));
        $output->writeln('<comment>The private key is stored as a sensitive app value and is never printed.</comment>');

        return Command::SUCCESS;

    }//end execute()


}//end class
