<?php
declare(strict_types=1);

/*
 * This file is part of PSB User Deployment.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace PSB\PsbUserDeployment\Command;

use Doctrine\DBAL\Exception;
use JsonException;
use PSB\PsbUserDeployment\Enum\RecordType;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use function count;
use function in_array;

/**
 * Class ExportCommand
 *
 * @package PSB\PsbUserDeployment\Command
 */
#[AsCommand(name: 'psbUserDeployment:export', description: 'This command exports file mounts, users and user groups into a JSON file which can be used as basis for further additions and optimizations.')]
class ExportCommand extends Command
{
    private const array DEFAULT_VALUES  = [
        'be_groups'      => [
            'allowed_languages'   => '',
            'availableWidgets'    => 0,
            'category_perms'      => '',
            'db_mountpoints'      => '',
            'description'         => '',
            'disable_auto_hide'   => 0,
            'disable_auto_prefix' => '',
            'explicit_allowdeny'  => '',
            'file_mountpoints'    => '',
            'file_permissions'    => '',
            'groupMods'           => '',
            'non_exclude_fields'  => '',
            'pagetypes_select'    => '',
            'pid'                 => 0,
            'subgroup'            => '',
            'tables_modify'       => '',
            'tables_select'       => '',
            'TSconfig'            => '',
            'workspace_perms'     => 0,
        ],
        'be_users'       => [
            'admin'            => 0,
            'file_permissions' => '',
            'lang'             => 'default',
            'mfa'              => null,
            'pid'              => 0,
            'TSconfig'         => '',
        ],
        'fe_groups'      => [],
        'fe_users'       => [],
        'sys_filemounts' => [
            'description' => '',
            'hidden'      => 0,
            'pid'         => 0,
            'read_only'   => 0,
        ],
    ];
    private const array EXCLUDED_FIELDS = [
        'crdate',
        'cruser_id',
        'deleted',
        'lastlogin',
        'mfa',
        'password',
        'password_reset_token',
        'sorting',
        'tstamp',
        'uc',
        'uid',
    ];
    protected SymfonyStyle $io;

    protected function configure(): void
    {
        $this->setHelp(
            'This command exports a JSON file containing the existing file mount, user and user group records in both backend and frontend.'
        )
            ->addArgument('file', InputArgument::REQUIRED, 'Provide the name of the created export file.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $configuration = [];
        $mapping = [];
        $this->io = new SymfonyStyle($input, $output);
        $fileName = $input->getArgument('file');

        $this->io->writeln('Exporting file mounts, users and user groups to ' . $fileName . '.');

        try {
            foreach (RecordType::strictlyOrderedCases() as $recordType) {
                $this->exportToConfiguration($configuration, $mapping, $recordType);
            }

            $this->exportToFile($configuration, $fileName);
            $this->io->success('The export has been successfully created.');

            return Command::SUCCESS;
        } catch (Throwable $exception) {
            $this->io->error('An error occurred during the export process: ' . $exception->getMessage());

            return Command::FAILURE;
        }
    }

    /**
     * @throws Exception
     */
    private function exportToConfiguration(array &$configuration, array &$mapping, RecordType $recordType): void
    {
        $table = $recordType->getTable();
        $configuration[$table]['_default'] = self::DEFAULT_VALUES[$table];
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()
            ->removeAll();
        $records = $queryBuilder->select('*')
            ->from($table)
            ->where(
                $queryBuilder->expr()
                    ->eq('deleted', 0)
            )
            ->executeQuery()
            ->fetchAllAssociative();
        $this->io->writeln('Exporting ' . count($records) . ' ' . $table . ' records');

        if (in_array($recordType, [
            RecordType::BackendGroup,
            RecordType::FileMount,
            RecordType::FrontendGroup,
        ], true)) {
            foreach ($records as $record) {
                $mapping[$table][$record['uid']] = $record[$recordType->getIdentifierField()];
            }
        }

        foreach ($records as $record) {
            $identifierField = $recordType->getIdentifierField();
            $identifier = $record[$identifierField];
            unset($record[$identifierField]);
            if (null !== $recordType->getGroupField()) {
                $this->replaceRelationIdentifier(
                    $mapping[$recordType->getGroupTable()],
                    $record,
                    $recordType->getGroupField()
                );
            }

            if (in_array($recordType, [
                RecordType::BackendGroup,
                RecordType::BackendUser,
            ], true)) {
                $this->replaceRelationIdentifier($mapping['sys_filemounts'], $record, 'file_mountpoints');
            }

            foreach ($record as $field => $value) {
                // Add field to configuration if it is not the default value and not in the excluded fields.
                if ((!isset($configuration[$table]['_default'][$field]) || $value !== $configuration[$table]['_default'][$field]) && !in_array(
                        $field,
                        self::EXCLUDED_FIELDS,
                        true
                    )) {
                    $configuration[$table][$identifier][$field] = $value;
                }
            }
        }
    }

    /**
     * @throws JsonException
     */
    private function exportToFile(array $configuration, string $fileName): void
    {
        $file = GeneralUtility::getFileAbsFileName($fileName);
        $fileContent = json_encode($configuration, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        GeneralUtility::writeFile($file, $fileContent);
    }

    private function replaceRelationIdentifier(array $tableMapping, array &$record, string $relationField): void
    {
        if (empty($record[$relationField])) {
            return;
        }

        $relations = GeneralUtility::intExplode(',', $record[$relationField]);

        array_walk($relations, static function(&$value) use ($tableMapping) {
            $value = $tableMapping[$value] ?? '';
        });

        $record[$relationField] = array_filter($relations);
    }
}
