<?php

/**
 * One-off repair: re-chain the whole audit trail from genesis.
 *
 * Sealing predates the seal lock. Before SEAL_LOCK_KEY existed, concurrent seal
 * passes could each read the same predecessor and then write, leaving many rows
 * chained onto ONE predecessor — a fan-out rather than a chain, which
 * verifyChain() correctly reports as broken. The sweeper cannot repair that: it
 * seals rows with NO hash, not rows carrying a WRONG one.
 *
 * This command is the repair, and it is a command rather than a job on purpose.
 * Rewriting stored audit hashes is exactly the event the chain exists to make
 * suspicious, so it must be asked for by a person, announced in the log at
 * warning level, and confirmed unless explicitly forced.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Command
 * @package  OCA\OpenRegister\Command
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/audit-hash-chain/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Command;

use OCA\OpenRegister\Service\AuditHashService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * Rebuilds the audit hash chain end to end.
 */
class RechainAuditTrailCommand extends Command
{

    /**
     * Constructor.
     *
     * @param AuditHashService $hashes Performs the re-chain.
     */
    public function __construct(private readonly AuditHashService $hashes)
    {
        parent::__construct();

    }//end __construct()

    /**
     * Declare the command.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName(name: 'openregister:rechain-audit-trail')
            ->setDescription(
                'One-off repair: recompute every audit-trail hash from genesis. '
                .'Rewrites stored hashes — run only to repair a chain broken by historical concurrent sealing.'
            )
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would change without writing')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Skip the confirmation prompt');

    }//end configure()

    /**
     * Verify, confirm, re-chain, verify again.
     *
     * The verification either side is the point: a repair that cannot show the
     * chain was broken before and whole after is indistinguishable from one that
     * quietly rewrote a healthy chain.
     *
     * @param InputInterface  $input  Console input.
     * @param OutputInterface $output Console output.
     *
     * @return int Exit code.
     *
     * @spec openspec/specs/audit-hash-chain/spec.md
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $before = $this->hashes->verifyChain();
        $output->writeln('<info>Before:</info>');
        $output->writeln(
            sprintf(
                '  valid=%s  verified=%d  brokenAt=%s  unsealed=%d',
                ($before['valid'] === true ? 'true' : 'false'),
                (int) ($before['entriesVerified'] ?? 0),
                var_export(($before['brokenAt'] ?? null), true),
                $this->hashes->countUnsealed()
            )
        );

        if ($input->getOption('dry-run') === true) {
            $output->writeln('<comment>Dry run — nothing written.</comment>');
            return Command::SUCCESS;
        }

        if ($input->getOption('force') !== true) {
            $helper   = $this->getHelper('question');
            $question = new ConfirmationQuestion(
                '<comment>This REWRITES every stored audit hash. Continue? [y/N] </comment>',
                false
            );

            if ($helper->ask($input, $output, $question) !== true) {
                $output->writeln('Aborted.');
                return Command::SUCCESS;
            }
        }

        $result = $this->hashes->rechainAll();
        $output->writeln(
            sprintf(
                '<info>Re-chained %d row(s); left %d retention tombstone(s) untouched.</info>',
                (int) $result['rechained'],
                (int) ($result['tombstonesPreserved'] ?? 0)
            )
        );

        $after = $this->hashes->verifyChain();
        $output->writeln('<info>After:</info>');
        $output->writeln(
            sprintf(
                '  valid=%s  verified=%d  brokenAt=%s  unsealed=%d',
                ($after['valid'] === true ? 'true' : 'false'),
                (int) ($after['entriesVerified'] ?? 0),
                var_export(($after['brokenAt'] ?? null), true),
                $this->hashes->countUnsealed()
            )
        );

        if ($after['valid'] !== true) {
            $output->writeln('<error>Chain still reports invalid — do not treat this repair as complete.</error>');
            return Command::FAILURE;
        }

        return Command::SUCCESS;

    }//end execute()
}//end class
