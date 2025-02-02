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
use iMSCP\Functions\SupportSystem;
use iMSCP\Functions\View;

require_once 'application.php';

Application::getInstance()->getAuthService()->checkIdentity(AuthenticationService::USER_IDENTITY_TYPE);
Application::getInstance()->getEventManager()->trigger(Events::onClientScriptStart);
Counting::userHasFeature('supportSystem') or View::showBadRequestErrorPage();

if (isset($_POST['uaction'])) {
    if (empty($_POST['subject'])) {
        View::setPageMessage(tr('Please specify a message subject.'), 'error');
    } elseif (empty($_POST['user_message'])) {
        View::setPageMessage(tr('Please type your message.'), 'error');
    } else {
        $identity = Application::getInstance()->getAuthService()->getIdentity();
        SupportSystem::createTicket($identity->getUserId(), $identity->getUserCreatedBy(), $_POST['urgency'], $_POST['subject'], $_POST['user_message'], 1);
        redirectTo('ticket_system.php');
    }
}

$userdata = [
    'OPT_URGENCY_1' => '',
    'OPT_URGENCY_2' => '',
    'OPT_URGENCY_3' => '',
    'OPT_URGENCY_4' => ''
];

if (isset($_POST['urgency'])) {
    $userdata['URGENCY'] = intval($_POST['urgency']);
} else {
    $userdata['URGENCY'] = 2;
}

switch ($userdata['URGENCY']) {
    case 1:
        $userdata['OPT_URGENCY_1'] = ' selected';
        break;
    case 3:
        $userdata['OPT_URGENCY_3'] = ' selected';
        break;
    case 4:
        $userdata['OPT_URGENCY_4'] = 'selected';
        break;
    default:
        $userdata['OPT_URGENCY_2'] = ' selected';
}

$userdata['SUBJECT'] = isset($_POST['subject']) ? cleanInput($_POST['subject']) : '';
$userdata['USER_MESSAGE'] = isset($_POST['user_message']) ? cleanInput($_POST['user_message']) : '';

$tpl = new TemplateEngine();
$tpl->define('layout', 'shared/layouts/ui.tpl');
$tpl->define([
    'page'         => 'client/ticket_create.tpl',
    'page_message' => 'layout'
]);
$tpl->assign([
    'TR_PAGE_TITLE'     => tr('Client / Support / New Ticket'),
    'TR_NEW_TICKET'     => tr('New ticket'),
    'TR_LOW'            => tr('Low'),
    'TR_MEDIUM'         => tr('Medium'),
    'TR_HIGH'           => tr('High'),
    'TR_VERY_HIGH'      => tr('Very high'),
    'TR_URGENCY'        => tr('Priority'),
    'TR_EMAIL'          => tr('Email'),
    'TR_SUBJECT'        => tr('Subject'),
    'TR_YOUR_MESSAGE'   => tr('Your message'),
    'TR_SEND_MESSAGE'   => tr('Send message'),
    'TR_OPEN_TICKETS'   => tr('Open tickets'),
    'TR_CLOSED_TICKETS' => tr('Closed tickets')
]);
$tpl->assign($userdata);
View::generateNavigation($tpl);
View::generatePageMessages($tpl);
$tpl->parse('LAYOUT_CONTENT', 'page');
Application::getInstance()->getEventManager()->trigger(Events::onClientScriptEnd, NULL, ['templateEngine' => $tpl]);
$tpl->prnt();
unsetMessages();

