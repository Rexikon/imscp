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
use iMSCP\Functions\View;
use Zend\Uri\Http;

/**
 * Check input data
 *
 * @return void
 */
function reseller_checkData()
{
    if (!isset($_POST['dmn_name']) || $_POST['dmn_name'] == '') {
        View::setPageMessage(tr('Domain name cannot be empty.'), 'error');
        return;
    }

    $dmnName = mb_strtolower(cleanInput($_POST['dmn_name']));

    global $dmnNameValidationErrMsg;
    if (!validateDomainName($dmnName)) {
        View::setPageMessage($dmnNameValidationErrMsg, 'error');
        return;
    }

    // www is considered as an alias of the domain
    while (strpos($dmnName, 'www.') !== false) {
        $dmnName = substr($dmnName, 4);
    }

    $identity = Application::getInstance()->getAuthService()->getIdentity();

    $asciiDmnName = encodeIdna($dmnName);

    if (isWebDomainKnown($asciiDmnName, $identity->getUserId())) {
        View::setPageMessage(tr('Domain %s is unavailable.', "<strong>$dmnName</strong>"), 'error');
        return;
    }

    $forwardUrl = 'no';
    $forwardType = NULL;
    $forwardHost = 'Off';

    // Check for URL forwarding option
    if (isset($_POST['url_forwarding']) && $_POST['url_forwarding'] == 'yes' && isset($_POST['forward_type'])
        && in_array($_POST['forward_type'], ['301', '302', '303', '307', 'proxy'], true)
    ) {
        isset($_POST['forward_url_scheme']) && isset($_POST['forward_url']) or View::showBadRequestErrorPage();

        $forwardUrl = cleanInput($_POST['forward_url_scheme']) . cleanInput($_POST['forward_url']);
        $forwardType = cleanInput($_POST['forward_type']);

        if ($forwardType == 'proxy' && isset($_POST['forward_host'])) {
            $forwardHost = 'On';
        }

        try {
            $uri = new Http($forwardUrl);
            $uri->setHost(encodeIdna(mb_strtolower($uri->getHost())));
            $forwardUrl = $uri->toString();
        } catch (\Exception $e) {
            View::setPageMessage(tr('Forward URL %s is not valid: %s', "<strong>$forwardUrl</strong>", $e->getMessage()), 'error');
            return;
        }
    }

    if ((!isset($_POST['datepicker']) || $_POST['datepicker'] == '') && !isset($_POST['never_expire'])) {
        View::setPageMessage(tr('Domain expiration date must be filled.'), 'error');
        return;
    }

    $dmnExpire = isset($_POST['datepicker']) && !isset($_POST['never_expire']) ? @strtotime(cleanInput($_POST['datepicker'])) : 0;
    if ($dmnExpire === false) {
        View::setPageMessage('Invalid expiration date.', 'error');
        return;
    }

    $hpId = isset($_POST['dmn_tpl']) ? cleanInput($_POST['dmn_tpl']) : 0;
    $customizeHp = $hpId > 0 && isset($_POST['chtpl']) ? $_POST['chtpl'] : '_no_';
    $session = Application::getInstance()->getSession();

    if ($hpId == 0 || $customizeHp == '_yes_') {
        $session['dmn_name'] = $asciiDmnName;
        $session['dmn_expire'] = $dmnExpire;
        $session['dmn_url_forward'] = $forwardUrl;
        $session['dmn_type_forward'] = $forwardType;
        $session['dmn_host_forward'] = $forwardHost;
        $session['dmn_tpl'] = $hpId;
        $session['chtpl'] = '_yes_';
        $session['step_one'] = '_yes_';
        redirectTo('user_add2.php');
    }

    if (!validateHostingPlan($hpId, $identity->getUserId(), true)) {
        View::setPageMessage(tr('Hosting plan limits exceed reseller limits.'), 'error');
        return;
    }

    $session['dmn_name'] = $asciiDmnName;
    $session['dmn_expire'] = $dmnExpire;
    $session['dmn_url_forward'] = $forwardUrl;
    $session['dmn_type_forward'] = $forwardType;
    $session['dmn_host_forward'] = $forwardHost;
    $session['dmn_tpl'] = $hpId;
    $session['chtpl'] = $customizeHp;
    $session['step_one'] = '_yes_';
    redirectTo('user_add3.php');
}

/**
 * Show first page of add user with data
 *
 * @param  TemplateEngine $tpl Template engine
 * @return void
 */
