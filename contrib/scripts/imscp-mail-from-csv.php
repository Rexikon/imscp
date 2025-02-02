<?php
/**
 * i-MSCP - internet Multi Server Control Panel
 * Copyright (C) 2010-2018 Laurent Declercq <l.declercq@nuxwin.com>
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation; either
 * version 2.1 of the License, or (at your option) any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU
 * Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public
 * License along with this library; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA 02110-1301 USA
 */

namespace iMSCP;

/**
 * Script that allows to import mail accounts into i-MSCP using a CSV file as source.
 * CSV file entries must be as follow:
 *
 * user@domain.tld,plaintext_password
 * user2@domain.tld,plaintext_password
 * ...
 */

use iMSCP\Functions\Daemon;
use iMSCP\Functions\Mail;

/**
 * Get mail data
 *
 * @param string $domainName Domain name
 * @return array Array which contains mail data
 */
function cli_getMailData($domainName)
{
    static $data = [];

    if (array_key_exists($domainName, $data) && $data[$domainName] !== NULL) {
        return $data[$domainName];
    }

    $stmt = execQuery('SELECT domain_id FROM domain WHERE domain_name = ?', [$domainName]);
    if ($stmt->rowCount()) {
        $row = $stmt->fetch();
        $data[$domainName] = [$row['domain_id'], 0, Mail::MT_NORMAL_MAIL];
        return $data[$domainName];
    }

    throw new \Exception('This script can only add mail accounts for domains which are already managed by i-MSCP.');
}

include '/var/www/imscp/frontend/library/include/application.php';

error_reporting(0);
ini_set('display_errors', 0);
ini_set('max_execution_time', 0);

// Full path to CSV file
if (!isset($argv[1])) {
    printf("USAGE: php %s <FULL_PATH_TO_CSV_FILE>\n", $argv[0]);
    exit(1);
}

$csvFilePath = $argv[1];

// csv column delimiter
$csvDelimiter = ',';

if (($handle = fopen($csvFilePath, 'r')) === false) {
    fwrite(STDERR, sprintf("ERROR: Couldn't open %s file.\n", $csvFilePath));
    exit(1);
}

$db = Application::getInstance()->getDb();
$stmt = $db->prepare(
    "
        INSERT INTO mail_users (
            mail_acc, mail_pass, mail_forward, domain_id, mail_type, sub_id, status, mail_auto_respond, mail_auto_respond_text, quota, mail_addr
        ) VALUES (
            ?, ?, '_no_', ?, ?, ?, 'toadd', '0', NULL, 0, ?
        )
    "
);
$stmt->bindParam(1, $mailUser, \PDO::PARAM_STR);
$stmt->bindParam(2, $mailPassword, \PDO::PARAM_STR);
$stmt->bindParam(3, $domainId, \PDO::PARAM_INT);
$stmt->bindParam(4, $mailType, \PDO::PARAM_STR);
$stmt->bindParam(5, $subId, \PDO::PARAM_INT);
$stmt->bindParam(6, $mailAddrACE, \PDO::PARAM_STR);

// Create i-MSCP mail accounts using entries from CSV file
while (($csvEntry = fgetcsv($handle, 1024, $csvDelimiter)) !== false) {
    $mailAddr = trim($csvEntry[0]);
    $mailAddrACE = encodeIdna($mailAddr);
    $mailPassword = trim($csvEntry[1]);

    try {
        if (!ValidateEmail($mailAddrACE)) {
            throw new \Exception(sprintf('%s is not a valid email address.', $mailAddr));
        }

        if (!checkPasswordSyntax($mailPassword)) {
            throw new \Exception(sprintf('Wrong password syntax or length for the %s mail account.', $mailAddr));
        }

        $mailPassword = \iMSCP\Crypt::sha512($mailPassword);
        list($mailUser, $mailDomain) = explode('@', $mailAddrACE);
        list($domainId, $subId, $mailType) = cli_getMailData($mailDomain);

        try {
            $stmt->execute();
            printf("`%s` has been successfully inserted in database.\n", $mailAddr);
        } catch (\PDOException $e) {
            if ($e->getCode() == 23000) {
                printf("WARN: `%s` already exists in database. Skipping...\n", $mailAddr);
            } else {
                fwrite(STDERR, sprintf("ERROR: Couldn't insert `%s in database: %s\n", $mailAddr, $e->getMessage()));
            }
        }
    } catch (\Exception $e) {
        fwrite(STDERR, sprintf("ERROR: `%s` has been skipped: %s\n", $mailAddr, $e->getMessage()));
    }
}

if (!Daemon::sendRequest()) {
    fwrite(STDERR, "ERROR: Couldn't send request to i-MSCP daemon.\n");
    exit(1);
}

print "Request has been successfully sent to i-MSCP daemon.\n";
exit(0);
