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

/**
 * Get quoted and unquoted strings from the given string
 *
 * @param $string String to be parsed
 * @return array Array containing arrays of quoted and unquoted strings
 * TODO: TO be improved as current version is ugly
 */
function getQuotedAndUnquotedStrings($string)
{
    $string = trim(str_replace(["\r\n", "\n", "\r"], ' ', $string));
    $quotedStrings = $unquotedStrings = [];
    $unquotedIdx = 0;
    $escaped = $afterQuoted = false;

    for ($i = 0, $length = strlen($string); $i < $length; $i++) {
        if ($afterQuoted && $string[$i] == ' ') {
            continue;
        }

        if (!$escaped && $string[$i] == '"') {
            $quotedString = '';

            while (isset($string[++$i]) && ($escaped || $string[$i] != '"')) {
                $quotedString .= $string[$i];
                $escaped = $string[$i] == '\\';
            }

            if (isset($string[$i])) {
                if ($quotedString != '') {
                    $quotedStrings[] = $quotedString;
                }

                $afterQuoted = true;
            } else {
                $afterQuoted = false;
                $unquotedStrings[] = '"' . $quotedString;
            }

            $unquotedIdx++;
            $escaped = false;
            continue;
        }

        $afterQuoted = false;
        $escaped = $string[$i] == '\\';

        if (isset($unquotedStrings[$unquotedIdx])) {
            $unquotedStrings[$unquotedIdx] .= $string[$i];
        } else {
            $unquotedStrings[$unquotedIdx] = $string[$i];
        }
    }

    return [$quotedStrings, $unquotedStrings];
}

/**
 * Get value for the given POST variable
 *
 * @param string $varname POST variable name
 * @param string $defaultValue Default value
 * @return string
 */
function client_getPost($varname, $defaultValue = '')
{
    return isset($_POST[$varname]) ? cleanInput($_POST[$varname]) : $defaultValue;
}

/**
 * Is the given name in conflict with existent CNAME
 *
 * Cover the following cases:
 *  - CNAME and other data
 *  - CNAME RRs singleton
 *
 * @param string $rrName Name
 * @param string $rrType Type
 * @param bool $isNewRecord
 * @param string &$errorString Reference to error string
 * @return bool TRUE if a conflict is found, FALSE otherwise
 */
function hasConflict($rrName, $rrType, $isNewRecord, &$errorString)
{
    $resolver = new \Net_DNS2_Resolver(['nameservers' => ['127.0.0.1']]);

    try {
        /** @var \Net_DNS2_Packet_Response $response */
        $response = $resolver->query($rrName, 'CNAME');

        if (empty($response->answer) || (!$isNewRecord && $rrType == 'CNAME' && $rrName == $response->answer[0]->name)) {
            return false;
        }

        $errorString = tr("Conflict with the %s DNS resource record.", $response->answer[0]);
        return true;
    } catch (\Net_DNS2_Exception $e) {
        // In case of failure, we just go ahead.
    }

    return false;
}

/**
 * Validate name for a DNS resource record
 *
 * @param string $name Name
 * @param string &$errorString Error string
 * @return bool TRUE if name is valid, FALSE otherwise
 */
function client_validate_NAME($name, &$errorString)
{
    if ($name === '') {
        $errorString .= tr('The %s field cannot be empty.', tr('Name'));
        return false;
    }

    // As per RFC 1034: Names that are not host names can consist of any printable ASCII character
    // AS per RFC 4871: All DKIM keys are stored in a subdomain named "_domainkey" ...
    // Here we remove any underscore to pass hostname validation
    if (!validateDomainName(str_replace('_', '', $name))) {
        $errorString .= tr('Invalid field: %s', tr('Name'));
        return false;
    }

    return true;
}

/**
 * Validate canonical name for a CNAME DNS resource record
 *
 * @param string $cname Cname
 * @param string &$errorString Error string
 * @return bool TRUE if cname is valid, FALSE otherwise
 */
function client_validate_CNAME($cname, &$errorString)
{
    if ($cname === '') {
        $errorString .= tr('The %s field cannot be empty.', tr('Canonical name'));
        return false;
    }

    // As per RFC 1034: Names that are not host names can consist of any printable ASCII character
    // AS per RFC 4871: All DKIM keys are stored in a subdomain named "_domainkey" ...
    // Here we remove any underscore to pass hostname validation
    if (!validateDomainName(str_replace('_', '', $cname))) {
        $errorString .= tr('Invalid field: %s', tr('Canonical name'));
        return false;
    }

    return true;
}

