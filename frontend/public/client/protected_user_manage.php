<?php
/**
 * i-MSCP - internet Multi Server Control Panel
 * Copyright (C) 2010-2018 by Laurent Declercq <l.declercq@nuxwin.com>
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation; either
 * version 2.1 of the License, or (at your option) any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
 * Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public
 * License along with this library; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
 */

namespace iMSCP;

use iMSCP\Authentication\AuthenticationService;
use iMSCP\Functions\Counting;
use iMSCP\Functions\View;

/**
 * Generates user action
 *
 * @access private
 * @param string $status User status
 * @return array
 */
function _client_generateUserAction($status)
{
    if ($status === 'ok') {
        return [
            tr('Delete'), "action_delete('protected_user_delete.php?uname={USER_ID}', '{UNAME}')",
            tr('Edit'), "protected_user_edit.php?uname={USER_ID}"
        ];
    }

    return [tr('N/A'), '', tr('N/A'), '#'];
}

/**
 * Generates group actions
 *
 * @access private
 * @param string $status Group status
 * @return array
 */
function _client_generateHtgroupAction($status)
{
    if ($status === 'ok') {
        return [tr('Delete'), "action_delete('protected_group_delete.php?gname={GROUP_ID}', '{GNAME}')"];
    }

    return [tr('N/A'), ''];
}

/**
 * Generates users list
 *
 * @param TemplateEngine $tpl Template engine instance
 * @return void
 */
function client_generateUsersList($tpl)
{
    $domainId = getCustomerMainDomainId(Application::getInstance()->getAuthService()->getIdentity()->getUserId());
    $stmt = execQuery('SELECT * FROM `htaccess_users` WHERE `dmn_id` = ? ORDER BY `dmn_id` DESC', [$domainId]);

    if (!$stmt->rowCount()) {
        $tpl->assign([
            'USERS_BLOCK'   => '',
            'USERS_MESSAGE' => tr('No user found.')
        ]);
        return;
    }

    $tpl->assign('USERS_MESSAGE_BLOCK', '');

    while ($row = $stmt->fetch()) {
        list($userDeleteTranslation, $userDeleteJsScript, $userEditTranslation, $htuserEditJsScript) = _client_generateUserAction($row['status']);

        $tpl->assign([
            'UNAME'              => toHtml($row['uname']),
            'USTATUS'            => humanizeItemStatus($row['status']),
            'USER_ID'            => $row['id'],
            'USER_DELETE'        => $userDeleteTranslation,
            'USER_DELETE_SCRIPT' => $userDeleteJsScript,
            'USER_EDIT'          => $userEditTranslation,
            'USER_EDIT_SCRIPT'   => $htuserEditJsScript
        ]);
        $tpl->parse('USER_BLOCK', '.user_block');
    }
}

/**
 * Generates groups list
 *
 * @param TemplateEngine $tpl Template engine instance
 * @return void
 */
function client_generateGroupsList($tpl)
{
    $domainId = getCustomerMainDomainId(Application::getInstance()->getAuthService()->getIdentity()->getUserId());
    $stmt = execQuery('SELECT * FROM htaccess_groups WHERE dmn_id = ? ORDER BY dmn_id DESC', [$domainId]);

    if (!$stmt->rowCount()) {
        $tpl->assign([
            'GROUPS_MESSAGE' => tr('No group found.'),
            'GROUP_BLOCKS'   => ''
        ]);
        return;
    }

    $tpl->assign('GROUPS_MESSAGE_BLOCK', '');

    while ($row = $stmt->fetch()) {
        list($groupDeleteTranslation, $groupDeleteJsScript) = _client_generateHtgroupAction($row['status']);

        $tpl->assign([
            'GNAME'               => toHtml($row['ugroup']),
            'GSTATUS'             => humanizeItemStatus($row['status']),
            'GROUP_ID'            => $row['id'],
            'GROUP_DELETE'        => $groupDeleteTranslation,
            'GROUP_DELETE_SCRIPT' => $groupDeleteJsScript
        ]);

        if (empty($row['members'])) {
            $tpl->assign('MEMBER', '');
        } else {
            $stmt2 = execQuery(
                'SELECT uname FROM htaccess_users WHERE id IN(' . implode(', ', array_map('quoteValue', explode(',', $row['members']))) . ')'
            );
            $tpl->assign('MEMBER', toHtml(implode(', ', $stmt2->fetchAll(\PDO::FETCH_COLUMN))));
        }

        $tpl->parse('GROUP_BLOCK', '.group_block');
    }
}

require_once 'application.php';

Application::getInstance()->getAuthService()->checkIdentity(AuthenticationService::USER_IDENTITY_TYPE);
Application::getInstance()->getEventManager()->trigger(Events::onClientScriptStart);
Counting::userHasFeature('protected_areas') or View::showBadRequestErrorPage();

$tpl = new TemplateEngine();
$tpl->define([
    'layout'               => 'shared/layouts/ui.tpl',
    'page'                 => 'client/puser_manage.tpl',
    'page_message'         => 'layout',
    'users_message_block'  => 'page',
    'users_block'          => 'page',
    'user_block'           => 'users_block',
    'groups_message_block' => 'page',
    'group_blocks'         => 'page',
    'group_block'          => 'group_blocks'
]);
$tpl->assign([
    'TR_PAGE_TITLE'     => tr('Client / Webtools / Protected Areas / Manage Users and Groups'),
    'TR_ACTIONS'        => tr('Actions'),
    'TR_USERS'          => tr('Users'),
    'TR_USERNAME'       => tr('Username'),
    'TR_ADD_USER'       => tr('Add user'),
    'TR_GROUPNAME'      => tr('Group name'),
    'TR_GROUP_MEMBERS'  => tr('Group members'),
    'TR_ADD_GROUP'      => tr('Add group'),
    'TR_CANCEL'         => tr('Cancel'),
    'TR_GROUP'          => tr('Group'),
    'TR_GROUPS'         => tr('Groups'),
    'TR_PASSWORD'       => tr('Password'),
    'TR_STATUS'         => tr('Status'),
    'TR_MESSAGE_DELETE' => tr('Are you sure you want to delete %s?', '%s'),
]);
View::generateNavigation($tpl);
client_generateUsersList($tpl);
client_generateGroupsList($tpl);
View::generatePageMessages($tpl);
$tpl->parse('LAYOUT_CONTENT', 'page');
Application::getInstance()->getEventManager()->trigger(Events::onClientScriptEnd, NULL, ['templateEngine' => $tpl]);
$tpl->prnt();
unsetMessages();
