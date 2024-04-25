<?php
declare(strict_types=1);

/*
 * This file is part of PSB User Deployment.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace PSB\PsbUserDeployment\Command;

use JsonException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use function is_array;

/**
 * Class DeployCommand
 *
 * This command imports a JSON file containing the configuration for users and user groups in both backend and frontend.
 * Example:
 * {
 *     "be_groups: {
 *         "_variables": {
 *
 *         },
 *         "basic": {
 *
 *         },
 *         "advanced": {
 *             "inherits": ["basic"]
 *         }
 *     },
 *     "be_users": {
 *         "jadoe": {
 *             "groups": ["basic"],
 *             "name": "Jane Doe"
 *         },
 *         "jodoe": {
 *             "groups": ["advanced"],
 *             "name": "John Doe"
 *         }
 *     }
 * }
 *
 * @package PSB\PsbUserDeployment\Command
 */
#[AsCommand(name: 'psbUserDeployment:deploy', description: 'This command creates/deletes/updates users and user groups depending on configuration.')]
class DeployCommand extends Command
{
    protected bool $dryRun = false;

    protected function configure(): void
    {
        $this->setHelp('This command does nothing. It always succeeds.')
            ->addArgument(
                'file',
                InputArgument::REQUIRED,
                'Provide the source which should be deployed.',
            )
            ->addOption(
                'dry-run',
                'd',
                InputOption::VALUE_NONE,
                'Don\'t make changes to the database, but show number of affected records only. You can use --dry-run or -d when running command.',
            );
    }

    /**
     * @throws JsonException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $fileName = $input->getArgument('file');
        $this->dryRun = (bool)$input->getOption('dry-run');

        $configuration = $this->decodeConfigurationFile($fileName);

        if (!empty($configuration['files'])) {
            $configuration = $this->importConfigurationFiles($configuration);
        }

        if (!empty($configuration['be_groups'])) {
            $this->deployBackendGroups($configuration['be_groups']);
        }

        if (!empty($configuration['be_users'])) {
            $this->deployBackendUsers($configuration['be_users']);
        }

        if (!empty($configuration['fe_groups'])) {
            $this->deployFrontendGroups($configuration['fe_groups']);
        }

        if (!empty($configuration['fe_users'])) {
            $this->deployFrontendUsers($configuration['fe_users']);
        }

        return Command::SUCCESS;
    }

    /**
     * @throws JsonException
     */
    private function decodeConfigurationFile(string $fileName): array
    {
        return json_decode($fileName, true, 512, JSON_THROW_ON_ERROR);
    }

    private function deployBackendGroups(array $backendGroupsConfiguration): void
    {
        $variables = $this->extractVariables($backendGroupsConfiguration);

        foreach ($backendGroupsConfiguration as $backendGroupName => $settings) {

        }
    }

    private function extractVariables(array &$tableConfiguration): array
    {
        if (isset($tableConfiguration['_variables'])) {
            if (is_array($tableConfiguration['_variables'])) {
                $variables = $tableConfiguration['_variables'];
            }

            unset($tableConfiguration['_variables']);
        }

        return $variables ?? [];
    }

    /**
     * @throws JsonException
     */
    private function importConfigurationFiles(array $configuration): array
    {
        foreach ($configuration['files'] as $fileName) {
            array_merge($configuration, $this->decodeConfigurationFile($fileName));
        }

        return $configuration;
    }
}