/**
 * Validate IP address a A DNS resource record
 *
 * @param string $ip IPv4 address
 * @param string &$errorString Error string
 * @return bool
 */
function client_validate_A($ip, &$errorString)
{
    if ($ip === '') {
        $errorString .= tr('The %s field cannot be empty.', tr('IP address'));
        return false;
    }

    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
        $errorString .= tr('Invalid field: %s', tr('IP address'));
        return false;
    }

    return true;
}

/**
 * Validate IP address for a AAAA DNS resource record
 *
 * @param array $ip IPv6 address
 * @param string &$errorString Reference to variable, which contain error string
 * @return bool TRUE if the record is valid, FALSE otherwise
 */
function client_validate_AAAA($ip, &$errorString)
{
    if ($ip === '') {
        $errorString .= tr('The %s field cannot be empty.', tr('IPv6 address'));
        return false;
    }

    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
        $errorString .= tr('Invalid field: %s', tr('IPv6 address'));
        return false;
    }

    return true;
}

/**
 * Validate hostname for a MX DNS resource record
 *
 * @param string $pref MX preference
 * @param string $host MX host
 * @param string &$errorString Reference to variable, which contain error string
 * @return bool TRUE if the record is valid, FALSE otherwise
 */
function client_validate_MX($pref, $host, &$errorString)
{
    if (!isNumber($pref) || $pref > 65535) {
        View::showBadRequestErrorPage();
    }

    if ($host === '') {
        $errorString .= tr('The %s field cannot be empty.', tr('Host'));
        return false;
    }

    if (!validateDomainName($host)) {
        $errorString .= tr('Invalid field: %s', tr('Host'));
        return false;
    }

    return true;
}

/**
 * Validate hostname for a NS DNS resource record
 *
 * @param string $host MX host
 * @param string &$errorString Reference to variable, which contain error string
 * @return bool TRUE if the record is valid, FALSE otherwise
 */
function client_validate_NS($host, &$errorString)
{
    if ($host === '') {
        $errorString .= tr('The %s field cannot be empty.', tr('Host'));
        return false;
    }

    if (!validateDomainName($host)) {
        $errorString .= tr('Invalid field: %s', tr('Host'));
        return false;
    }

    return true;
}

/**
 * Validate and format SPF/TXT DNS resource record
 *
 * @param string $data DNS record data
 * @param string &$errorString Reference to variable, which contain error string
 * @return bool TRUE if the record is valid, FALSE otherwise
 */
function client_validateAndFormat_TXT(&$data, &$errorString)
{
    $data = trim($data, "\t\n\r\0\x0B\x28\x29");

    if ($data === '') {
        $errorString .= tr("The '%s' field cannot be empty.", tr('Data'));
        return false;
    }

    if (!preg_match('/^[[:print:]\s]+$/', $data)) {
        $errorString .= tr("Invalid character found in the '%s' field. Only printable ASCII characters and line breaks are allowed.", tr('Data'));
        return false;
    }

    list($quotedStrings, $unquotedStrings) = getQuotedAndUnquotedStrings($data);

    if (!empty($quotedStrings) && !empty($unquotedStrings)) {
        $errorString .= tr('The %s field cannot have both unquoted strings and quoted strings.', tr('Data'));
        return false;
    }

    if (!empty($unquotedStrings)) {
        foreach ($unquotedStrings as $unquotedString) {
            if (preg_match('/(?<!\\\\)(?:\\\\{2})*\K"/', $unquotedString)) {
                $errorString .= tr("Unescaped quote found in '%s' field. A quote that is not a string delimiter must be escaped.", tr('Data'));
                return false;
            }
        }
    }

    $data = join('', empty($quotedStrings) ? $unquotedStrings : $quotedStrings);

    // Split <character-string> into several <character-string>s when
    // <character-string> is longer than 255 bytes (excluding delimiters).
    // See: https://tools.ietf.org/html/rfc4408#section-3.1.3
    if (strlen($data) > 255) {
        $quotedStrings = [];

        for ($i = 0; $length = strlen($data), $i < $length; $i += 255) {
            $quotedStrings[] = '"' . substr($data, $i, 255) . '"';
        }

        $data = join(' ', $quotedStrings);
    } else {
        $data = '"' . $data . '"';
    }

    return true;
}

