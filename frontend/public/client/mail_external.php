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
use iMSCP\Functions\Daemon;
use iMSCP\Functions\View;
use Zend\EventManager\Event;

/**
 * Activate or deactivate external mail feature for the given domain
 *
 * @param string $action Action to be done (activate|deactivate)
 * @param int $domainId Domain unique identifier
 * @param string $domainType Domain type
 * @return void
 */
function updateExternalMailFeature($action, $domainId, $domainType)
{
    $identity = Application::getInstance()->getAuthService()->getIdentity();
    $db = Application::getInstance()->getDb();

    try {
        $db->getDriver()->getConnection()->beginTransaction();

        if ($domainType == 'dmn') {
            $stmt = execQuery(
                "
                    UPDATE domain
                    SET domain_status = 'tochange', external_mail = ?
                    WHERE domain_id = ?
                    AND domain_admin_id = ?
                    AND domain_status = 'ok'
                ",
                [$action == 'activate' ? 'on' : 'off', $domainId, $identity->getUserId()]
            );
            $stmt->rowCount() or View::showBadRequestErrorPage();
            execQuery("UPDATE subdomain SET subdomain_status = 'tochange' WHERE domain_id = ?", [$domainId]);
        } elseif ($domainType == 'als') {
            $stmt = execQuery(
                "
                    UPDATE domain_aliases AS t1
                    JOIN domain AS t2 USING(domain_id)
                    SET t1.alias_status = 'tochange', t1.external_mail = ?
                    WHERE t1.alias_id = ?
                    AND t1.alias_status = 'ok'
                    AND t2.domain_admin_id = ?
                ",
                [$action == 'activate' ? 'on' : 'off', $domainId, $identity->getUserId()]
            );
            $stmt->rowCount() or View::showBadRequestErrorPage();
            execQuery(
                "
                    UPDATE subdomain_alias AS t1
                    JOIN domain_aliases AS t2 ON(t2.domain_id = ?)
                    SET subdomain_alias_status = 'tochange'
                    WHERE t1.alias_id = t2.alias_id
                ",
                $domainId
            );
        } else {
            View::showBadRequestErrorPage();
        }

        $db->getDriver()->getConnection()->commit();

        if ($action == 'activate') {
            writeLog(sprintf('External mail feature has been activated by %s', getProcessorUsername($identity)));
            View::setPageMessage(tr('External mail server feature scheduled for activation.'), 'success');
            return;
        }

        writeLog(sprintf('External mail feature has been deactivated by %s', getProcessorUsername($identity)));
        View::setPageMessage(tr('External mail server feature scheduled for deactivation.'), 'success');
    } catch (\Exception $e) {
        $db->getDriver()->getConnection()->rollBack();
        throw $e;
    }
}

/**
 * Generate an external mail server item
 *
 * @access private
 * @param TemplateEngine $tpl Template instance
 * @param string $externalMail Status of external mail for the domain
 * @param int $domainId Domain id
 * @param string $domainName Domain name
 * @param string $status Item status
 * @param string $type Domain type (normal for domain or alias for domain alias)
 * @return void
 */
function generateItem(TemplateEngine $tpl, $externalMail, $domainId, $domainName, $status, $type)
{
    if ($status == 'ok') {
        if ($externalMail == 'off') {
            $tpl->assign([
                'DOMAIN'          => decodeIdna($domainName),
                'STATUS'          => $status == 'ok' ? tr('Deactivated') : humanizeItemStatus($status),
                'DOMAIN_TYPE'     => $type,
                'DOMAIN_ID'       => $domainId,
                'TR_ACTIVATE'     => $status == 'ok' ? tr('Activate') : tr('N/A'),
                'DEACTIVATE_LINK' => ''
            ]);
            $tpl->parse('ACTIVATE_LINK', 'activate_link');
            return;
        }

        $tpl->assign([
            'DOMAIN'        => decodeIdna($domainName),
            'STATUS'        => $status == 'ok' ? tr('Activated') : humanizeItemStatus($status),
            'DOMAIN_TYPE'   => $type,
            'DOMAIN_ID'     => $domainId,
            'ACTIVATE_LINK' => '',
            'TR_DEACTIVATE' => $status == 'ok' ? tr('Deactivate') : tr('N/A'),
        ]);
        $tpl->parse('DEACTIVATE_LINK', 'deactivate_link');
        return;
    }

    $tpl->assign([
        'DOMAIN'          => decodeIdna($domainName),
        'STATUS'          => humanizeItemStatus($status),
        'ACTIVATE_LINK'   => '',
        'DEACTIVATE_LINK' => ''
    ]);
}

