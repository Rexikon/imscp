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
use iMSCP\Model\CpSuIdentityInterface;

/**
 * Generate layout color form
 *
 * @param $tpl TemplateEngine Template engine instance
 * @return void
 */
function reseller_generateLayoutColorForm($tpl)
{
    $colors = getLayoutColorsSet();

    if (!empty($POST) && isset($_POST['layoutColor']) && in_array($_POST['layoutColor'], $colors)) {
        $selectedColor = $_POST['layoutColor'];
    } else {
        $selectedColor = getLayoutColor(Application::getInstance()->getAuthService()->getIdentity()->getUserId());
    }

    if (!empty($colors)) {
        foreach ($colors as $color) {
            $tpl->assign([
                'COLOR'          => $color,
                'SELECTED_COLOR' => ($color == $selectedColor) ? ' selected' : ''
            ]);
            $tpl->parse('LAYOUT_COLOR_BLOCK', '.layout_color_block');
        }
    } else {
        $tpl->assign('LAYOUT_COLORS_BLOCK', '');
    }
}

require_once 'application.php';

Application::getInstance()->getAuthService()->checkIdentity(AuthenticationService::RESELLER_IDENTITY_TYPE);
Application::getInstance()->getEventManager()->trigger(Events::onResellerScriptStart);

$tpl = new TemplateEngine();
$tpl->define([
    'layout'              => 'shared/layouts/ui.tpl',
    'page'                => 'reseller/layout.tpl',
    'page_message'        => 'layout',
    'logo_remove_button'  => 'page',
    'layout_colors_block' => 'page',
    'layout_color_block'  => 'layout_colors_block'
]);

$identity = Application::getInstance()->getAuthService()->getIdentity();

if (isset($_POST['uaction'])) {
    if ($_POST['uaction'] == 'updateIspLogo') {
        setUserLogo() ?:
            View::setPageMessage(tr('Logo successfully updated.'), 'success');
    } elseif ($_POST['uaction'] == 'deleteIspLogo') {
        deleteUserLogo() ?: View::setPageMessage(tr('Logo successfully removed.'), 'success');
    } elseif ($_POST['uaction'] == 'changeLayoutColor' && isset($_POST['layoutColor'])) {
        if (setLayoutColor($identity->getUserId(), $_POST['layoutColor'])) {
            if (!($identity instanceof CpSuIdentityInterface)) {
                Application::getInstance()->getSession()['user_theme_color'] = $_POST['layoutColor'];
                View::setPageMessage(tr('Layout color successfully updated.'), 'success');
            } else {
                View::setPageMessage(tr("Reseller's layout color successfully updated."), 'success');
            }
        } else {
            View::setPageMessage(tr('Unknown layout color.'), 'error');
        }
    } elseif ($_POST['uaction'] == 'changeShowLabels') {
        setMainMenuLabelsVisibility($identity->getUserId(), intval($_POST['mainMenuShowLabels']));
        View::setPageMessage(tr('Main menu labels visibility successfully updated.'), 'success');
    } else {
        View::setPageMessage(tr('Unknown action: %s', toHtml($_POST['uaction'])), 'error');
    }
}

if (isMainMenuLabelsVisible($identity->getUserId())) {
    $tpl->assign([
        'MAIN_MENU_SHOW_LABELS_ON'  => ' selected',
        'MAIN_MENU_SHOW_LABELS_OFF' => ''
    ]);
} else {
    $tpl->assign([
        'MAIN_MENU_SHOW_LABELS_ON'  => '',
        'MAIN_MENU_SHOW_LABELS_OFF' => ' selected'
    ]);
}

$ispLogo = getUserLogo(false);

if (isUserLogo($ispLogo)) {
    $tpl->parse('LOGO_REMOVE_BUTTON', '.logo_remove_button');
} else {
    $tpl->assign('LOGO_REMOVE_BUTTON', '');
}

$tpl->assign([
    'TR_PAGE_TITLE'            => tr('Reseller / Profile / Layout'),
    'OWN_LOGO'                 => $ispLogo,
    'TR_LAYOUT_SETTINGS'       => tr('Layout'),
    'TR_UPLOAD_LOGO'           => tr('Upload logo'),
    'TR_LOGO_FILE'             => tr('Logo file'),
    'TR_ENABLED'               => tr('Enabled'),
    'TR_DISABLED'              => tr('Disabled'),
    'TR_UPLOAD'                => tr('Upload'),
    'TR_REMOVE'                => tr('Remove'),
    'TR_LAYOUT_COLOR'          => tr('Layout color'),
    'TR_CHOOSE_LAYOUT_COLOR'   => tr('Choose layout color'),
    'TR_CHANGE'                => tr('Change'),
    'TR_OTHER_SETTINGS'        => tr('Other settings'),
    'TR_MAIN_MENU_SHOW_LABELS' => tr('Show labels for main menu links')
]);
View::generateNavigation($tpl);
reseller_generateLayoutColorForm($tpl);
View::generatePageMessages($tpl);
$tpl->parse('LAYOUT_CONTENT', 'page');
Application::getInstance()->getEventManager()->trigger(Events::onResellerScriptEnd, NULL, ['templateEngine' => $tpl]);
$tpl->prnt();
unsetMessages();