/**
 * Validate SRV DNS record
 *
 * @param string $srvName Service name
 * @param string $proto Protocol
 * @param string $priority Priority
 * @param string $weight Weight
 * @param int $port Port
 * @param string $host Target host
 * @param string $errorString Error string
 * @return bool
 */
function client_validate_SRV($srvName, $proto, $priority, $weight, $port, $host, &$errorString)
{
    if ($srvName === '') {
        $errorString .= tr('The %s field cannot be empty.', tr('Service name'));
        return false;
    }

    if (!preg_match('/^_[a-z0-9]+/i', $srvName)) {
        $errorString .= tr('Invalid field: %s', tr('Service name'));
        return false;
    }

    in_array($proto, ['udp', 'tcp', 'tls']) or View::showBadRequestErrorPage();

    if (!isNumber($priority) || $priority > 65535) {
        View::showBadRequestErrorPage();
    }

    if (!isNumber($weight) || $weight > 65535) {
        View::showBadRequestErrorPage();
    }

    if ($port === '') {
        $errorString .= tr('The %s field cannot be empty.', tr('Target port'));
        return false;
    }

    if (!isNumber($port)) {
        $errorString .= tr('Target port must be a number.');
        return false;
    }

    if ($host === '') {
        $errorString .= tr('The %s field cannot be empty.', tr('Host'));
        return false;
    }

    if (!validateDomainName($host)) {
        $errorString .= tr('Invalid field: %s', tr('Host'));
        return false;
    }

    return true;
}

/**
 * Validate TTL for a DNS resource record
 *
 * @param int $ttl TTL value
 * @return int TTL
 */
function client_validate_TTL($ttl)
{
    if (!isNumber($ttl) || $ttl < 60 || $ttl > 2147483647) {
        View::showBadRequestErrorPage();
    }

    return $ttl;
}

/**
 * Create HTML option elements
 *
 * @param array $data Options data
 * @param null|string $value
 * @return string
 */
function client_create_options($data, $value = NULL)
{
    $options = "\n";
    reset($data);

    foreach ($data as $item) {
        $options .= "\t\t\t\t\t" . '<option value="' . $item . '"' . ($item == $value ? ' selected' : '') . '>' . $item . "</option>\n";
    }

    return $options;
}

/**
 * Decode DNS record data
 *
 * @param array|null $data DNS record data or NULL
 * @return array
 */
function client_decodeDnsRecordData($data)
{
    $ipv4 = $ipv6 = $srvName = $srvProto = $cname = $txt = $name = $dnsTTL = $srvTargetPort = $srvTargetHost = '';
    $ownedBy = 'custom_dns_feature';

    $srvPrio = 0; // Default priority for SRV records
    $srvWeight = 0; // Default weight for SRV records
    $ttl = 3600; // Default TTL (1 hour)

    if (is_array($data)) {
        # Extract name and ttl field for any record type excepted SRV record
        if ($data['domain_type'] != 'SRV' && preg_match('/^(?P<name>([^\s]+))(?:\s+(?P<ttl>\d+))?/', $data['domain_dns'], $matches)) {
            $name = $matches['name'];
            $ttl = isset($matches['ttl']) ? $matches['ttl'] : $ttl;
        }

        $ownedBy = $data['owned_by'];

        switch ($data['domain_type']) {
            case 'A':
                $ipv4 = $data['domain_text'];
                break;
            case 'AAAA':
                $ipv6 = $data['domain_text'];
                break;
            case 'CNAME':
                $cname = $data['domain_text'];
                break;
            case 'MX':
                # Extract priority and host fields
                if (preg_match('/^(?P<pref>\d+)\s+(?P<host>[^\s]+)/', $data['domain_text'], $matches)) {
                    $srvPrio = $matches['pref'];
                    $srvTargetHost = $matches['host'];
                }
                break;
            case 'NS':
                $srvTargetHost = $data['domain_text'];
                break;
            case 'SRV':
                # Extract service name, protocol name, owner name and ttl fields
                if (preg_match('/^(?P<srvname>_[^\s.]+)\.(?P<proto>_[^\s.]+)\.(?P<name>[^\s]+)\s+(?P<ttl>\d+)/', $data['domain_dns'], $matches)) {
                    $srvName = $matches['srvname'];
                    $srvProto = $matches['proto'];
                    $name = $matches['name'];
                    $ttl = $matches['ttl'];
                }

                # Extract priority, weight, port and target fields
                if (preg_match('/^(?P<prio>\d+)\s+(?P<weight>\d+)\s(?P<port>\d+)\s+(?P<host>[^\s]+)/', $data['domain_text'], $matches)) {
                    $srvPrio = $matches['prio'];
                    $srvWeight = $matches['weight'];
                    $srvTargetPort = $matches['port'];
                    $srvTargetHost = $matches['host'];
                }
                break;
            default:
                $txt = $data['domain_text'];
        }
    }

    return [$name, $ipv4, $ipv6, $srvName, $srvProto, $ttl, $srvPrio, $srvWeight, $srvTargetPort, $srvTargetHost, $cname, $txt, $ownedBy];
}

