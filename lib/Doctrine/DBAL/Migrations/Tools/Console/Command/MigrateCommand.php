<?php
/*
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the LGPL. For more information, see
 * <http://www.doctrine-project.org>.
 */

namespace Doctrine\DBAL\Migrations\Tools\Console\Command;

use Doctrine\DBAL\Migrations\Migration;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * Command for executing a migration to a specified version or the latest available version.
 *
 * @license http://www.opensource.org/licenses/lgpl-license.php LGPL
 * @link    www.doctrine-project.org
 * @since   2.0
 * @author  Jonathan Wage <jonwage@gmail.com>
 */
class MigrateCommand extends AbstractCommand
{
    protected function configure()
    {
        $this
            ->setName('migrations:migrate')
            ->setDescription('Execute a migration to a specified version or the latest available version.')
            ->addArgument('version', InputArgument::OPTIONAL, 'The version number (YYYYMMDDHHMMSS) or alias (first, prev, next, latest) to migrate to.', 'latest')
            ->addOption('write-sql', null, InputOption::VALUE_NONE, 'The path to output the migration SQL file instead of executing it.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Execute the migration as a dry run.')
            ->addOption('query-time', null, InputOption::VALUE_NONE, 'Time all the queries individually.')
            ->setHelp(<<<EOT
The <info>%command.name%</info> command executes a migration to a specified version or the latest available version:

    <info>%command.full_name%</info>

You can optionally manually specify the version you wish to migrate to:

    <info>%command.full_name% YYYYMMDDHHMMSS</info>

You can specify the version you wish to migrate to using an alias:

    <info>%command.full_name% prev</info>

You can also execute the migration as a <comment>--dry-run</comment>:

    <info>%command.full_name% YYYYMMDDHHMMSS --dry-run</info>

You can output the would be executed SQL statements to a file with <comment>--write-sql</comment>:

    <info>%command.full_name% YYYYMMDDHHMMSS --write-sql</info>

Or you can also execute the migration without a warning message which you need to interact with:

    <info>%command.full_name% --no-interaction</info>

You can also time all the different queries if you wanna know which one is taking so long:

    <info>%command.full_name% --query-time</info>
EOT
        );

        parent::configure();
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        $configuration = $this->getMigrationConfiguration($input, $output);
        $migration = new Migration($configuration);

        $this->outputHeader($configuration, $output);

        $noInteraction = !$input->isInteractive();

        $timeAllqueries = $input->getOption('query-time');

        $executedMigrations = $configuration->getMigratedVersions();
        $availableMigrations = $configuration->getAvailableVersions();
        $executedUnavailableMigrations = array_diff($executedMigrations, $availableMigrations);

        $versionAlias = $input->getArgument('version');
        $version = $configuration->resolveVersionAlias($versionAlias);
        if ($version === null) {
            switch ($versionAlias) {
                case 'prev':
                    $output->writeln('<error>Already at first version.</error>');
                    break;
                case 'next':
                    $output->writeln('<error>Already at latest version.</error>');
                    break;
                default:
                    $output->writeln('<error>Unknown version: ' . $output->getFormatter()->escape($versionAlias) . '</error>');
            }

            return 1;
        }

        if ($executedUnavailableMigrations) {
            $output->writeln(sprintf('<error>WARNING! You have %s previously executed migrations in the database that are not registered migrations.</error>', count($executedUnavailableMigrations)));
            foreach ($executedUnavailableMigrations as $executedUnavailableMigration) {
                $output->writeln('    <comment>>></comment> ' . $configuration->formatVersion($executedUnavailableMigration) . ' (<comment>' . $executedUnavailableMigration . '</comment>)');
            }

            if (! $noInteraction) {
                $question = 'Are you sure you wish to continue? (y/n)';
                $confirmation = $this->askConfirmation($question, $input, $output);

                if (! $confirmation) {
                    $output->writeln('<error>Migration cancelled!</error>');

                    return 1;
                }
            }
        }

        if ($path = $input->getOption('write-sql')) {
            $path = is_bool($path) ? getcwd() : $path;
            $migration->writeSqlFile($path, $version);
        } else {
            $dryRun = (boolean) $input->getOption('dry-run');

            // warn the user if no dry run and interaction is on
            if (! $dryRun && ! $noInteraction) {
                $question = 'WARNING! You are about to execute a database migration that could result in schema changes and data lost. Are you sure you wish to continue? (y/n)';
                $confirmation = $this->askConfirmation($question, $input, $output);

                if (! $confirmation) {
                    $output->writeln('<error>Migration cancelled!</error>');

                    return 1;
                }
            }

            $sql = $migration->migrate($version, $dryRun, $timeAllqueries);

            if (! $sql) {
                $output->writeln('<comment>No migrations to execute.</comment>');
            }
        }
    }
}
