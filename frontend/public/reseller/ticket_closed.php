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

Application::getInstance()->getAuthService()->checkIdentity(AuthenticationService::RESELLER_IDENTITY_TYPE);
Application::getInstance()->getEventManager()->trigger(Events::onResellerScriptStart);
Counting::userHasFeature('supportSystem') or View::showBadRequestErrorPage();

!isset($_GET['ticket_id']) or SupportSystem::reopenTicket(intval($_GET['ticket_id']));

if (isset($_GET['psi'])) {
    $start = $_GET['psi'];
} else {
    $start = 0;
}

$tpl = new TemplateEngine();
$tpl->define([
    'layout'           => 'shared/layouts/ui.tpl',
    'page'             => 'reseller/ticket_closed.tpl',
    'page_message'     => 'layout',
    'tickets_list'     => 'page',
    'tickets_item'     => 'tickets_list',
    'scroll_prev_gray' => 'page',
    'scroll_prev'      => 'page',
    'scroll_next_gray' => 'page',
    'scroll_next'      => 'page'
]);
$tpl->assign([
    'TR_PAGE_TITLE'                 => tr('Reseller / Support / Closed Tickets'),
    'TR_TICKET_STATUS'              => tr('Status'),
    'TR_TICKET_FROM'                => tr('From'),
    'TR_TICKET_SUBJECT'             => tr('Subject'),
    'TR_TICKET_URGENCY'             => tr('Priority'),
    'TR_TICKET_LAST_ANSWER_DATE'    => tr('Last reply date'),
    'TR_TICKET_ACTION'              => tr('Actions'),
    'TR_TICKET_DELETE'              => tr('Delete'),
    'TR_TICKET_READ_LINK'           => tr('Read ticket'),
    'TR_TICKET_DELETE_LINK'         => tr('Delete ticket'),
    'TR_TICKET_REOPEN'              => tr('Reopen'),
    'TR_TICKET_REOPEN_LINK'         => tr('Reopen ticket'),
    'TR_TICKET_DELETE_ALL'          => tr('Delete all tickets'),
    'TR_TICKETS_DELETE_MESSAGE'     => tr("Are you sure you want to delete the '%s' ticket?", '%s'),
    'TR_TICKETS_DELETE_ALL_MESSAGE' => tr('Are you sure you want to delete all closed tickets?'),
    'TR_PREVIOUS'                   => tr('Previous'),
    'TR_NEXT'                       => tr('Next')
]);
View::generateNavigation($tpl);
SupportSystem::generateTicketList(
    $tpl,
    Application::getInstance()->getAuthService()->getIdentity()->getUserId(),
    $start,
    Application::getInstance()->getConfig()['DOMAIN_ROWS_PER_PAGE'],
    'reseller',
    'closed'
);
View::generatePageMessages($tpl);
$tpl->parse('LAYOUT_CONTENT', 'page');
Application::getInstance()->getEventManager()->trigger(Events::onResellerScriptEnd, NULL, ['templateEngine' => $tpl]);
$tpl->prnt();
unsetMessages();