/**
 * Check and save DNS record
 *
 * @param int $dnsRecordId DNS record unique identifier (0 for new record)
 * @return bool
 */
function client_saveDnsRecord($dnsRecordId)
{
    $error = false;
    $mainDmnProps = getClientProperties(Application::getInstance()->getAuthService()->getIdentity()->getUserId());
    $mainDmnId = $mainDmnProps['domain_id'];
    $errorString = '';
    $dnsRecordClass = client_getPost('class');

    if ($dnsRecordId == 0) {
        $dnsRecordType = client_getPost('type');

        if ($dnsRecordClass != 'IN' || !in_array($dnsRecordType, ['A', 'AAAA', 'CNAME', 'MX', 'NS', 'SPF', 'SRV', 'TXT'])) {
            View::showBadRequestErrorPage();
        }

        if (client_getPost('zone_id') == 0) {
            $domainName = $mainDmnProps['domain_name'];
            $domainId = 0;
        } else {
            $stmt = execQuery('SELECT alias_id, alias_name FROM domain_aliases WHERE alias_id = ? AND domain_id = ?', [
                intval($_POST['zone_id']), $mainDmnId
            ]);
            $stmt->rowCount() or View::showBadRequestErrorPage();
            $row = $stmt->fetch();
            $domainName = $row['alias_name'];
            $domainId = $row['alias_id'];
        }
    } else {
        $stmt = execQuery(
            '
                SELECT t1.*, IFNULL(t3.alias_name, t2.domain_name) AS domain_name, IFNULL(t3.alias_status, t2.domain_status) AS domain_status
                FROM domain_dns AS t1
                LEFT JOIN domain AS t2 USING(domain_id)
                LEFT JOIN domain_aliases AS t3 USING (alias_id)
                WHERE domain_dns_id = ?
                AND t1.domain_id = ?
            ',
            [$dnsRecordId, $mainDmnId]
        );

        $stmt->rowCount() or View::showBadRequestErrorPage();
        $row = $stmt->fetch();
        $domainId = $row['alias_id'] ? $row['alias_id'] : $row['domain_id'];
        $domainName = $row['domain_name'];
        $dnsRecordType = $row['domain_type'];
    }

    $nameValidationError = '';
    $dnsRecordName = mb_strtolower(client_getPost('dns_name'));
    $ttl = client_validate_TTL(client_getPost('dns_ttl')); // Raise a bad request error page on invalid TTL

    // Substitute @ sign and blank with $ORIGIN
    if ($dnsRecordName === '@' || $dnsRecordName === '') {
        $dnsRecordName = $domainName . '.';
    } // No fully-qualified name, complete it
    elseif ($dnsRecordName != '' && substr($dnsRecordName, -1) !== '.') {
        $dnsRecordName .= '.' . $domainName . '.';
    }

    $dnsRecordName = encodeIdna($dnsRecordName);

    # Disallow out-of-zone record
    if ($dnsRecordName !== '' && !preg_match("/(?:.*?\\.)?$domainName\\.$/", $dnsRecordName)) {
        View::setPageMessage(tr("Couldn't validate DNS resource record: %s", 'out-of-zone data'), 'error');
        $error = true;
    } else {
        // Remove trailing dot for validation process (will be re-added after)
        $dnsRecordName = rtrim($dnsRecordName, '.');
        if (!client_validate_NAME($dnsRecordName, $nameValidationError)) {
            View::setPageMessage(tr("Couldn't validate DNS resource record: %s", $nameValidationError), 'error');
            $error = true;
        }
    }

    if ($error) {
        return false;
    }

    switch ($dnsRecordType) {
        case 'A':
            $dnsRecordData = client_getPost('dns_A_address');

            // Process validation
            if (!client_validate_A($dnsRecordData, $errorString)) {
                View::setPageMessage(tr("Couldn't validate DNS resource record: %s", $errorString), 'error');
                $error = true;
            }
            break;
        case 'AAAA':
            $dnsRecordData = client_getPost('dns_AAAA_address');

            // Process validation
            if (!client_validate_AAAA($dnsRecordData, $errorString)) {
                View::setPageMessage(tr("Couldn't validate DNS resource record: %s", $errorString), 'error');
                $error = true;
            }
            break;
        case 'CNAME':
            $dnsRecordData = mb_strtolower(client_getPost('dns_cname'));

            // Not a fully-qualified canonical name; append $ORIGIN to it
            if ($dnsRecordData !== '' && substr($dnsRecordData, -1) !== '.') {
                $dnsRecordData .= '.' . $domainName;
            }

            // Remove trailing dot for validation process (will be re-added after)
            $dnsRecordData = rtrim($dnsRecordData, '.');
            // Convert to punycode
            $dnsRecordData = encodeIdna($dnsRecordData);

            // Process validation
            if (!client_validate_CNAME($dnsRecordData, $errorString)) {
                View::setPageMessage(tr("Couldn't validate DNS resource record: %s", $errorString), 'error');
                $error = true;
            }

            $dnsRecordData .= '.';
            break;
        case'MX':
            $pref = client_getPost('dns_srv_prio');
            $host = mb_strtolower(client_getPost('dns_srv_host'));

            // Not a fully-qualified host; append $ORIGIN to it
            if ($host !== '' && substr($host, -1) !== '.') {
                $host .= '.' . $domainName;
            }

            // Remove trailing dot for validation process (will be re-added after)
            $host = rtrim($host, '.');
            // Convert to punycode
            $host = encodeIdna($host);

            // Process validation
            if (!client_validate_MX($pref, $host, $errorString)) {
                View::setPageMessage(tr("Couldn't validate DNS resource record: %s", $errorString), 'error');
                $error = true;
            }

            $dnsRecordData = sprintf('%d %s.', $pref, $host);
            break;
        case 'NS';
            $host = mb_strtolower(client_getPost('dns_srv_host'));

            // Not a fully-qualified host; append $ORIGIN to it
            if ($host !== '' && substr($host, -1) !== '.') {
                $host .= '.' . $domainName;
            }

            // Remove trailing dot for validation process (will be re-added after)
            $host = rtrim($host, '.');

            // Convert to punycode
            $host = encodeIdna($host);

            // Process validation
            if (!client_validate_NS($host, $errorString)) {
                View::setPageMessage(tr("Couldn't validate DNS resource record: %s", $errorString), 'error');
                $error = true;
            } elseif ($dnsRecordName == $domainName) {
                View::setPageMessage(
                    tr("Couldn't validate DNS resource record: %s", tr('NS DNS resource records are only allowed for subzone delegation.')), 'error'
                );
                $error = true;
            }

            $dnsRecordData = $host . '.';
            break;
        case 'SRV':
            $srvName = mb_strtolower(client_getPost('dns_srv_name'));
            $srvProto = client_getPost('srv_proto');
            $srvPrio = client_getPost('dns_srv_prio');
            $srvWeight = client_getPost('dns_srv_weight');
            $srvPort = client_getPost('dns_srv_port');
            $srvTarget = mb_strtolower(client_getPost('dns_srv_host'));

            // Not a fully-qualified target host; append $ORIGIN to it
            if ($srvTarget != '' && substr($srvTarget, -1) !== '.') {
                $srvTarget .= '.' . $domainName;
            }

            // Remove trailing dot for validation process (will be re-added after)
            $srvTarget = rtrim($srvTarget, '.');
            // Convert to punycode
            $srvTarget = encodeIdna($srvTarget);

            // Process validation
            if (!client_validate_SRV($srvName, $srvProto, $srvPrio, $srvWeight, $srvPort, $srvTarget, $errorString)) {
                View::setPageMessage(tr("Couldn't validate DNS resource record: %s", $errorString), 'error');
                $error = true;
            }

            $dnsRecordName = sprintf('%s._%s.%s', $srvName, $srvProto, $dnsRecordName);
            $dnsRecordData = sprintf('%d %d %d %s.', $srvPrio, $srvWeight, $srvPort, $srvTarget);
            break;
        case 'SPF':
        case 'TXT':
            $dnsRecordData = client_getPost('dns_txt_data');

            // Process validation and formatting
            if (!client_validateAndFormat_TXT($dnsRecordData, $errorString)) {
                View::setPageMessage(tr("Couldn't validate DNS resource record: %s", $errorString), 'error');
                $error = true;
            }

            break;
        default :
            View::showBadRequestErrorPage();
            exit;
    }

    if ($error) {
        return false;
    }

    // Check for conflict with existent CNAME
    // Check removed because it prevent user to override default CNAME record
    // See 10_named_override_default_rr.pl listener file
    //if (hasConflict($dnsRecordName, $dnsRecordType, ($dnsRecordId > 0) ? false : true, $errorString)) {
    //    set_page_message(tr("Couldn't validate DNS resource record: %s", $errorString), 'error');
    //    $error = true;
    //}

    //if ($error) {
    //    return false;
    //}

    $dnsRecordName .= '.'; // Add trailing dot
    $dnsRecordName .= "\t$ttl"; // Add TTL

    $db = Application::getInstance()->getDb();

    try {
        $db->getDriver()->getConnection()->beginTransaction();

        if (!$dnsRecordId) {
            Application::getInstance()->getEventManager()->trigger(Events::onBeforeAddCustomDNSrecord, NULL, [
                'domainId' => $mainDmnId,
                'aliasId'  => $domainId,
                'name'     => $dnsRecordName,
                'class'    => $dnsRecordClass,
                'type'     => $dnsRecordType,
                'data'     => $dnsRecordData
            ]);
            execQuery(
                "
                  INSERT INTO domain_dns (domain_id, alias_id, domain_dns, domain_class, domain_type, domain_text, owned_by, domain_dns_status)
                  VALUES (?, ?, ?, ?, ?, ?, 'custom_dns_feature', 'toadd')
                ",
                [$mainDmnId, $domainId, $dnsRecordName, $dnsRecordClass, $dnsRecordType, $dnsRecordData,]
            );
            // Also update status of any DNS resource record with error
            execQuery(
                "
                  UPDATE domain_dns
                  SET domain_dns_status = 'tochange'
                  WHERE domain_id = ?
                  AND domain_dns_status NOT IN('ok', 'toadd', 'tochange', 'todelete', 'disabled')
                ",
                [$mainDmnId]
            );
            Application::getInstance()->getEventManager()->trigger(Events::onAfterAddCustomDNSrecord, NULL, [
                'id'       => $db->getDriver()->getLastGeneratedValue(),
                'domainId' => $mainDmnId,
                'aliasId'  => $domainId,
                'name'     => $dnsRecordName,
                'class'    => $dnsRecordClass,
                'type'     => $dnsRecordType,
                'data'     => $dnsRecordData
            ]);
        } else {
            Application::getInstance()->getEventManager()->trigger(Events::onBeforeEditCustomDNSrecord, NULL, [
                'id'       => $dnsRecordId,
                'domainId' => $mainDmnId,
                'aliasId'  => $domainId,
                'name'     => $dnsRecordName,
                'class'    => $dnsRecordClass,
                'type'     => $dnsRecordType,
                'data'     => $dnsRecordData
            ]);
            execQuery(
                "
                  UPDATE domain_dns
                  SET domain_dns = ?, domain_class = ?, domain_type = ?, domain_text = ?, domain_dns_status = 'tochange'
                  WHERE domain_dns_id = ?
                ",
                [$dnsRecordName, $dnsRecordClass, $dnsRecordType, $dnsRecordData, $dnsRecordId]
            );
            // Also update status of any DNS resource record with error
            execQuery(
                "
                  UPDATE domain_dns
                  SET domain_dns_status = 'tochange'
                  WHERE domain_id = ?
                  AND domain_dns_status NOT IN('ok', 'toadd', 'tochange', 'todelete', 'disabled')
                ",
                [$mainDmnId]
            );
            Application::getInstance()->getEventManager()->trigger(Events::onAfterEditCustomDNSrecord, NULL, [
                'id'       => $dnsRecordId,
                'domainId' => $mainDmnId,
                'aliasId'  => $domainId,
                'name'     => $dnsRecordName,
                'class'    => $dnsRecordClass,
                'type'     => $dnsRecordType,
                'data'     => $dnsRecordData
            ]);
        }

        $db->getDriver()->getConnection()->commit();
        Daemon::sendRequest();
        writeLog(
            sprintf('DNS resource record has been scheduled for %s by %s', $dnsRecordId ? tr('update') : tr('addition'), getProcessorUsername(Application::getInstance()->getAuthService()->getIdentity())),
            E_USER_NOTICE
        );
    } catch (\Exception $e) {
        $db->getDriver()->getConnection()->rollBack();
        if ($e->getCode() == 23000) { // Duplicate entries
            View::setPageMessage(tr('DNS record already exist.'), 'error');
            return false;
        }

        throw $e;
    }

    return true;
}

