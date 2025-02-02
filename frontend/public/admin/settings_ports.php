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
use Zend\EventManager\Event;

/**
 * Validates a service port and sets an appropriate message on error
 *
 * @param string $name Service name
 * @param string $ip Ip address
 * @param int $port Port
 * @param string $protocol Protocle
 * @param bool $show Tell whether or not service must be show on status page
 * @param string $index Item index on update, empty value otherwise
 * @return bool TRUE if valid, FALSE otherwise
 */
function validatesService($name, $ip, $port, $protocol, $show, $index = '')
{
    global $services;
    if (Application::getInstance()->getRegistry()->has('errorFieldsIds')) {
        $errorFieldsIds = Application::getInstance()->getRegistry()->get('errorFieldsIds');
    } else {
        $errorFieldsIds = [];
    }

    $dbServiceName = "PORT_$name";
    $ip = ($ip == 'localhost') ? '127.0.0.1' : $ip;

    if (!preg_match('/^[\w\-]+$/D', $name)) {
        View::setPageMessage(tr("Invalid service name: %s", $name), 'error');
        $errorFieldsIds[] = "name$index";
    } elseif (strlen($name) > 25) {
        View::setPageMessage(tr("Service name cannot be greater than 25 characters.", $name), 'error');
        $errorFieldsIds[] = "name$index";
    }

    if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
        View::setPageMessage(tr('Wrong IP address.'), 'error');
        $errorFieldsIds[] = "ip$index";
    }

    if (!isNumber($port) || $port < 1 || $port > 65535) {
        View::setPageMessage(tr('Only numbers in range from 0 to 65535 are allowed.'), 'error');
        $errorFieldsIds[] = "port$index";
    }

    if (!is_int($index) && isset($dbConfig[$dbServiceName])) {
        View::setPageMessage(tr('Service with same name already exists.'), 'error');
        $errorFieldsIds[] = "name$index";
    }

    if (($protocol != 'tcp' && $protocol != 'udp') || ($show != '0' && $show != '1')) {
        View::showBadRequestErrorPage();
    }

    if (Application::getInstance()->getRegistry()->has('errorFieldsIds')) {
        Application::getInstance()->getRegistry()->set(
            'errorFieldsIds', Application::getInstance()->getRegistry()->get('errorFieldsIds') + $errorFieldsIds
        );
    } elseif (!empty($errorFieldsIds)) {
        Application::getInstance()->getRegistry()->set('errorFieldsIds', $errorFieldsIds);
    }

    return empty($errorFieldsIds);
}

/**
 * Remove a service port from the database
 *
 * @param string $serviceName Service name
 * @return bool TRUE on success, FALSE otherwise
 */
function deleteService($serviceName)
{
    $dbConfig = Application::getInstance()->getDbConfig();

    if (!isset($dbConfig[$serviceName])) {
        View::setPageMessage(tr("Unknown service name '%s'.", $serviceName), 'error');
        return false;
    }

    unset($dbConfig[$serviceName]);
    writeLog(sprintf(
        'A service port (%s) has been removed by %s', $serviceName, Application::getInstance()->getAuthService()->getIdentity()->getUsername()),
        E_USER_NOTICE
    );
    View::setPageMessage(tr('Service port successfully removed.'), 'success');
    return true;
}

/**
 * Adds or updates services ports
 *
 * @param string $mode Mode in witch act (add or update)
 * @return void
 */
