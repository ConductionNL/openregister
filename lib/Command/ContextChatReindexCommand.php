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
 * @spec openspec/specs/context-chat-provider/spec.md#requirement-getitemurl-and-initial-import-reuse-existing-openregister-infrastructure
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Command;

use OCA\OpenRegister\ContextChat\ContentProvider;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * `occ openregister:contextchat:reindex` — batched Context Chat backfill.
 *
 * @spec openspec/specs/context-chat-provider/spec.md#requirement-getitemurl-and-initial-import-reuse-existing-openregister-infrastructure
 */
class ContextChatReindexCommand extends Command
{
    /**
     * Wire collaborators.
     *
     * ⚠️ `ContentProvider` is resolved LAZILY, from the container, inside
     * {@see self::execute()} — it is deliberately NOT a constructor parameter,
     * and this is not a style choice.
     *
     * `OCP\ContextChat\IContentProvider` is core Nextcloud API, but only from
     * **NC 32**: it is absent from `stable31`, which
     * this app's `info.xml` claims to support (`min-version="28"`). Because
     * {@see ContentProvider} declares `implements IContentProvider`, merely
     * LOADING that class on an older server is fatal — a class header is
     * resolved eagerly and no guard inside the class can prevent it.
     *
     * A constructor typehint is enough to trigger exactly that. Every command
     * listed in `appinfo/info.xml` is resolved by
     * `Console\Application::loadCommandsFromInfoXml()`, which reflects each
     * constructor to build it — so with `ContentProvider` in the signature,
     * EVERY `occ` invocation on NC 28-32 threw before running anything:
     *
     *     Error: Interface "OCP\ContextChat\IContentProvider" not found
     *       at .../openregister/lib/ContextChat/ContentProvider.php:50
     *       ReflectionClass::__construct  <- SimpleContainer::resolve
     *       <- loadCommandsFromInfoXml <- console.php
     *
     * Observed in CI on NC 31.0.14.1 during `occ app:enable`. It is logged at
     * `level 3` and the enable still reports success, which is why it survived:
     * the failure is loud in the log and invisible in the exit code.
     *
     * `ContentProvider::class` below is compile-time — the compiler turns it
     * into a plain string and never autoloads — so naming the class here is
     * safe; only `get()`ing it is not, and that now happens at execute() time.
     *
     * @param ContainerInterface $container      Container, for the lazy ContentProvider resolve.
     * @param RegisterMapper     $registerMapper Register lookup mapper, for the `--register` option.
     * @param SchemaMapper       $schemaMapper   Schema lookup mapper, for the `--schema` option.
     *
     * @return void
     *
     * @spec openspec/specs/context-chat-provider/spec.md#requirement-getitemurl-and-initial-import-reuse-existing-openregister-infrastructure
     */
    public function __construct(
        private readonly ContainerInterface $container,
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
     * @spec openspec/specs/context-chat-provider/spec.md#requirement-getitemurl-and-initial-import-reuse-existing-openregister-infrastructure
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // See the constructor: OCP\ContextChat is NC 32+. On an older server
        // this command cannot run, and saying so is far better than the fatal
        // that loading ContentProvider would produce. `interface_exists()`
        // asks the autoloader without dying when the answer is no.
        if (interface_exists('OCP\\ContextChat\\IContentProvider') === false) {
            $output->writeln(
                '<comment>Context Chat is not available on this Nextcloud version '
                .'(OCP\\ContextChat was introduced in Nextcloud 32). Nothing to reindex.</comment>'
            );
            return Command::SUCCESS;
        }

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
            // Resolved here, not in the constructor — this is the first point
            // at which loading ContentProvider is safe. See the constructor.
            $contentProvider = $this->container->get(ContentProvider::class);

            $submitted = $contentProvider->reindex(registerId: $registerId, schemaId: $schemaId);
        } catch (Throwable $e) {
            $output->writeln('<error>Reindex failed: '.$e->getMessage().'</error>');
            return Command::FAILURE;
        }

        $output->writeln(sprintf('<info>Submitted %d object(s).</info>', $submitted));

        return Command::SUCCESS;
    }//end execute()
}//end class