/**
 * Generate page
 *
 * @param TemplateEngine $tpl
 * @param int $dnsRecordId DNS record unique identifier (0 for new record)
 * @return void
 */
function generatePage($tpl, $dnsRecordId)
{
    $mainDomainId = getCustomerMainDomainId(Application::getInstance()->getAuthService()->getIdentity()->getUserId());

    // Add DNS record
    if ($dnsRecordId == 0) {
        $stmt = execQuery(
            "
                SELECT '0' AS domain_id, domain_name FROM domain WHERE domain_id = ?
                UNION
                SELECT alias_id AS domain_id, alias_name AS domain_name FROM domain_aliases
                WHERE domain_id = ? AND alias_status <> ?
            ",
            [$mainDomainId, $mainDomainId, 'ordered']
        );

        $domainId = client_getPost('zone_id', '0');
        $selectOptions = "\n";

        while ($data = $stmt->fetch()) {
            $selectOptions .= "\t\t\t\t\t" . '<option value="' . $data['domain_id'] . '"' .
                ($data['domain_id'] == $domainId ? ' selected' : '') . '>' . decodeIdna($data['domain_name']) . "</option>\n";
        }

        $tpl->assign([
            'SELECT_ZONES'      => $selectOptions,
            'DNS_TYPE_DISABLED' => ''
        ]);
    } // Edit DNS record
    else {
        $stmt = execQuery('SELECT * FROM domain_dns WHERE domain_dns_id = ? AND domain_id = ?', [$dnsRecordId, $mainDomainId]);
        $stmt->rowCount() or View::showBadRequestErrorPage();
        $data = $stmt->fetch();
        $tpl->assign([
            'ADD_RECORD'        => '',
            'DNS_TYPE_DISABLED' => ' disabled'
        ]);
    }

    list($name, $ipv4, $ipv6, $srvName, $srvProto, $srvTTL, $srvPriority, $srvWeight, $srvTargetPort, $srvTargetHost, $cname, $txt, $ownedBy
        ) = client_decodeDnsRecordData($data);

    // Protection against edition (eg. for external mail MX record)
    $ownedBy == 'custom_dns_feature' or View::showBadRequestErrorPage();
    $dnsTypes = client_create_options(['A', 'AAAA', 'SRV', 'CNAME', 'MX', 'NS', 'SPF', 'TXT'], client_getPost('type', $data['domain_type']));
    $dnsClasses = client_create_options(['IN'], client_getPost('class', $data['domain_class']));
    $tpl->assign([
        'ID'                      => toHtml($dnsRecordId),
        'DNS_SRV_NAME'            => toHtml(client_getPost('dns_srv_name', decodeIdna($srvName))),
        'SELECT_DNS_SRV_PROTOCOL' => client_create_options(['tcp', 'udp', 'tls'], client_getPost('srv_proto', $srvProto)),
        'DNS_NAME'                => toHtml(client_getPost('dns_name', decodeIdna($name))),
        'DNS_TTL'                 => toHtml(client_getPost('dns_ttl', $srvTTL)),
        'SELECT_DNS_TYPE'         => $dnsTypes,
        'SELECT_DNS_CLASS'        => $dnsClasses,
        'DNS_ADDRESS'             => toHtml(client_getPost('dns_A_address', $ipv4)),
        'DNS_ADDRESS_V6'          => toHtml(client_getPost('dns_AAAA_address', $ipv6)),
        'DNS_SRV_PRIO'            => toHtml(client_getPost('dns_srv_prio', $srvPriority)),
        'DNS_SRV_WEIGHT'          => toHtml(client_getPost('dns_srv_weight', $srvWeight)),
        'DNS_SRV_PORT'            => toHtml(client_getPost('dns_srv_port', $srvTargetPort)),
        'DNS_SRV_HOST'            => toHtml(client_getPost('dns_srv_host', decodeIdna($srvTargetHost))),
        'DNS_CNAME'               => toHtml(client_getPost('dns_cname', decodeIdna($cname))),
        'DNS_TXT_DATA'            => toHtml(client_getPost('dns_txt_data', $txt))
    ]);
}

