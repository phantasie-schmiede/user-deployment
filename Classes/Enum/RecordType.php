<?php
declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

namespace PSB\PsbUserDeployment\Enum;

/**
 * Class RecordType
 *
 * @package PSB\PsbUserDeployment\Enum
 */
enum RecordType: string
{
    case BackendGroup  = 'be_groups';
    case BackendUser   = 'be_users';
    case FileMount     = 'sys_filemounts';
    case FrontendGroup = 'fe_groups';
    case FrontendUser  = 'fe_users';

    /**
     * @return RecordType[]
     */
    public static function strictlyOrderedCases(): array
    {
        return [
            0 => self::FileMount,
            1 => self::BackendGroup,
            2 => self::FrontendGroup,
            3 => self::BackendUser,
            4 => self::FrontendUser,
        ];
    }

    public function getGroupField(): ?string
    {
        return match ($this) {
            self::BackendGroup, self::FrontendGroup => 'subgroup',
            self::BackendUser, self::FrontendUser   => 'usergroup',
            default                                 => null,
        };
    }

    public function getGroupTable(): ?string
    {
        return match ($this) {
            self::BackendGroup, self::BackendUser   => 'be_groups',
            self::FrontendGroup, self::FrontendUser => 'fe_groups',
            default                                 => null,
        };
    }

    public function getIdentifierField(): ?string
    {
        return match ($this) {
            self::BackendGroup, self::FileMount, self::FrontendGroup => 'title',
            self::BackendUser, self::FrontendUser                    => 'username',
        };
    }

    public function getTable(): string
    {
        return $this->value;
    }
}
