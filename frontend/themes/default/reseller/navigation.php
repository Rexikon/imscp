<?php
/**
 * i-MSCP - internet Multi Server Control Panel
 * Copyright (C) 2010-2018 by Laurent Declercq <l.declercq@nuxwin.com>
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 */

namespace iMSCP;

use iMSCP\Functions\Counting;

return [
    'general'       => [
        'label' => tr('General'),
        'uri'   => '/reseller/index.php',
        'class' => 'general',
        'pages' => [
            'overview'        => [
                'label'       => tr('Overview'),
                'uri'         => '/reseller/index.php',
                'title_class' => 'general'
            ]
        ]
    ],
    'customers'     => [
        'label' => tr('Customers'),
        'uri'   => '/reseller/users.php',
        'class' => 'manage_users',
        'pages' => [
            'overview'            => [
                'label'       => tr('Overview'),
                'uri'         => '/reseller/users.php',
                'title_class' => 'users',
                'pages'       => [
                    'account_details' => [
                        'label'       => tr('{VL_ACCOUNT_NAME} account details'),
                        'uri'         => '/reseller/account_details.php',
                        'title_class' => 'user_blue',
                        'visible'     => false,
                    ],
                    'account_edit'    => [
                        'label'       => tr('Edit {ACCOUNT_NAME} account'),
                        'uri'         => '/reseller/account_edit.php',
                        'title_class' => 'domains',
                        'visible'     => false
                    ],
                    'customer_edit'   => [
                        'label'       => tr('Edit customer'),
                        'uri'         => '/reseller/user_edit.php',
                        'title_class' => 'user_blue',
                        'visible'     => false
                    ]
                ]
            ],
            'add_customer'        => [
                'label'       => tr('Add customer'),
                'uri'         => '/reseller/user_add1.php',
                'title_class' => 'user',
                'pages'       => [
                    'add_customer_p2' => [
                        'label'       => tr('Add customer - Next step'),
                        'uri'         => '/reseller/user_add2.php',
                        'visible'     => false,
                        'title_class' => 'user'
                    ],
                    'add_customer_p3' => [
                        'label'       => tr('Add customer - Next step'),
                        'uri'         => '/reseller/user_add3.php',
                        'visible'     => false,
                        'title_class' => 'user'
                    ],
                    'add_customer_p4' => [
                        'label'       => tr('Add customer - Next step'),
                        'uri'         => '/reseller/user_add4.php',
                        'visible'     => false,
                        'title_class' => 'user'
                    ]
                ]
            ],
            'domain_alias_orders' => [
                'label'              => tr('Domain alias orders'),
                'uri'                => '/reseller/alias_orders.php',
                'title_class'        => 'domains',
                'privilege_callback' => [
                    [
                        'name'  => [Counting::class, 'resellerHasFeature'],
                        'param' => 'domain_aliases'
                    ],
                    [
                        'name' => [Counting::class, 'resellerHasCustomers']
                    ]
                ]
            ],
            'welcome_mail'        => [
                'label'       => tr('Welcome email'),
                'uri'         => '/reseller/settings_welcome_mail.php',
                'title_class' => 'email'
            ],
            'lostpassword_mail'   => [
                'label'       => tr('Lost password email'),
                'uri'         => '/reseller/settings_lostpassword.php',
                'title_class' => 'email'
            ],
            'circular'            => [
                'label'              => tr('Circular'),
                'uri'                => '/reseller/circular.php',
                'title_class'        => 'email',
                'privilege_callback' => [
                    'name' => [Counting::class, 'resellerHasCustomers']
                ]
            ]
        ]
    ],
    'hosting_plans' => [
        'label' => tr('Hosting plans'),
        'uri'   => '/reseller/hosting_plan.php',
        'class' => 'hosting_plans',
        'pages' => [
            'overview'         => [
                'label'       => tr('Hosting plans'),
                'uri'         => '/reseller/hosting_plan.php',
                'title_class' => 'hosting_plans',
                'pages'       => [
                    'hosting_plan_edit' => [
                        'label'       => tr('Edit hosting plan'),
                        'uri'         => '/reseller/hosting_plan_edit.php',
                        'title_class' => 'hosting_plans',
                        'visible'     => false
                    ]
                ]
            ],
            'add_hosting_plan' => [
                'label'       => tr('Add hosting plan'),
                'uri'         => '/reseller/hosting_plan_add.php',
                'title_class' => 'hosting_plans',
                'class'       => 'hosting_plan_add'
            ]
        ]
    ],
    'statistics'    => [
        'label'              => tr('Statistics'),
        'uri'                => '/reseller/user_statistics.php',
        'class'              => 'statistics',
        'privilege_callback' => [
            'name' => [Counting::class, 'resellerHasCustomers']
        ],
        'pages'              => [
            'user_statistics' => [
                'label'       => tr('User statistics'),
                'uri'         => '/reseller/user_statistics.php',
                'title_class' => 'stats',
                'pages'       => [
                    'user_statistics_details' => [
                        'label'       => tr('{USERNAME} user statistics'),
                        'uri'         => '/reseller/user_statistics_details.php',
                        'title_class' => 'stats',
                        'visible'     => false
                    ]
                ]
            ],
            'ip_assignments'  => [
                'label'       => tr('IP assignments'),
                'uri'         => '/reseller/ip_assignments.php',
                'title_class' => 'stats'
            ]
        ]
    ],
    'supports'      => [
        'label'              => tr('Support'),
        'uri'                => '{SUPPORT_SYSTEM_PATH}',
        'target'             => '{SUPPORT_SYSTEM_TARGET}',
        'class'              => 'support',
        'privilege_callback' => [
            [
                'name'  => [Counting::class, 'resellerHasFeature'],
                'param' => 'support'
            ],
            [
                'name' => [Counting::class, 'resellerHasCustomers']
            ]
        ],
        'pages'              => [
            'tickets_open'   => [
                'label'       => tr('Open tickets'),
                'uri'         => '/reseller/ticket_system.php',
                'title_class' => 'support'
            ],
            'tickets_closed' => [
                'label'       => tr('Closed tickets'),
                'uri'         => '/reseller/ticket_closed.php',
                'title_class' => 'support'
            ],
            'new_ticket'     => [
                'label'       => tr('New ticket'),
                'uri'         => '/reseller/ticket_create.php',
                'title_class' => 'support'
            ],
            'view_ticket'    => [
                'label'       => tr('View ticket'),
                'uri'         => '/reseller/ticket_view.php',
                'title_class' => 'support',
                'visible'     => false
            ]
        ]
    ],
    'profile'       => [
        'label' => tr('Profile'),
        'uri'   => '/reseller/profile.php',
        'class' => 'profile',
        'pages' => [
            'overview'      => [
                'label'       => tr('Account summary'),
                'uri'         => '/reseller/profile.php',
                'title_class' => 'profile'
            ],
            'personal_data' => [
                'label'       => tr('Personal data'),
                'uri'         => '/reseller/personal_change.php',
                'title_class' => 'profile'
            ],
            'passsword'     => [
                'label'       => tr('Password'),
                'uri'         => '/reseller/password_update.php',
                'title_class' => 'profile'
            ],
            'language'      => [
                'label'       => tr('Language'),
                'uri'         => '/reseller/language.php',
                'title_class' => 'multilanguage'
            ],
            'layout'        => [
                'label'       => tr('Layout'),
                'uri'         => '/reseller/layout.php',
                'title_class' => 'layout'
            ]
        ]
    ]
];