function reseller_generatePage($tpl)
{
    $forwardType = isset($_POST['forward_type']) && in_array($_POST['forward_type'], ['301', '302', '303', '307', 'proxy'], true)
        ? $_POST['forward_type'] : '302';
    $forwardHost = ($forwardType == 'proxy' && isset($_POST['forward_host'])) ? 'On' : 'Off';

    $tpl->assign([
        'DOMAIN_NAME_VALUE'    => isset($_POST['dmn_name']) ? toHtml($_POST['dmn_name']) : '',
        'FORWARD_URL_YES'      => isset($_POST['url_forwarding']) && $_POST['url_forwarding'] == 'yes' ? ' checked' : '',
        'FORWARD_URL_NO'       => isset($_POST['url_forwarding']) && $_POST['url_forwarding'] == 'yes' ? '' : ' checked',
        'HTTP_YES'             => isset($_POST['forward_url_scheme']) && $_POST['forward_url_scheme'] == 'http://' ? ' selected' : '',
        'HTTPS_YES'            => isset($_POST['forward_url_scheme']) && $_POST['forward_url_scheme'] == 'https://' ? ' selected' : '',
        'FORWARD_URL'          => isset($_POST['forward_url']) ? toHtml($_POST['forward_url']) : '',
        'FORWARD_TYPE_301'     => $forwardType == '301' ? ' checked' : '',
        'FORWARD_TYPE_302'     => $forwardType == '302' ? ' checked' : '',
        'FORWARD_TYPE_303'     => $forwardType == '303' ? ' checked' : '',
        'FORWARD_TYPE_307'     => $forwardType == '307' ? ' checked' : '',
        'FORWARD_TYPE_PROXY'   => $forwardType == 'proxy' ? ' checked' : '',
        'FORWARD_HOST'         => $forwardHost == 'On' ? ' checked' : '',
        'DATEPICKER_VALUE'     => isset($_POST['datepicker']) ? toHtml($_POST['datepicker']) : '',
        'DATEPICKER_DISABLED'  => isset($_POST['datepicker']) ? '' : ' disabled',
        'NEVER_EXPIRE_CHECKED' => isset($_POST['datepicker']) ? '' : ' checked',
        'CHTPL1_VAL'           => isset($_POST['chtpl']) && $_POST['chtpl'] == '_yes_' ? ' checked' : '',
        'CHTPL2_VAL'           => isset($_POST['chtpl']) && $_POST['chtpl'] == '_yes_' ? '' : ' checked'
    ]);

    $stmt = execQuery("SELECT id, name FROM hosting_plans WHERE reseller_id = ? AND status = 1 ORDER BY name", [
        Application::getInstance()->getAuthService()->getIdentity()->getUserId()
    ]);

    if (!$stmt->rowCount()) {
        $tpl->assign('HOSTING_PLAN_ENTRIES_BLOCK', '');
        return;
    }

    while ($row = $stmt->fetch()) {
        $hpId = isset($_POST['dmn_tpl']) ? $_POST['dmn_tpl'] : '';
        $tpl->assign([
            'HP_NAME'     => toHtml($row['name']),
            'HP_ID'       => toHtml($row['id']),
            'HP_SELECTED' => $row['id'] == $hpId ? ' selected' : ''
        ]);
        $tpl->parse('HOSTING_PLAN_ENTRY_BLOCK', '.hosting_plan_entry_block');
    }
}

require_once 'application.php';

Application::getInstance()->getAuthService()->checkIdentity(AuthenticationService::RESELLER_IDENTITY_TYPE);
Application::getInstance()->getEventManager()->trigger(Events::onResellerScriptStart);

if (Application::getInstance()->getRequest()->isPost()) {
    reseller_checkData();
}

$tpl = new TemplateEngine();
$tpl->define([
    'layout'                       => 'shared/layouts/ui.tpl',
    'page'                         => 'reseller/user_add1.tpl',
    'page_message'                 => 'layout',
    'hosting_plan_entries_block'   => 'page',
    'hosting_plan_entry_block'     => 'hosting_plan_entries_block',
    'customize_hosting_plan_block' => 'hosting_plan_entries_block'
]);
$tpl->assign([
    'TR_PAGE_TITLE'             => toHtml(tr('Reseller / Customers / Add Customer')),
    'TR_ADD_USER'               => toHtml(tr('Add user')),
    'TR_CORE_DATA'              => toHtml(tr('Domain data')),
    'TR_DOMAIN_NAME'            => toHtml(tr('Domain name')),
    'TR_DOMAIN_EXPIRE'          => toHtml(tr('Domain expiration date')),
    'TR_EXPIRE_CHECKBOX'        => toHtml(tr('Never')),
    'TR_CHOOSE_HOSTING_PLAN'    => toHtml(tr('Choose hosting plan')),
    'TR_PERSONALIZE_TEMPLATE'   => toHtml(tr('Personalise template')),
    'TR_URL_FORWARDING'         => toHtml(tr('URL forwarding')),
    'TR_URL_FORWARDING_TOOLTIP' => toHtml(tr('Allows to forward any request made to this domain to a specific URL.'), 'htmlAttr'),
    'TR_FORWARD_TO_URL'         => toHtml(tr('Forward to URL')),
    'TR_YES'                    => toHtml(tr('Yes'), 'htmlAttr'),
    'TR_NO'                     => toHtml(tr('No'), 'htmlAttr'),
    'TR_HTTP'                   => toHtml('http://'),
    'TR_HTTPS'                  => toHtml('https://'),
    'TR_FORWARD_TYPE'           => toHtml(tr('Forward type')),
    'TR_301'                    => toHtml('301'),
    'TR_302'                    => toHtml('302'),
    'TR_303'                    => toHtml('303'),
    'TR_307'                    => toHtml('307'),
    'TR_PROXY'                  => toHtml(tr('Proxy')),
    'TR_PROXY_PRESERVE_HOST'    => toHtml(tr('Preserve Host')),
    'TR_NEXT_STEP'              => toHtml(tr('Next step'))
]);
View::generateNavigation($tpl);
reseller_generatePage($tpl);
View::generatePageMessages($tpl);
$tpl->parse('LAYOUT_CONTENT', 'page');
Application::getInstance()->getEventManager()->trigger(Events::onResellerScriptEnd, NULL, ['templateEngine' => $tpl]);
$tpl->prnt();
