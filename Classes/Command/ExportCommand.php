<?php
declare(strict_types=1);

/*
 * This file is part of PSB User Deployment.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace PSB\PsbUserDeployment\Command;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Exception;
use JsonException;
use PSB\PsbUserDeployment\Enum\RecordType;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use function in_array;
use function is_array;

/**
 * Class ExportCommand
 *
 * @package PSB\PsbUserDeployment\Command
 */
#[AsCommand(name: 'psbUserDeployment:export', description: 'This command exports users and user groups into a JSON file which can be used as basis for further additions and optimizations.')]
class ExportCommand extends Command
{
    protected SymfonyStyle $io;

    protected function configure(): void
    {
        $this->setHelp('This command exports a JSON file containing the existing user and user group records in both backend and frontend.')
            ->addArgument('file', InputArgument::REQUIRED, 'Provide the name of the created export file.');
    }

    /**
     * @throws Exception
     * @throws JsonException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $configuration = [];
        $this->io = new SymfonyStyle($input, $output);
        $fileName = $input->getArgument('file');

        foreach (RecordType::cases() as $recordType) {
            $table = $recordType->getTable();
            $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($table);
            $records = $queryBuilder->select('*')
                ->from($table)
                ->executeQuery()
                ->fetchAllAssociative();

            foreach ($records as $record) {
                $identifierField = $recordType->getIdentifierField();
                $identifier = $record[$identifierField];
                unset($record[$identifierField]);
                $configuration[$table][$identifier] = $record;
            }
        }

        // Write the configuration to the file.
        $file = GeneralUtility::getFileAbsFileName($fileName);
        $fileContent = json_encode($configuration, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        GeneralUtility::writeFile($file, $fileContent);

        $this->io->success('The export has been successfully created.');

        return Command::SUCCESS;
    }
}
