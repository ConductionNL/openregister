<?php

/**
 * OpenRegister contextchat:reindex command
 *
 * Backfills opted-in, published objects into Context Chat via the same
 * batch-submission path `ContentProvider::triggerInitialImport()` uses,
 * optionally scoped to a single register/schema for repair.
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
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/context-chat-provider/spec.md#requirement-initial-import-must-walk-opted-in-schemas-in-batches-and-must-be-re-runnable-via-occ
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Command;

use OCA\OpenRegister\ContextChat\ContentProvider;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * `occ openregister:contextchat:reindex` — batched Context Chat backfill.
 *
 * @spec openspec/specs/context-chat-provider/spec.md#requirement-initial-import-must-walk-opted-in-schemas-in-batches-and-must-be-re-runnable-via-occ
 */
class ContextChatReindexCommand extends Command
{
    /**
     * Wire collaborators.
     *
     * @param ContentProvider $contentProvider Shared batch-submission path.
     * @param RegisterMapper  $registerMapper  Register lookup mapper, for the `--register` option.
     * @param SchemaMapper    $schemaMapper    Schema lookup mapper, for the `--schema` option.
     *
     * @return void
     *
     * @spec openspec/specs/context-chat-provider/spec.md#requirement-initial-import-must-walk-opted-in-schemas-in-batches-and-must-be-re-runnable-via-occ
     */
    public function __construct(
        private readonly ContentProvider $contentProvider,
        private readonly RegisterMapper $registerMapper,
        private readonly SchemaMapper $schemaMapper
    ) {
        parent::__construct();
    }//end __construct()

    /**
     * Define command name, description, and options.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName(name: 'openregister:contextchat:reindex')
            ->setDescription(
                'Backfill opted-in, published objects into Context Chat. '.
                'Optionally scoped to a single register and/or schema.'
            )
            ->addOption('register', null, InputOption::VALUE_REQUIRED, 'Register slug, uuid or id to scope the reindex to')
            ->addOption('schema', null, InputOption::VALUE_REQUIRED, 'Schema slug, uuid or id to scope the reindex to');
    }//end configure()

    /**
     * Resolve the optional scope options and run the batch-submission walk.
     *
     * @param InputInterface  $input  Console input.
     * @param OutputInterface $output Console output stream.
     *
     * @return int Symfony command exit code.
     *
     * @spec openspec/specs/context-chat-provider/spec.md#requirement-initial-import-must-walk-opted-in-schemas-in-batches-and-must-be-re-runnable-via-occ
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $registerId  = null;
        $registerRef = $input->getOption('register');
        if ($registerRef !== null) {
            try {
                $registerId = (int) $this->registerMapper->find(id: $registerRef, _rbac: false, _multitenancy: false)->getId();
            } catch (Throwable $e) {
                $output->writeln('<error>Register not found: '.$e->getMessage().'</error>');
                return Command::FAILURE;
            }
        }

        $schemaId  = null;
        $schemaRef = $input->getOption('schema');
        if ($schemaRef !== null) {
            try {
                $schemaId = (int) $this->schemaMapper->find(id: $schemaRef, _rbac: false, _multitenancy: false)->getId();
            } catch (Throwable $e) {
                $output->writeln('<error>Schema not found: '.$e->getMessage().'</error>');
                return Command::FAILURE;
            }
        }

        $output->writeln('<info>Reindexing OpenRegister objects into Context Chat…</info>');

        try {
            $submitted = $this->contentProvider->reindex(registerId: $registerId, schemaId: $schemaId);
        } catch (Throwable $e) {
            $output->writeln('<error>Reindex failed: '.$e->getMessage().'</error>');
            return Command::FAILURE;
        }

        $output->writeln(sprintf('<info>Submitted %d object(s).</info>', $submitted));

        return Command::SUCCESS;
    }//end execute()
}//end class
