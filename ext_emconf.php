<?php

$EM_CONF[$_EXTKEY] = [
    'author'           => 'Daniel Ablass',
    'author_email'     => 'dn@phantasie-schmiede.de',
    'category'         => 'misc',
    'clearCacheOnLoad' => true,
    'constraints'      => [
        'conflicts' => [],
        'depends'   => [
            'php'   => '8.3',
            'typo3' => '12.4.0-13.4.99',
        ],
        'suggests'  => [],
    ],
    'description'      => 'Deployment of users, user groups and their privileges',
    'state'            => 'stable',
    'title'            => 'PSBits | ACL Deployment',
    'version'          => '2.0.0',
];
