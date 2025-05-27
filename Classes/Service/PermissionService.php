<?php
declare(strict_types=1);

/*
 * This file is part of PSB User Deployment.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace PSB\PsbUserDeployment\Service;

use Doctrine\DBAL\Exception as DoctrineException;
use Exception;
use PDO;
use PSB\PsbFoundation\Utility\TypoScript\TypoScriptUtility;
use PSB\PsbUserDeployment\Data\ExtensionInformation;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\DataHandling\PagePermissionAssembler;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\TypoScript\Parser\TypoScriptParser;
use TYPO3\CMS\Core\TypoScript\TypoScriptService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use function count;

/**
 * Class PermissionService
 *
 * @package PSB\PsbUserDeployment\Service
 */
class PermissionService
{
    public const string    PERMISSION_KEY  = '_pageTreeAccess';
    protected const array  RELEVANT_FIELDS = [
        'uid',
        'pid',
        'perms_everybody',
        'perms_group',
        'perms_groupid',
        'perms_user',
        'perms_userid',
        'TSconfig',
    ];
    protected const string TABLE_NAME      = 'pages';
    protected PagePermissionAssembler $pagePermissionAssembler;
    protected int                     $pagesCounter = 0;

    public function __construct(
        protected readonly ExtensionInformation $extensionInformation,
        protected readonly SiteFinder           $siteFinder,
        protected readonly TypoScriptService    $typoScriptService,
    ) {
        $this->pagePermissionAssembler = GeneralUtility::makeInstance(
            PagePermissionAssembler::class,
            $GLOBALS['TYPO3_CONF_VARS']['BE']['defaultPermissions']
        );
    }

    /**
     * @throws DoctrineException
     * @throws Exception
     */
    public function setPermissionsForAllPages(array $pageTreeAccessMapping, SymfonyStyle $io = null): void
    {
        $queryBuilder = $this->createQueryBuilder();
        $this->pagesCounter = 0;

        // Get first array key of $pageTreeAccessMapping
        $siteConfigurations = $this->siteFinder->getAllSites();

        foreach ($siteConfigurations as $siteConfiguration) {
            $pageData = $queryBuilder->select(...self::RELEVANT_FIELDS)
                ->from(self::TABLE_NAME)
                ->where(
                    $queryBuilder->expr()
                        ->eq(
                            'uid',
                            $queryBuilder->createNamedParameter($siteConfiguration->getRootPageId(), PDO::PARAM_INT)
                        )
                )
                ->executeQuery()
                ->fetchAllAssociative();

            $io?->writeln(
                'Setting permissions for pages, starting at root page with UID ' . $siteConfiguration->getRootPageId(
                ) . '.'
            );

            $this->setPermissionsRecursively($pageData, $pageTreeAccessMapping);
        }

        $io?->writeln(
            'Finished setting permissions for all pages. ' . $this->pagesCounter . ' pages were processed.'
        );
    }

    protected function createQueryBuilder(): QueryBuilder
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable(self::TABLE_NAME)
            ->createQueryBuilder();

        $queryBuilder->getRestrictions()
            ->removeAll();