/**
 * Generate external mail server item list
 *
 * @access private
 * @param TemplateEngine $tpl Template engine
 * @param int $domainId Domain id
 * @param string $domainName Domain name
 * @return void
 */
function generateItemList(TemplateEngine $tpl, $domainId, $domainName)
{
    $stmt = execQuery('SELECT domain_status, external_mail FROM domain WHERE domain_id = ?', [$domainId]);
    $data = $stmt->fetch();

    generateItem($tpl, $data['external_mail'], $domainId, $domainName, $data['domain_status'], 'dmn');
    $tpl->parse('ITEM', '.item');

    $stmt = execQuery('SELECT alias_id, alias_name, alias_status, external_mail FROM domain_aliases WHERE domain_id = ?', [$domainId]);

    if (!$stmt->rowCount()) {
        return;
    }

    while ($data = $stmt->fetch()) {
        generateItem($tpl, $data['external_mail'], $data['alias_id'], $data['alias_name'], $data['alias_status'], 'als');
        $tpl->parse('ITEM', '.item');
    }
}

/**
 * Generates page
 *
 * @param TemplateEngine $tpl
 * @return void
 */
function generatePage($tpl)
{
    Application::getInstance()->getEventManager()->attach(Events::onGetJsTranslations, function (Event $e) {
        $translations = $e->getParam('translations');
        $translations['core']['datatable'] = View::getDataTablesPluginTranslations(false);
    });

    $tpl->assign([
        'TR_PAGE_TITLE' => toHtml(tr('Client / Mail / External Mail Feature')),
        'TR_INTRO'      => toHtml(tr('Below you can activate the external mail feature for your domains, including subdomains. If you do so, you must not forgot to add the DNS MX and SPF records for your external mail server through the custom DNS interface, or through your own DNS management interface if you make use of an external DNS server.')),
        'TR_DOMAIN'     => toHtml(tr('Domain')),
        'TR_STATUS'     => toHtml(tr('Status')),
        'TR_ACTION'     => toHtml(tr('Action')),
        'TR_DEACTIVATE' => toHtml(tr('Deactivate')),
        'TR_CANCEL'     => toHtml(tr('Cancel'))
    ]);

    $domainProps = getClientProperties(Application::getInstance()->getAuthService()->getIdentity()->getUserId());
    $domainId = $domainProps['domain_id'];
    $domainName = $domainProps['domain_name'];
    generateItemList($tpl, $domainId, $domainName);
}

require_once 'application.php';

Application::getInstance()->getAuthService()->checkIdentity(AuthenticationService::USER_IDENTITY_TYPE);
Application::getInstance()->getEventManager()->trigger(Events::onClientScriptStart);
Counting::userHasFeature('mailExternalServer') or View::showBadRequestErrorPage();

if (isset($_GET['action']) && isset($_GET['domain_id']) && isset($_GET['domain_type'])) {
    $action = cleanInput($_GET['action']);
    $domainId = intval($_GET['domain_id']);
    $domainType = cleanInput($_GET['domain_type']);

    switch ($action) {
        case 'activate':
        case 'deactivate':
            updateExternalMailFeature($action, $domainId, $domainType);
            Daemon::sendRequest();
            break;
        default:
            View::showBadRequestErrorPage();
    }

    redirectTo('mail_external.php');
}

$tpl = new TemplateEngine();
$tpl->define([
    'layout'          => 'shared/layouts/ui.tpl',
    'page'            => 'client/mail_external.tpl',
    'page_message'    => 'layout',
    'item'            => 'page',
    'activate_link'   => 'item',
    'deactivate_link' => 'item'
]);
View::generateNavigation($tpl);
generatePage($tpl);
View::generatePageMessages($tpl);
$tpl->parse('LAYOUT_CONTENT', 'page');
Application::getInstance()->getEventManager()->trigger(Events::onClientScriptEnd, NULL, ['templateEngine' => $tpl]);
$tpl->prnt();
unsetMessages();