function addOrUpdateServices($mode = 'add')
{
    $dbConfig = Application::getInstance()->getDbConfig();

    if ($mode == 'add') {
        if (!isset($_POST['port_new']) || !isset($_POST['port_type_new']) || !isset($_POST['port_type_new']) || !isset($_POST['show_val_new'])
            || !isset($_POST['ip_new'])
        ) {
            View::showBadRequestErrorPage();
        }

        $port = cleanInput($_POST['port_new']);
        $protocol = cleanInput($_POST['port_type_new']);
        $name = strtoupper(cleanInput($_POST['name_new']));
        $show = cleanInput($_POST['show_val_new']);
        $ip = cleanInput($_POST['ip_new']);

        if (validatesService($name, $ip, $port, $protocol, $show)) {
            $dbServiceName = "PORT_$name";
            $dbConfig[$dbServiceName] = "$port;$protocol;$name;$show;$ip";
            writeLog(sprintf('A service port (%s:%s) has been added by %s', $name, $port, Application::getInstance()->getAuthService()->getIdentity()->getUsername()), E_USER_NOTICE);
        }
    } elseif ($mode == 'update') {
        if (!isset($_POST['name']) || !is_array($_POST['name']) || !isset($_POST['var_name']) || !is_array($_POST['var_name']) || !isset($_POST['ip'])
            || !is_array($_POST['ip']) || !isset($_POST['port']) || !is_array($_POST['port']) || !isset($_POST['port_type'])
            || !is_array($_POST['port_type']) || !isset($_POST['show_val']) || !is_array($_POST['show_val'])
        ) {
            View::showBadRequestErrorPage();
        }

        // Reset counter of update queries
        $dbConfig->resetQueriesCounter(DbConfig::UPDATE_QUERY_COUNTER);

        foreach ($_POST['name'] as $index => $name) {
            $name = strtoupper(cleanInput($name));
            $ip = cleanInput($_POST['ip'][$index]);
            $port = cleanInput($_POST['port'][$index]);
            $protocol = cleanInput($_POST['port_type'][$index]);
            $show = $_POST['show_val'][$index];

            if (validatesService($name, $ip, $port, $protocol, $show, $index)) {
                $dbServiceName = $_POST['var_name'][$index];
                $dbConfig[$dbServiceName] = "$port;$protocol;$name;$show;$ip";
            }
        }
    } else {
        throw new \Exception('addOrUpdateServices(): Wrong argument for $mode');
    }

    if (Application::getInstance()->getRegistry()->has('errorFieldsIds')) {
        if ($mode == 'add') {
            Application::getInstance()->getRegistry()->set('error_on_add', [
                'name_new'      => $_POST['name_new'],
                'ip_new'        => $_POST['ip_new'],
                'port_new'      => $_POST['port_new'],
                'port_type_new' => $_POST['port_type_new'],
                'show_val_new'  => $_POST['show_val_new']
            ]);
        } else {
            $errorOnUpdt = [];
            foreach ($_POST['var_name'] as $index => $service) {
                $name = $_POST['name'][$index];
                $ip = $_POST['ip'][$index];
                $port = $_POST['port'][$index];
                $protocol = $_POST['port_type'][$index];
                $show = $_POST['show_val'][$index];
                $errorOnUpdt[] = "$port;$protocol;$name;$show;$ip";
            }

            Application::getInstance()->getRegistry()->set('error_on_updt', $errorOnUpdt);
        }

        return;
    }

    if ($mode == 'add') {
        View::setPageMessage(tr('Service port successfully added'), 'success');
        return;
    }

    $updateCount = $dbConfig->countQueries(DbConfig::UPDATE_QUERY_COUNTER);

    if ($updateCount > 0) {
        View::setPageMessage(ntr('Service port has been updated.', '%d service ports were updated.', $updateCount, $updateCount), 'success');
    } else {
        View::setPageMessage(tr('Nothing has been changed.'), 'info');
    }

    redirectTo('settings_ports.php');
}

/**
 * Generate page
 *
 * @param TemplateEngine $tpl
 * @return void;
 */