        return $queryBuilder;
    }

    /**
     * @throws DoctrineException
     */
    protected function findAllSubpages(int $uid): array
    {
        $queryBuilder = $this->createQueryBuilder();

        $queryBuilder->select(...self::RELEVANT_FIELDS)
            ->from(self::TABLE_NAME)
            ->where(
                $queryBuilder->expr()
                    ->and(
                        $queryBuilder->expr()
                            ->eq('pid', $queryBuilder->createNamedParameter($uid, PDO::PARAM_INT)),
                        $queryBuilder->expr()
                            ->eq(
                                'deleted',
                                $queryBuilder->createNamedParameter(0, PDO::PARAM_INT)
                            )
                    )
            );

        return $queryBuilder->executeQuery()
            ->fetchAllAssociative();
    }

    protected function logUpdateStatement(QueryBuilder $queryBuilder): void
    {
        $sql = $queryBuilder->getSQL();

        foreach ($queryBuilder->getParameters() as $alias => $value) {
            $sql = str_replace(':' . $alias, (string)$value, $sql);
        }
    }

    /**
     * @throws DoctrineException
     */
    protected function setPermissionsRecursively(
        array $pages,
        array $pageTreeAccessMapping,
        int   $parentPageGroupId = null,
    ): bool {
        foreach ($pages as $page) {
            $permissions = $this->pagePermissionAssembler->applyDefaults([],
                $page['uid'],
                $page['perms_userid'],
                $page['perms_groupid']);

            if (isset($pageTreeAccessMapping[$page['uid']])) {
                $permissions['perms_groupid'] = $pageTreeAccessMapping[$page['uid']];
            } elseif (null !== $parentPageGroupId) {
                $permissions['perms_groupid'] = $parentPageGroupId;
            }

            $this->updateTSconfigForPage($page, $pageTreeAccessMapping[$page['uid']] ?? null);

            if ($parentPageGroupId !== $permissions['perms_groupid']) {
                $setParentShowForEverybody = true;
            }

            $subpages = $this->findAllSubpages($page['uid']);

            if (0 < count($subpages)) {
                $setShowForEverybody = $this->setPermissionsRecursively(
                    $subpages,
                    $pageTreeAccessMapping,
                    $permissions['perms_groupid']
                );

                /*
                 * Add the right to show this page for everybody if its subpages belong to other groups, so that members
                 * of those groups can see the whole rootline.
                 */
                if ($setShowForEverybody) {
                    $permissions['perms_everybody'] |= Permission::PAGE_SHOW;
                }
            }

            $queryBuilder = $this->createQueryBuilder();

            $queryBuilder->update(self::TABLE_NAME)
                ->where(
                    $queryBuilder->expr()
                        ->eq('uid', $queryBuilder->createNamedParameter($page['uid'], PDO::PARAM_INT))
                )
                ->set('perms_everybody', $permissions['perms_everybody'])
                ->set('perms_group', $permissions['perms_group'])
                ->set('perms_groupid', $permissions['perms_groupid'])
                ->set('perms_user', $permissions['perms_user'])
                ->set('perms_userid', $permissions['perms_userid']);

            $queryBuilder->executeStatement();

            $this->logUpdateStatement($queryBuilder);
            $this->pagesCounter++;
        }

        return $setParentShowForEverybody ?? false;
    }

    private function updateTSconfigForPage(array $page, ?int $permsGroupId = null): void
    {
        /** @var TypoScriptParser $typoScriptParser */
        $typoScriptParser = GeneralUtility::makeInstance(TypoScriptParser::class);
        $typoScriptParser->parse($page['TSconfig']);
        $tsconfig = $this->typoScriptService->convertTypoScriptArrayToPlainArray($typoScriptParser->setup);

        if (null !== $permsGroupId) {
            $tsconfig['TCEMAIN']['permissions']['groupid'] = [
                TypoScriptUtility::TYPO_SCRIPT_KEYS['COMMENT'] => 'added by ' . $this->extensionInformation->getExtensionKey(
                    ),
                $permsGroupId,
            ];
        } elseif (isset($tsconfig['TCEMAIN']['permissions']['groupid'])) {
            unset($tsconfig['TCEMAIN']['permissions']['groupid']);
        } else {
            // No change needed.
            return;
        }

        $page['TSconfig'] = TypoScriptUtility::convertArrayToTypoScript($tsconfig);

        $queryBuilder = $this->createQueryBuilder();
        $queryBuilder->update(self::TABLE_NAME)
            ->where(
                $queryBuilder->expr()
                    ->eq('uid', $queryBuilder->createNamedParameter($page['uid'], PDO::PARAM_INT))
            )
            ->set('TSconfig', $page['TSconfig']);
        $queryBuilder->executeStatement();
    }
}