require_once 'application.php';

Application::getInstance()->getAuthService()->checkIdentity(AuthenticationService::USER_IDENTITY_TYPE);
Application::getInstance()->getEventManager()->trigger(Events::onClientScriptStart);
Counting::userHasFeature('dnsEditor') or View::showBadRequestErrorPage();

$dnsRecordId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (Application::getInstance()->getRequest()->isPost()) {
    if (client_saveDnsRecord($dnsRecordId)) {
        if ($dnsRecordId > 0) {
            View::setPageMessage(tr('DNS resource record scheduled for update.'), 'success');
        } else {
            View::setPageMessage(tr('DNS resource record scheduled for addition.'), 'success');
        }

        redirectTo('domains_manage.php');
    }
}

$tpl = new TemplateEngine();
$tpl->define([
    'layout'       => 'shared/layouts/ui.tpl',
    'page'         => 'client/dns_edit.tpl',
    'page_message' => 'layout',
    'logged_from'  => 'page'
]);
$tpl->assign([
    'TR_PAGE_TITLE'        => $dnsRecordId > 0
        ? toHtml(tr('Client / Domain / Edit DNS resource record')) : toHtml(tr('Client / Domains / Add DNS resource record')),
    'ACTION_MODE'          => ($dnsRecordId > 0) ? 'dns_edit.php?id={ID}' : 'dns_add.php',
    'TR_CUSTOM_DNS_RECORD' => toHtml(tr('DNS resource record')),
    'TR_ZONE'              => toHtml(tr('Zone')),
    'TR_ZONE_HELP'         => toHtml(tr('DNS zone in which you want add this DNS resource record.'), 'htmlAttr'),
    'TR_NAME'              => toHtml(tr('Name')),
    'TR_DNS_TYPE'          => toHtml(tr('Type')),
    'TR_DNS_CLASS'         => toHtml(tr('Class')),
    'TR_DNS_NAME'          => toHtml(tr('Name')),
    'TR_DNS_SRV_NAME'      => toHtml(tr('Service name')),
    'TR_DNS_IP_ADDRESS'    => toHtml(tr('IP address')),
    'TR_DNS_IP_ADDRESS_V6' => toHtml(tr('IPv6 address')),
    'TR_DNS_SRV_PROTOCOL'  => toHtml(tr('Service protocol')),
    'TR_DNS_TTL'           => toHtml(tr('TTL')),
    'TR_DNS_SRV_PRIO'      => toHtml(tr('Priority')),
    'TR_DNS_SRV_WEIGHT'    => toHtml(tr('Relative weight')),
    'TR_DNS_SRV_HOST'      => toHtml(tr('Host')),
    'TR_DNS_SRV_PORT'      => toHtml(tr('Target port')),
    'TR_DNS_CNAME'         => toHtml(tr('Canonical name')),
    'TR_DNS_TXT_DATA'      => toHtml(tr('Data')),
    'TR_ADD'               => toHtml(tr('Add'), 'htmlAttr'),
    'TR_SEC'               => toHtml(tr('Sec.')),
    'TR_UPDATE'            => toHtml(tr('Update'), 'htmlAttr'),
    'TR_CANCEL'            => toHtml(tr('Cancel'))
]);
$tpl->assign(($dnsRecordId > 0) ? 'FORM_ADD_MODE' : 'FORM_EDIT_MODE', '');
View::generateNavigation($tpl);
generatePage($tpl, $dnsRecordId);
View::generatePageMessages($tpl);
$tpl->parse('LAYOUT_CONTENT', 'page');
Application::getInstance()->getEventManager()->trigger(Events::onClientScriptEnd, NULL, ['templateEngine' => $tpl]);
$tpl->prnt();
unsetMessages();