function generatePage($tpl)
{
    global $services;

    if (Application::getInstance()->getRegistry()->has('error_on_updt')) {
        $values = new \ArrayObject(Application::getInstance()->getRegistry()->get('error_on_updt'));
        $services = array_keys($values->getArrayCopy());
    } else {
        $values = Application::getInstance()->getDbConfig();
        $services = array_filter(array_keys($values->getArrayCopy()), function ($name) {
            return (strlen($name) > 5 && substr($name, 0, 5) == 'PORT_');
        });

        if (Application::getInstance()->getRegistry()->has('error_on_add')) {
            $errorOnAdd = new \ArrayObject(Application::getInstance()->getRegistry()->get('error_on_add'));
        }
    }

    if (empty($services)) {
        $tpl->assign('SERVICE_PORTS', '');
        View::setPageMessage(tr('There are no service ports yet.'), 'static_info');
        return;
    }

    foreach ($services as $index => $service) {
        list($port, $protocol, $name, $status, $ip) = explode(';', $values->{$service});

        $tpl->assign([
            'NAME'         => toHtml($name, 'htmlAttr'),
            'TR_DELETE'    => toHtml(tr('Delete')),
            'DELETE_ID'    => toUrl($service),
            'NUM'          => toHtml($index, 'htmlAttr'),
            'VAR_NAME'     => toHtml($service, 'htmlAttr'),
            'IP'           => ($ip == 'localhost') ? '127.0.0.1' : (!$ip ? '0.0.0.0' : toHtml($ip, 'htmlAttr')),
            'PORT'         => toHtml($port, 'htmlAttr'),
            'SELECTED_UDP' => ($protocol == 'udp') ? ' selected' : '',
            'SELECTED_TCP' => ($protocol == 'udp') ? '' : ' selected',
            'SELECTED_ON'  => ($status) ? ' selected' : '',
            'SELECTED_OFF' => ($status) ? '' : ' selected'
        ]);
        $tpl->parse('SERVICE_PORTS', '.service_ports');
    }

    $tpl->assign(
        isset($errorOnAdd) ? [
            'VAL_FOR_NAME_NEW' => $errorOnAdd['name_new'],
            'VAL_FOR_IP_NEW'   => $errorOnAdd['ip_new'],
            'VAL_FOR_PORT_NEW' => $errorOnAdd['port_new']
        ] : [
            'VAL_FOR_NAME_NEW' => '',
            'VAL_FOR_IP_NEW'   => '',
            'VAL_FOR_PORT_NEW' => ''
        ]
    );

    $tpl->assign(
        'ERROR_FIELDS_IDS',
        Application::getInstance()->getRegistry()->has('errorFieldsIds')
            ? json_encode(Application::getInstance()->getRegistry()->get('errorFieldsIds')) : '[]'
    );
}

require_once 'application.php';

Application::getInstance()->getAuthService()->checkIdentity(AuthenticationService::ADMIN_IDENTITY_TYPE);
Application::getInstance()->getEventManager()->trigger(Events::onAdminScriptStart);

$services = new Services;

if (isset($_POST['uaction']) && $_POST['uaction'] != 'reset') {
    addOrUpdateServices((cleanInput($_POST['uaction'])));
} elseif (isset($_GET['delete'])) {
    deleteService(cleanInput($_GET['delete']));
}

$tpl = new TemplateEngine();
$tpl->define([
    'layout'        => 'shared/layouts/ui.tpl',
    'page'          => 'admin/settings_ports.tpl',
    'page_message'  => 'layout',
    'service_ports' => 'page'
]);
$tpl->assign([
    'TR_PAGE_TITLE'            => toHtml(tr('Admin / Settings / Service Ports')),
    'TR_YES'                   => toHtml(tr('Yes'), 'htmlAttr'),
    'TR_NO'                    => toHtml(tr('No'), 'htmlAttr'),
    'TR_SERVICE'               => toHtml(tr('Service name')),
    'TR_IP'                    => toHtml(tr('IP address')),
    'TR_PORT'                  => toHtml(tr('Port')),
    'TR_PROTOCOL'              => toHtml(tr('Protocol')),
    'TR_SHOW'                  => toHtml(tr('Show')),
    'TR_DELETE'                => toHtml(tr('Delete')),
    'TR_MESSAGE_DELETE'        => toJs(tr('Are you sure you want to delete the %s service port ?', '%s')),
    'TR_ACTION'                => toHtml(tr('Actions')),
    'VAL_FOR_SUBMIT_ON_UPDATE' => toHtml(tr('Update'), 'htmlAttr'),
    'VAL_FOR_SUBMIT_ON_ADD'    => toHtml(tr('Add'), 'htmlAttr'),
    'VAL_FOR_SUBMIT_ON_RESET'  => toHtml(tr('Reset'), 'htmlAttr')
]);

Application::getInstance()->getEventManager()->attach(Events::onGetJsTranslations, function (Event $e) {
    $e->getParam('translations')->core['dataTable'] = View::getDataTablesPluginTranslations(false);
});

View::generateNavigation($tpl);
generatePage($tpl);
View::generatePageMessages($tpl);
$tpl->parse('LAYOUT_CONTENT', 'page');
Application::getInstance()->getEventManager()->trigger(Events::onAdminScriptEnd, NULL, ['templateEngine' => $tpl]);
$tpl->prnt();
unsetMessages();
