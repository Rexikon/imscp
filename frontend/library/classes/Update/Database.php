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

namespace iMSCP\Update;

use iMSCP\Application;
use iMSCP\Crypt as Crypt;
use iMSCP\PHPini;
use phpseclib\Crypt\RSA;
use Zend\Db\Exception\RuntimeException;
use Zend\Uri\Http;

/**
 * Class UpdateDatabase
 * @package iMSCP\Update
 */
class Database extends DatabaseAbstract
{
    /**
     * @var int Last database update revision
     */
    protected $lastUpdate = 287;

    /**
     * Decrypt any SSL private key
     *
     * @return array|null SQL statements to be executed
     */
    public function r178()
    {
        $sqlQueries = [];
        $stmt = execQuery('SELECT cert_id, password, `key` FROM ssl_certs');

        if (!$stmt->rowCount()) {
            return NULL;
        }

        while ($row = $stmt->fetch()) {
            $certId = quoteValue($row['cert_id']);
            $privateKey = new RSA();

            if ($row['password'] != '') {
                $privateKey->setPassword($row['password']);
            }

            if (!$privateKey->loadKey($row['key'], RSA::PRIVATE_FORMAT_PKCS1)) {
                $sqlQueries[] = "DELETE FROM ssl_certs WHERE cert_id = $certId";
                continue;
            }

            // Clear out passphrase
            $privateKey->setPassword();
            // Get unencrypted private key
            $privateKey = $privateKey->getPrivateKey();
            $privateKey = quoteValue($privateKey);
            $sqlQueries[] = "UPDATE ssl_certs SET `key` = $privateKey WHERE cert_id = $certId";
        }

        return $sqlQueries;
    }

    /**
     * Remove password column from the ssl_certs table
     *
     * @return null|string SQL statements to be executed
     */
    public function r179()
    {
        return $this->dropColumn('ssl_certs', 'password');
    }

    /**
     * Drop deprecated columns -- Those are not removed when upgrading from some older versions
     *
     * @return array SQL statements to be executed
     */
    public function r270()
    {
        return [
            $this->dropColumn('reseller_props', 'php_ini_al_register_globals'),
            $this->dropColumn('domain', 'phpini_perm_register_globals'),
            $this->dropColumn('php_ini', 'register_globals')
        ];
    }

    /**
     * Prohibit upgrade from i-MSCP versions older than 1.1.x
     *
     */
    protected function r173()
    {
        throw new RuntimeException('Upgrade support for i-MSCP versions older than 1.1.0 has been removed. You must first upgrade to i-MSCP version 1.3.8, then upgrade to this newest version.');
    }

    /**
     * Remove domain.domain_created_id column
     *
     * @return null|string SQL statement to be executed
     */
    protected function r174()
    {
        return $this->dropColumn('domain', 'domain_created_id');
    }

    /**
     * Update sql_database and sql_user table structure
     *
     * @return array SQL statements to be executed
     */
    protected function r176()
    {
        return [
            // sql_database table update
            $this->changeColumn('sql_database', 'domain_id', 'domain_id INT(10) UNSIGNED NOT NULL'),
            $this->changeColumn('sql_database', 'sqld_name', 'sqld_name VARCHAR(64) CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL'),
            // sql_user table update
            $this->changeColumn('sql_user', 'sqld_id', 'sqld_id INT(10) UNSIGNED NOT NULL'),
            $this->changeColumn('sql_user', 'sqlu_name', 'sqlu_name VARCHAR(16) CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL'),
            $this->changeColumn('sql_user', 'sqlu_pass', 'sqlu_pass VARCHAR(64) CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL'),
            $this->addColumn('sql_user', 'sqlu_host', 'VARCHAR(255) CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL AFTER sqlu_name'),
            $this->addIndex('sql_user', 'sqlu_name', 'INDEX', 'sqlu_name'),
            $this->addIndex('sql_user', 'sqlu_host', 'INDEX', 'sqlu_host')
        ];
    }

    /**
     * Fix SQL user hosts
     *
     * @return array SQL statements to be executed
     */
    protected function r177()
    {
        $sqlQueries = [];
        $sqlUserHost = Application::getInstance()->getConfig()['DATABASE_USER_HOST'];

        if ($sqlUserHost == '127.0.0.1') {
            $sqlUserHost = 'localhost';
        }

        $sqlUserHost = quoteValue($sqlUserHost);
        $stmt = execQuery('SELECT DISTINCT sqlu_name FROM sql_user');

        if ($stmt->rowCount()) {
            while ($row = $stmt->fetch()) {
                $sqlUser = quoteValue($row['sqlu_name']);
                $sqlQueries[] = "UPDATE IGNORE mysql.user SET Host = $sqlUserHost WHERE User = $sqlUser AND Host NOT IN ($sqlUserHost, '%')";
                $sqlQueries[] = "UPDATE IGNORE mysql.db SET Host = $sqlUserHost WHERE User = $sqlUser AND Host NOT IN ($sqlUserHost, '%')";
                $sqlQueries[] = "UPDATE sql_user SET sqlu_host = $sqlUserHost WHERE sqlu_name = $sqlUser AND sqlu_host NOT IN ($sqlUserHost, '%')";
            }

            $sqlQueries[] = 'FLUSH PRIVILEGES';
        }

        return $sqlQueries;
    }

    /**
     * Rename ssl_certs.id column to ssl_certs.domain_id
     *
     * @return null|string SQL statement to be executed
     */
    protected function r180()
    {
        return $this->changeColumn('ssl_certs', 'id', 'domain_id INT(10) NOT NULL');
    }

    /**
     * Rename ssl_certs.type column to ssl_certs.domain_type
     *
     * @return null|string SQL statement to be executed
     */
    protected function r181()
    {
        return $this->changeColumn(
            'ssl_certs', 'type', "domain_type ENUM('dmn','als','sub','alssub') CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL DEFAULT 'dmn'"
        );
    }

    /**
     * Rename ssl_certs.key column to ssl_certs.private_key
     *
     * @return null|string SQL statement to be executed
     */
    protected function r182()
    {
        return $this->changeColumn('ssl_certs', 'key', 'private_key TEXT CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL');
    }

    /**
     * Rename ssl_certs.cert column to ssl_certs.certificate
     *
     * @return null|string SQL statement to be executed
     */
    protected function r183()
    {
        return $this->changeColumn('ssl_certs', 'cert', 'certificate TEXT CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL');
    }

    /**
     * Rename ssl_certs.ca_cert column to ssl_certs.ca_bundle
     *
     * @return null|string SQL statement to be executed
     */
    protected function r184()
    {
        return $this->changeColumn('ssl_certs', 'ca_cert', 'ca_bundle TEXT CHARACTER SET utf8 COLLATE utf8_unicode_ci NULL DEFAULT NULL');
    }

    /**
     * Drop index id from ssl_certs table
     *
     * @return null|string SQL statement to be executed
     */
    protected function r185()
    {
        return $this->dropIndexByName('ssl_certs', 'id');
    }

    /**
     * Add domain_id_domain_type index in ssl_certs table
     *
     * @return null|string SQL statement to be executed
     */
    protected function r186()
    {
        return $this->addIndex('ssl_certs', ['domain_id', 'domain_type'], 'UNIQUE', 'domain_id_domain_type');
    }

    /**
     * SSL certificates normalization
     *
     * @return array|null SQL statements to be executed
     */
    protected function r189()
    {
        $sqlQueries = [];
        $stmt = execQuery('SELECT cert_id, private_key, certificate, ca_bundle FROM ssl_certs');

        if (!$stmt->rowCount()) {
            return NULL;
        }

        while ($row = $stmt->fetch()) {
            $certificateId = quoteValue($row['cert_id']);
            // Data normalization
            $privateKey = quoteValue(str_replace("\r\n", "\n", trim($row['private_key'])) . PHP_EOL);
            $certificate = quoteValue(str_replace("\r\n", "\n", trim($row['certificate'])) . PHP_EOL);
            $caBundle = quoteValue(str_replace("\r\n", "\n", trim($row['ca_bundle'])));
            $sqlQueries[] = "
                UPDATE ssl_certs SET private_key = $privateKey, certificate = $certificate, ca_bundle = $caBundle WHERE cert_id = $certificateId
            ";
        }

        return $sqlQueries;
    }

    /**
     * Delete deprecated Web folder protection parameter
     *
     * @return null
     */
    protected function r190()
    {
        if (isset($this->dbConfig['WEB_FOLDER_PROTECTION'])) {
            unset($this->dbConfig['WEB_FOLDER_PROTECTION']);
        }

        return NULL;
    }

    /**
     * #1143: Add po_active column (mail_users table)
     *
     * @return null|string SQL statement to be executed
     */
    protected function r191()
    {
        return $this->addColumn('mail_users', 'po_active', "VARCHAR(3) COLLATE utf8_unicode_ci NOT NULL DEFAULT 'yes' AFTER status");
    }

    /**
     * #1143: Remove any mail_users.password prefix
     *
     * @return string SQL statement to be executed
     */
    protected function r192()
    {
        return "UPDATE mail_users SET mail_pass = SUBSTRING(mail_pass, 4), po_active = 'no' WHERE mail_pass <> '_no_' AND status = 'disabled'";
    }

    /**
     * #1143: Add status and po_active columns index (mail_users table)
     *
     * @return array SQL statements to be executed
     */
    protected function r193()
    {
        return [
            $this->addIndex('mail_users', 'mail_addr', 'INDEX', 'mail_addr'),
            $this->addIndex('mail_users', 'status', 'INDEX', 'status'),
            $this->addIndex('mail_users', 'po_active', 'INDEX', 'po_active')
        ];
    }

    /**
     * Added plugin_priority column in plugin table
     *
     * @return array SQL statements to be executed
     */
    protected function r194()
    {
        return [
            $this->addColumn('plugin', 'plugin_priority', "INT(11) UNSIGNED NOT NULL DEFAULT '0' AFTER plugin_config"),
            $this->addIndex('plugin', 'plugin_priority', 'INDEX', 'plugin_priority')
        ];
    }

    /**
     * Remove deprecated MAIL_WRITER_EXPIRY_TIME configuration parameter
     *
     * @return null
     */
    protected function r195()
    {
        if (isset($this->dbConfig['MAIL_WRITER_EXPIRY_TIME'])) {
            unset($this->dbConfig['MAIL_WRITER_EXPIRY_TIME']);
        }

        return NULL;
    }

    /**
     * Remove deprecated MAIL_BODY_FOOTPRINTS configuration parameter
     *
     * @return null
     */
    protected function r196()
    {
        if (isset($this->dbConfig['MAIL_BODY_FOOTPRINTS'])) {
            unset($this->dbConfig['MAIL_BODY_FOOTPRINTS']);
        }

        return NULL;
    }

    /**
     * Remove postgrey and policyd-weight ports
     *
     * @return null
     */
    protected function r198()
    {
        if (isset($this->dbConfig['PORT_POSTGREY'])) {
            unset($this->dbConfig['PORT_POSTGREY']);
        }

        if (isset($this->dbConfig['PORT_POLICYD-WEIGHT'])) {
            unset($this->dbConfig['PORT_POLICYD-WEIGHT']);
        }

        return NULL;
    }

    /**
     * Add domain_dns.domain_dns_status column
     *
     * @return string SQL statement to be executed
     */
    protected function r199()
    {
        return $this->addColumn('domain_dns', 'domain_dns_status', "VARCHAR(255) CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL DEFAULT 'ok'");
    }

    /**
     * Add plugin.plugin_config_prev column
     *
     * @return array|null SQL statements to be executed
     */
    protected function r200()
    {
        $sql = $this->addColumn(
            'plugin', 'plugin_config_prev', "VARCHAR(255) CHARACTER SET utf8 COLLATE utf8_unicode_ci DEFAULT NULL AFTER plugin_config"
        );

        if ($sql !== NULL) {
            return [$sql, 'UPDATE plugin SET plugin_config_prev = plugin_config'];
        }

        return NULL;
    }

    /**
     * Fixed: Wrong field type for the plugin.plugin_config_prev column
     *
     * @return array SQL statements to be executed
     */
    protected function r201()
    {
        return [
            $this->changeColumn(
                'plugin', 'plugin_config_prev', 'plugin_config_prev TEXT CHARACTER SET utf8 COLLATE utf8_unicode_ci NULL DEFAULT NULL'
            ),
            'UPDATE plugin SET plugin_config_prev = plugin_config'
        ];
    }

    /**
     * Change domain.allowbackup column length and update values for backup feature
     *
     * @return array SQL statements to be executed
     */
    protected function r203()
    {
        return [
            $this->changeColumn('domain', 'allowbackup', "allowbackup varchar(12) COLLATE utf8_unicode_ci NOT NULL DEFAULT 'dmn|sql|mail'"),
            "UPDATE domain SET allowbackup = REPLACE(allowbackup, 'full', 'dmn|sql|mail')",
            "UPDATE domain SET allowbackup = REPLACE(allowbackup, 'no', '')"
        ];
    }

    /**
     * Add plugin.plugin_lock field
     *
     * @return string SQL statement to be executed
     */
    protected function r206()
    {
        return $this->addColumn('plugin', 'plugin_locked', "TINYINT UNSIGNED NOT NULL DEFAULT '0'");
    }

    /**
     * Remove index on server_traffic.traff_time column if any
     *
     * @return string SQL statement to be executed
     */
    protected function r208()
    {
        return $this->dropIndexByName('server_traffic', 'traff_time');
    }

    /**
     * #IP-582 PHP editor - PHP configuration levels (per_user, per_domain and per_site) are ignored
     * - Adds php_ini.admin_id and php_ini.domain_type columns
     * - Adds admin_id, domain_id and domain_type indexes
     * - Populates the php_ini.admin_id column for existent records
     *
     * @return array SQL statements to be executed
     */
    protected function r211()
    {
        return [
            $this->addColumn('php_ini', 'admin_id', 'INT(10) NOT NULL AFTER `id`'),
            $this->addColumn(
                'php_ini', 'domain_type', "VARCHAR(15) CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL DEFAULT 'dmn' AFTER `domain_id`"
            ),
            $this->addIndex('php_ini', 'admin_id', 'KEY'),
            $this->addIndex('php_ini', 'domain_id', 'KEY'),
            $this->addIndex('php_ini', 'domain_type', 'KEY'),
            "UPDATE php_ini JOIN domain USING(domain_id) SET admin_id = domain_admin_id WHERE domain_type = 'dmn'"
        ];
    }

    /**
     * Makes the PHP mail function disableable
     * - Adds reseller_props.php_ini_al_mail_function permission column
     * - Adds domain.phpini_perm_mail_function permission column
     *
     * @return array SQL statements to be executed
     */
    protected function r212()
    {
        $sqlQueries = [];

        // Add permission column for resellers
        $sqlQueries[] = $this->addColumn(
            'reseller_props', 'php_ini_al_mail_function', "VARCHAR(15) NOT NULL DEFAULT 'yes' AFTER `php_ini_al_disable_functions`"
        );
        # Add permission column for clients
        $sqlQueries[] = $this->addColumn(
            'domain', 'phpini_perm_mail_function', "VARCHAR(20) NOT NULL DEFAULT 'yes' AFTER `phpini_perm_disable_functions`"
        );

        return $sqlQueries;
    }

    /**
     * Deletes obsolete PHP editor configuration options
     * PHP configuration options defined at administrator level are no longer supported
     *
     * @return string SQL statement to be executed
     */
    protected function r213()
    {
        return "DELETE FROM config WHERE name LIKE 'PHPINI_%'";
    }

    /**
     * Update default value for the php_ini.error_reporting column
     *
     * @return string SQL statement to be executed
     */
    protected function r214()
    {
        return $this->changeColumn(
            'php_ini',
            'error_reporting',
            "error_reporting VARCHAR(255) CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL DEFAULT 'E_ALL & ~E_DEPRECATED & ~E_STRICT'"
        );
    }

    /**
     * Add status column in ftp_users table
     *
     * @return string SQL statements to be executed
     */
    protected function r217()
    {
        return $this->addColumn('ftp_users', 'status', "varchar(255) collate utf8_unicode_ci NOT NULL DEFAULT 'ok'");
    }

    /**
     * Add default value for the domain.external_mail_dns_ids field
     * Add default value for the domain_aliasses.external_mail_dns_ids field
     *
     * @return array SQL statements to be executed
     */
    protected function r218()
    {
        return [
            $this->changeColumn(
                'domain', 'external_mail_dns_ids', "external_mail_dns_ids VARCHAR(255) CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL DEFAULT ''"
            ),
            $this->changeColumn(
                'domain_aliasses',
                'external_mail_dns_ids',
                "external_mail_dns_ids VARCHAR(255) CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL DEFAULT ''"
            )
        ];
    }

    /**
     * Add SPF custom DNS record type
     *
     * @return string SQL statements to be executed
     */
    protected function r219()
    {
        return $this->changeColumn(
            'domain_dns',
            'domain_type',
            "
                `domain_type` ENUM(
                    'A','AAAA','CERT','CNAME','DNAME','GPOS','KEY','KX','MX','NAPTR','NSAP','NS','NXT','PTR','PX','SIG',
                    'SRV','TXT','SPF'
                 ) CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL DEFAULT 'A'
            "
        );
    }

    /**
     * Drop domain_id index on domain_dns table (needed for update r221)
     *
     * @return string SQL statements to be executed
     */
    protected function r220()
    {
        return $this->dropIndexByName('domain_dns', 'domain_id');
    }

    /**
     * Change domain_dns.domain_dns and domain_dns.domain_text column types from varchar to text
     * Create domain_id index on domain_dns table (with expected index length)
     *
     * @return array SQL statements to be executed
     */
    protected function r221()
    {
        return [
            $this->changeColumn('domain_dns', 'domain_dns', "`domain_dns` TEXT CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL"),
            $this->changeColumn('domain_dns', 'domain_text', "`domain_text` TEXT CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL"),
            $this->addIndex('domain_dns', ['domain_id', 'alias_id', 'domain_dns(255)', 'domain_class', 'domain_type', 'domain_text(255)'], 'UNIQUE')
        ];
    }

    /**
     * Convert FTP usernames, groups and members to ACE form
     *
     * @return null
     */
    protected function r222()
    {
        $stmt = execQuery('SELECT userid FROM ftp_users');
        while ($row = $stmt->fetch()) {
            execQuery('UPDATE ftp_users SET userid = ? WHERE userid = ?', [encodeIdna($row['userid']), $row['userid']]);
        }

        $stmt = execQuery('SELECT groupname, members FROM ftp_group');
        while ($row = $stmt->fetch()) {
            $members = implode(',', array_map('encodeIdna', explode(',', $row['members'])));
            execQuery('UPDATE ftp_group SET groupname = ?, members = ? WHERE groupname = ?', [
                encodeIdna($row['groupname']), $members, $row['groupname']
            ]);
        }

        return NULL;
    }

    /**
     * Wrong value for LOG_LEVEL configuration parameter
     *
     * @return null
     */
    protected function r223()
    {
        if (isset($this->dbConfig['LOG_LEVEL']) && preg_match('/\D/', $this->dbConfig['LOG_LEVEL'])) {
            $this->dbConfig['LOG_LEVEL'] = defined($this->dbConfig['LOG_LEVEL']) ? constant($this->dbConfig['LOG_LEVEL']) : E_USER_ERROR;
        }

        return NULL;
    }

    /**
     * Add column for HSTS feature
     *
     * @return null|string SQL statement to be executed
     */
    protected function r224()
    {
        return $this->addColumn('ssl_certs', 'allow_hsts', "VARCHAR(10) COLLATE utf8_unicode_ci NOT NULL DEFAULT 'off' AFTER ca_bundle");
    }

    /**
     * Add columns for forward type feature
     *
     * @return array SQL statements to be executed
     */
    protected function r225()
    {
        $sqlQueries = [];

        $sql = $this->addColumn('domain_aliasses', 'type_forward', "VARCHAR(5) COLLATE utf8_unicode_ci DEFAULT NULL AFTER url_forward");

        if ($sql !== NULL) {
            $sqlQueries[] = $sql;
            $sqlQueries[] = "UPDATE domain_aliasses SET type_forward = '302' WHERE url_forward <> 'no'";
        }

        $sql = $this->addColumn('subdomain', 'subdomain_type_forward', "VARCHAR(5) COLLATE utf8_unicode_ci DEFAULT NULL AFTER subdomain_url_forward");

        if ($sql !== NULL) {
            $sqlQueries[] = $sql;
            $sqlQueries[] = "UPDATE subdomain SET subdomain_type_forward = '302' WHERE subdomain_url_forward <> 'no'";
        }

        $sql = $this->addColumn(
            'subdomain_alias', 'subdomain_alias_type_forward', "VARCHAR(5) COLLATE utf8_unicode_ci DEFAULT NULL AFTER subdomain_alias_url_forward"
        );

        if ($sql !== NULL) {
            $sqlQueries[] = $sql;
            $sqlQueries[] = "UPDATE subdomain_alias SET subdomain_alias_type_forward = '302' WHERE subdomain_alias_url_forward <> 'no'";
        }

        return $sqlQueries;
    }

    /**
     * #IP-1395: Domain redirect feature - Missing URL path separator
     *
     * @return void
     */
    protected function r226()
    {
        $stmt = execQuery("SELECT alias_id, url_forward FROM domain_aliasses WHERE url_forward <> 'no'");

        while ($row = $stmt->fetch()) {
            $uri = new Http($row['url_forward']);
            $uriPath = rtrim(preg_replace('#/+#', '/', $uri->getPath()), '/') . '/';
            $uri->setPath($uriPath);
            execQuery('UPDATE domain_aliasses SET url_forward = ? WHERE alias_id = ?', [$uri->toString(), $row['alias_id']]);
        }

        $stmt = execQuery("SELECT subdomain_id, subdomain_url_forward FROM subdomain WHERE subdomain_url_forward <> 'no'");

        while ($row = $stmt->fetch()) {
            $uri = new Http($row['subdomain_url_forward']);
            $uriPath = rtrim(preg_replace('#/+#', '/', $uri->getPath()), '/') . '/';
            $uri->setPath($uriPath);
            execQuery('UPDATE subdomain SET subdomain_url_forward = ? WHERE subdomain_id = ?', [$uri->toString(), $row['subdomain_id']]);
        }

        $stmt = execQuery("SELECT subdomain_alias_id, subdomain_alias_url_forward FROM subdomain_alias WHERE subdomain_alias_url_forward <> 'no'");
        while ($row = $stmt->fetch()) {
            $uri = new Http($row['subdomain_alias_url_forward']);
            $uriPath = rtrim(preg_replace('#/+#', '/', $uri->getPath()), '/') . '/';
            $uri->setPath($uriPath);
            execQuery('UPDATE subdomain_alias SET subdomain_alias_url_forward = ? WHERE subdomain_alias_id = ?', [
                $uri->toString(), $row['subdomain_alias_id']
            ]);
        }
    }

    /**
     * Add column for HSTS options
     *
     * @return array SQL statements to be executed
     */
    protected function r227()
    {
        return [
            $this->addColumn('ssl_certs', 'hsts_max_age', "int(11) NOT NULL DEFAULT '31536000' AFTER allow_hsts"),
            $this->addColumn('ssl_certs', 'hsts_include_subdomains', "VARCHAR(10) COLLATE utf8_unicode_ci NOT NULL DEFAULT 'off' AFTER hsts_max_age")
        ];
    }

    /**
     * Reset all mail templates according changes made in 1.3.0
     *
     * @return string SQL statement to be executed
     */
    protected function r228()
    {
        return 'TRUNCATE email_tpls';
    }

    /**
     * Add index for mail_users.sub_id column
     *
     * @return string SQL statement to be executed
     */
    protected function r229()
    {
        return $this->addIndex('mail_users', 'sub_id', 'INDEX');
    }

    /**
     * Ext. mail feature - Remove deprecated columns and reset values
     *
     * @return array SQL statements to be executed
     */
    protected function r230()
    {
        return $sqlQueries = [
            $this->dropColumn('domain', 'external_mail_dns_ids'),
            $this->dropColumn('domain_aliasses', 'external_mail_dns_ids'),
            "DELETE FROM domain_dns WHERE owned_by = 'ext_mail_feature'",
            "UPDATE domain_aliasses SET external_mail = 'off'",
            "UPDATE domain SET external_mail = 'off'"
        ];
    }

    /**
     * #IP-1581 Allow to disable auto-configuration of network interfaces
     * - Add server_ips.ip_config_mode column
     *
     * @return null|string SQL statement to be executed
     */
    protected function r231()
    {
        return $this->addColumn('server_ips', 'ip_config_mode', "VARCHAR(15) COLLATE utf8_unicode_ci DEFAULT 'auto' AFTER ip_card");
    }

    /**
     * Set configuration mode to `manual' for the server's primary IP
     *
     * @return string SQL statement to be executed
     */
    protected function r232()
    {
        $primaryIP = quoteValue(Application::getInstance()->getConfig()['BASE_SERVER_IP']);
        return "UPDATE server_ips SET ip_config_mode = 'manual' WHERE ip_number = $primaryIP";
    }

    /**
     * Creates missing entries in the php_ini table (one for each domain)
     *
     * @return void
     */
    protected function r233()
    {
        $phpini = PHPini::getInstance();

        // For each reseller
        $resellers = execQuery("SELECT admin_id FROM admin WHERE admin_type = 'reseller'");
        while ($reseller = $resellers->fetch()) {
            $phpini->loadResellerPermissions($reseller['admin_id']);

            // For each client of the reseller
            $clients = execQuery("SELECT admin_id FROM admin WHERE created_by = ?", [$reseller['admin_id']]);
            while ($client = $clients->fetch()) {
                $phpini->loadClientPermissions($client['admin_id']);

                $domain = execQuery("SELECT domain_id FROM domain WHERE domain_admin_id = ? AND domain_status <> 'todelete' ?", [
                    $client['admin_id']
                ]);

                if (!$domain->rowCount()) {
                    continue;
                }

                $domain = $domain->fetch();
                $phpini->loadIniOptions($client['admin_id'], $domain['domain_id'], 'dmn');
                if ($phpini->isDefaultIniOptions()) {
                    $phpini->saveIniOptions($client['admin_id'], $domain['domain_id'], 'dmn');
                }

                $subdomains = execQuery("SELECT subdomain_id FROM subdomain WHERE domain_id = ? AND subdomain_status <> 'todelete'", [
                    $domain['domain_id']
                ]);
                while ($subdomain = $subdomains->fetch()) {
                    $phpini->loadIniOptions($client['admin_id'], $subdomain['subdomain_id'], 'sub');
                    if ($phpini->isDefaultIniOptions()) {
                        $phpini->saveIniOptions($client['admin_id'], $subdomain['subdomain_id'], 'sub');
                    }
                }
                unset($subdomains);

                $domainAliases = execQuery("SELECT alias_id FROM domain_aliasses WHERE domain_id = ? AND alias_status <> 'todelete'", [
                    $domain['domain_id']
                ]);
                while ($domainAlias = $domainAliases->fetch()) {
                    $phpini->loadIniOptions($client['admin_id'], $domainAlias['alias_id'], 'als');
                    if ($phpini->isDefaultIniOptions()) {
                        $phpini->saveIniOptions($client['admin_id'], $domainAlias['alias_id'], 'als');
                    }
                }
                unset($domainAliases);

                $subdomainAliases = execQuery(
                    '
                        SELECT subdomain_alias_id
                        FROM subdomain_alias
                        JOIN domain_aliasses USING(alias_id)
                        WHERE domain_id = ?
                        AND subdomain_alias_status <> ?
                    ',
                    [$domain['domain_id'], 'todelete']
                );
                while ($subdomainAlias = $subdomainAliases->fetch()) {
                    $phpini->loadIniOptions($client['admin_id'], $subdomainAlias['subdomain_alias_id'], 'subals');
                    if ($phpini->isDefaultIniOptions()) {
                        $phpini->saveIniOptions($client['admin_id'], $subdomainAlias['subdomain_alias_id'], 'subals');
                    }
                }
                unset($subdomainAliases);
            }
        }
    }

    /**
     * #IP-1429 Make primary domains forwardable
     * - Add domain.url_forward, domain.type_forward and domain.host_forward columns
     * - Add domain_aliasses.host_forward column
     * - Add subdomain.subdomain_host_forward column
     * - Add subdomain_alias.subdomain_alias_host_forward column
     *
     * @return array SQL statements to be executed
     */
    protected function r235()
    {
        return [
            $this->addColumn('domain', 'url_forward', "VARCHAR(255) COLLATE utf8_unicode_ci NOT NULL DEFAULT 'no'"),
            $this->addColumn('domain', 'type_forward', "VARCHAR(5) COLLATE utf8_unicode_ci DEFAULT NULL"),
            $this->addColumn('domain', 'host_forward', "VARCHAR(3) COLLATE utf8_unicode_ci DEFAULT 'Off'"),
            $this->addColumn('domain_aliasses', 'host_forward', "VARCHAR(3) COLLATE utf8_unicode_ci DEFAULT 'Off' AFTER type_forward"),
            $this->addColumn('subdomain', 'subdomain_host_forward', "VARCHAR(3) COLLATE utf8_unicode_ci DEFAULT 'Off' AFTER subdomain_type_forward"),
            $this->addColumn(
                'subdomain_alias',
                'subdomain_alias_host_forward',
                "VARCHAR(3) COLLATE utf8_unicode_ci DEFAULT 'Off' AFTER subdomain_alias_type_forward"
            ),
        ];
    }

    /**
     * Remove support for ftp URL redirects
     *
     * @return array SQL statements to be executed
     */
    protected function r236()
    {
        return [
            "UPDATE domain_aliasses SET url_forward = 'no', type_forward = NULL WHERE url_forward LIKE 'ftp://%'",
            "UPDATE subdomain SET subdomain_url_forward = 'no', subdomain_type_forward = NULL WHERE subdomain_url_forward LIKE 'ftp://%'",
            "
                UPDATE subdomain_alias SET subdomain_alias_url_forward = 'no', subdomain_alias_type_forward = NULL
                WHERE subdomain_alias_url_forward LIKE 'ftp://%'
            "
        ];
    }

    /**
     * Update domain_traffic table schema
     * - Disallow NULL value on domain_id and dtraff_time columns
     * - Change default value for dtraff_web, dtraff_ftp, dtraff_mail domain_traffic columns (NULL to 0)
     *
     * @return string SQL statement to be executed
     */
    protected function r238()
    {
        return "
          ALTER TABLE `domain_traffic`
            CHANGE `domain_id` `domain_id` INT(10) UNSIGNED NOT NULL,
            CHANGE `dtraff_time` `dtraff_time` BIGINT(20) UNSIGNED NOT NULL,
            CHANGE `dtraff_web` `dtraff_web` BIGINT(20) UNSIGNED NULL DEFAULT '0',
            CHANGE `dtraff_ftp` `dtraff_ftp` BIGINT(20) UNSIGNED NULL DEFAULT '0',
            CHANGE `dtraff_mail` `dtraff_mail` BIGINT(20) UNSIGNED NULL DEFAULT '0',
            CHANGE `dtraff_pop` `dtraff_pop` BIGINT(20) UNSIGNED NULL DEFAULT '0'
        ";
    }

    /**
     * Drop monthly_domain_traffic view which was added in update r238 and removed later on
     *
     * @return string SQL statement to be executed
     */
    protected function r239()
    {
        return 'DROP VIEW IF EXISTS monthly_domain_traffic';
    }

    /**
     * Delete deprecated `statistics` group for AWStats
     *
     * @return string SQL statement to be executed
     */
    protected function r241()
    {
        return "DELETE FROM htaccess_groups WHERE ugroup = 'statistics'";
    }

    /**
     * Add servers_ips.ip_netmask column
     *
     * @return null|string SQL statement to be executed
     */
    protected function r242()
    {
        return $this->addColumn('server_ips', 'ip_netmask', 'TINYINT(1) UNSIGNED DEFAULT NULL AFTER ip_number');
    }

    /**
     * Populate servers_ips.ip_netmask column
     *
     * @return null
     */
    protected function r243()
    {
        $stmt = execQuery('SELECT ip_id, ip_number, ip_netmask FROM server_ips');

        while ($row = $stmt->fetch()) {
            if ($this->config['BASE_SERVER_IP'] === $row['ip_number'] || $row['ip_netmask'] !== NULL) {
                continue;
            }

            if (strpos($row['ip_number'], ':') !== false) {
                $netmask = '64';
            } else {
                $netmask = '32';
            }

            execQuery('UPDATE server_ips SET ip_netmask = ? WHERE ip_id = ?', [$netmask, $row['ip_id']]);
        }

        return NULL;
    }

    /**
     * Renamed plugin.plugin_lock table to plugin.plugin_lockers and set default value
     *
     * @return array SQL statements to be executed
     */
    protected function r244()
    {
        return [
            "ALTER TABLE plugin CHANGE plugin_locked plugin_lockers TEXT CHARACTER SET utf8 COLLATE utf8_unicode_ci DEFAULT NULL",
            "UPDATE plugin SET plugin_lockers = '{}'"
        ];
    }

    /**
     * Add columns for alternative document root feature
     * - Add the domain.document_root column
     * - Add the subdomain.subdomain_document_root column
     * - Add the domain_aliasses.alias_document_root column
     * - Add the subdomain_alias.subdomain_alias_document_root column
     *
     * @return array SQL statements to be executed
     */
    protected function r245()
    {
        return [
            $this->addColumn('domain', 'document_root', "varchar(255) collate utf8_unicode_ci NOT NULL DEFAULT '/htdocs' AFTER mail_quota"),
            $this->addColumn(
                'subdomain', 'subdomain_document_root', "varchar(255) collate utf8_unicode_ci NOT NULL DEFAULT '/htdocs' AFTER subdomain_mount"
            ),
            $this->addColumn(
                'domain_aliasses', 'alias_document_root', "varchar(255) collate utf8_unicode_ci NOT NULL DEFAULT '/htdocs' AFTER alias_mount"
            ),
            $this->addColumn(
                'subdomain_alias',
                'subdomain_alias_document_root',
                "varchar(255) collate utf8_unicode_ci NOT NULL DEFAULT '/htdocs' AFTER subdomain_alias_mount"
            ),
        ];
    }

    /**
     * Drop ftp_users.rawpasswd column
     *
     * @return null|string SQL statement to be executed or NULL
     */
    protected function r246()
    {
        return $this->dropColumn('ftp_users', 'rawpasswd');
    }

    /**
     * Drop sql_user.sqlu_pass column
     *
     * @return null|string SQL statement to be executed or NULL
     */
    protected function r247()
    {
        return $this->dropColumn('sql_user', 'sqlu_pass');
    }

    /**
     * Update mail_users.mail_pass columns length
     *
     * @return null|string SQL statement to be executed or NULL
     */
    protected function r248()
    {
        return $this->changeColumn('mail_users', 'mail_pass', "mail_pass varchar(255) collate utf8_unicode_ci NOT NULL DEFAULT '_no_'");
    }

    /**
     * Store all mail account passwords using SHA512-crypt scheme
     *
     * @return void
     */
    protected function r249()
    {
        $stmt = execQuery('SELECT mail_id, mail_pass FROM mail_users WHERE mail_pass <> ? AND mail_pass NOT LIKE ?', ['_no_', '$6$%']);

        while ($row = $stmt->fetch()) {
            execQuery('UPDATE mail_users SET mail_pass = ? WHERE mail_id = ?', [Crypt::sha512($row['mail_pass']), $row['mail_id']]);
        }
    }

    /**
     * Change server_ips.ip_number column length
     *
     * @return null|string SQL statement to be executed or NULL
     */
    protected function r250()
    {
        return $this->changeColumn('server_ips', 'ip_number', 'ip_number VARCHAR(45) CHARACTER SET utf8 COLLATE utf8_unicode_ci NULL DEFAULT NULL');
    }

    /**
     * Delete invalid default mail accounts
     *
     * @return string SQL statement to be executed
     */
    protected function r251()
    {
        return "DELETE FROM mail_users WHERE mail_acc RLIKE '^abuse|hostmaster|postmaster|webmaster\\$' AND mail_forward IS NULL";
    }

    /**
     * Fix value for the plugin.plugin_lockers field
     *
     * @return string SQL statement to be executed
     */
    protected function r252()
    {
        return "UPDATE plugin SET plugin_lockers = '{}' WHERE plugin_lockers = 'null'";
    }

    /**
     * Change domain_dns.domain_dns_status column length
     *
     * @return null|string SQL statement to be executed or NULL
     */
    protected function r253()
    {
        return $this->changeColumn('domain_dns', 'domain_dns_status', "domain_dns_status TEXT CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL");
    }

    /**
     * Remove any virtual mailbox that was added for Postfix canonical domain (SERVER_HOSTNAME)
     *
     * SERVER_HOSTNAME is a Postfix canonical domain (local domain) which
     * cannot be listed in both `mydestination' and `virtual_mailbox_domains'
     * Postfix parameters. This necessarily means that Postfix canonical
     * domains cannot have virtual mailboxes, hence their deletion.
     *
     * See http://www.postfix.org/VIRTUAL_README.html#canonical
     *
     * @return null
     */
    protected function r254()
    {
        $stmt = execQuery(
            "SELECT mail_id, mail_type FROM mail_users WHERE mail_type LIKE '%_mail%' AND SUBSTRING(mail_addr, LOCATE('@', mail_addr)+1) = ?",
            [Application::getInstance()->getConfig()['SERVER_HOSTNAME']]
        );

        while ($row = $stmt->fetch()) {
            if (strpos($row['mail_type'], '_forward') !== FALSE) {
                # Turn normal+forward account into forward only account
                execQuery("UPDATE mail_users SET mail_pass = '_no_', mail_type = ?, quota = NULL WHERE mail_id = ?", [
                    preg_replace('/,?\b\.*_mail\b,?/', '', $row['mail_type']), $row['mail_id']
                ]);
            } else {
                # Schedule deletion of the mail account as virtual mailboxes
                # are prohibited for Postfix canonical domains.
                execQuery("UPDATE mail_users SET status = 'todelete' WHERE mail_id = ?", [$row['mail_id']]);
            }
        }

        return NULL;
    }

    /**
     * Fixed: mail_users.po_active column of forward only and catch-all accounts must be set to 'no'
     *
     * @return string SQL statement to be executed
     */
    protected function r255()
    {
        return "UPDATE mail_users SET po_active = 'no' WHERE mail_type NOT LIKE '%_mail%'";
    }

    /**
     * Remove output compression related parameters
     *
     * @return null
     */
    protected function r256()
    {

        if (isset($this->dbConfig['COMPRESS_OUTPUT'])) {
            unset($this->dbConfig['COMPRESS_OUTPUT']);
        }

        if (isset($this->dbConfig['SHOW_COMPRESSION_SIZE'])) {
            unset($this->dbConfig['SHOW_COMPRESSION_SIZE']);
        }

        return NULL;
    }

    /**
     * Update user_gui_props table
     *
     * @return array SQL statements to be executed
     */
    protected function r257()
    {
        return [
            $this->changeColumn('user_gui_props', 'lang', "lang varchar(15) collate utf8_unicode_ci DEFAULT 'browser'"),
            "UPDATE user_gui_props SET lang = 'browser' WHERE lang = 'auto'",
            $this->changeColumn('user_gui_props', 'layout', "layout varchar(100) collate utf8_unicode_ci NOT NULL DEFAULT 'default'"),
            $this->changeColumn('user_gui_props', 'layout_color', "layout_color varchar(15) COLLATE utf8_unicode_ci NOT NULL DEFAULT 'black'"),
            $this->changeColumn('user_gui_props', 'show_main_menu_labels', "show_main_menu_labels tinyint(1) NOT NULL DEFAULT '0'")
        ];
    }

    /**
     * Remove possible orphaned PHP ini entries that belong to subdomains of domain aliases
     *
     * @return string SQL statement to be executed
     */
    protected function r258()
    {
        return "
            DELETE FROM php_ini
            WHERE domain_id NOT IN(SELECT subdomain_alias_id FROM subdomain_alias WHERE subdomain_alias_status <> 'todelete')
            AND domain_type = 'subals'
        ";
    }

    /**
     * Fix erroneous ftp_group.members fields (missing subsequent FTP account members)
     *
     * @return string SQL statement to be executed
     */
    protected function r259()
    {
        return "
            UPDATE ftp_group AS t1, (SELECT gid, group_concat(userid SEPARATOR ',') AS members
            FROM ftp_users GROUP BY gid) AS t2
            SET t1.members = t2.members
            WHERE t1.gid = t2.gid
        ";
    }

    /**
     * Adds unique constraint for mail user entities
     *
     * Note: Repeated update due to mistake in previous implementation (was r202 and r260)
     *
     * @return array SQL statements to be executed
     */
    protected function r265()
    {
        if ($renameQuery = $this->renameTable('mail_users', 'old_mail_users')) {
            execQuery($renameQuery);
        }

        if (!$this->isTable('mail_users')) {
            execQuery('CREATE TABLE mail_users LIKE old_mail_users');
        }

        if ($dropQuery = $this->dropIndexByName('mail_users', 'mail_addr')) {
            execQuery($dropQuery);
        }

        return [
            $this->addIndex('mail_users', 'mail_addr', 'UNIQUE', 'mail_addr'),
            'INSERT IGNORE INTO mail_users SELECT * FROM old_mail_users',
            $this->dropTable('old_mail_users')
        ];
    }

    /**
     * Add unique constraint on server_traffic.traff_time column to avoid duplicate time periods
     *
     * Note: Repeated update due to mistake in previous implementation (was r210 and r261)
     *
     * @return array SQL statements to be executed
     */
    protected function r266()
    {
        if ($renameQuery = $this->renameTable('server_traffic', 'old_server_traffic')) {
            execQuery($renameQuery);
        }

        if (!$this->isTable('server_traffic')) {
            execQuery('CREATE TABLE server_traffic LIKE old_server_traffic');
        }

        if ($dropQuery = $this->dropIndexByName('server_traffic', 'traff_time')) {
            execQuery($dropQuery);
        }

        return [
            $this->addIndex('server_traffic', 'traff_time', 'UNIQUE', 'traff_time'),
            'INSERT IGNORE INTO server_traffic SELECT * FROM old_server_traffic',
            $this->dropTable('old_server_traffic')
        ];
    }

    /**
     * #IP-1587 Slow query on domain_traffic table when admin or reseller want to login into customer's area
     * - Add compound unique index on the domain_traffic table to avoid slow query and duplicate entries
     *
     * Note: Repeated update due to mistake in previous implementation (was r237 and r263)
     *
     * @return array SQL statements to be executed
     */
    protected function r268()
    {
        if ($renameQuery = $this->renameTable('domain_traffic', 'old_domain_traffic')) {
            execQuery($renameQuery);
        }

        if (!$this->isTable('domain_traffic')) {
            execQuery('CREATE TABLE domain_traffic LIKE old_domain_traffic');
        }

        if ($dropQuery = $this->dropIndexByName('domain_traffic', 'i_unique_timestamp')) {
            execQuery($dropQuery);
        }

        return [
            $this->addIndex('domain_traffic', ['domain_id', 'dtraff_time'], 'UNIQUE', 'i_unique_timestamp'),
            'INSERT IGNORE INTO domain_traffic SELECT * FROM old_domain_traffic',
            $this->dropTable('old_domain_traffic')
        ];
    }

    /**
     * Add missing primary key on httpd_vlogger table
     *
     * Note: Repeated update due to mistake in previous implementation (was r240 and r264)
     *
     * @return null|array SQL statements to be executed or null
     */
    protected function r269()
    {
        if ($renameQuery = $this->renameTable('httpd_vlogger', 'old_httpd_vlogger')) {
            execQuery($renameQuery);
        }

        if (!$this->isTable('httpd_vlogger')) {
            execQuery('CREATE TABLE httpd_vlogger LIKE old_httpd_vlogger');
        }

        if ($dropQuery = $this->dropIndexByName('httpd_vlogger', 'PRIMARY')) {
            execQuery($dropQuery);
        }

        return [
            $this->addIndex('httpd_vlogger', ['vhost', 'ldate']),
            'INSERT IGNORE INTO httpd_vlogger SELECT * FROM old_httpd_vlogger',
            $this->dropTable('old_httpd_vlogger')
        ];
    }

    /**
     * Adds compound unique key on the php_ini table
     *
     * Note: Repeated update due to mistake in previous implementation (was r234, r262 and r267)
     *
     * @return array SQL statements to be executed
     */
    protected function r271()
    {
        if ($renameQuery = $this->renameTable('php_ini', 'old_php_ini')) {
            execQuery($renameQuery);
        }

        if (!$this->isTable('php_ini')) {
            execQuery('CREATE TABLE php_ini LIKE old_php_ini');
        }

        if ($dropQueries = $this->dropIndexByColumn('php_ini', 'admin_id')) {
            foreach ($dropQueries as $dropQuery) {
                execQuery($dropQuery);
            }
        }

        if ($dropQueries = $this->dropIndexByColumn('php_ini', 'domain_id')) {
            foreach ($dropQueries as $dropQuery) {
                execQuery($dropQuery);
            }
        }

        if ($dropQueries = $this->dropIndexByColumn('php_ini', 'domain_type')) {
            foreach ($dropQueries as $dropQuery) {
                execQuery($dropQuery);
            }
        }

        return [
            $this->addIndex('php_ini', ['admin_id', 'domain_id', 'domain_type'], 'UNIQUE', 'unique_php_ini'),
            'INSERT IGNORE INTO php_ini SELECT * FROM old_php_ini',
            $this->dropTable('old_php_ini')
        ];
    }

    /**
     * Schema review (domain_traffic table):
     *  - Fix for #IP-1756:
     *   - Remove domain_traffic.dtraff_id column (PRIMARY KEY, AUTO_INCREMENT)
     *   - Remove `i_unique_timestamp` unique index (domain_id, dtraff_time)
     *   - Create new PRIMARY KEY (domain_id, dtraff_time)
     *
     * @return string|null string SQL statement to be executed
     */
    protected function r272()
    {
        if ($dropQuery = $this->dropColumn('domain_traffic', 'dtraff_id')) {
            execQuery($dropQuery);
        }

        if ($dropQuery = $this->dropIndexByName('domain_traffic', 'i_unique_timestamp')) {
            execQuery($dropQuery);
        }

        return $this->addIndex('domain_traffic', ['domain_id', 'dtraff_time']);
    }

    /**
     * Schema review (server_traffic table):
     *  - Remove server_traffic.dtraff_id column (PRIMARY KEY, AUTO_INCREMENT)
     *  - Remove `traff_time` unique index (traff_time)
     *  - Add compound PRIMARY KEY (traff_time)
     *
     * Note: Repeated update due to mistake in previous implementation (was r273)
     *
     * @return array string SQL statement to be executed
     */
    protected function r274()
    {
        if ($dropQuery = $this->dropColumn('server_traffic', 'straff_id')) {
            execQuery($dropQuery);
        }

        if ($dropQueries = $this->dropIndexByColumn('server_traffic', 'traff_time')) {
            foreach ($dropQueries as $dropQuery) {
                execQuery($dropQuery);
            }
        }

        return [
            // All parts of a PRIMARY KEY must be NOT NULL
            'ALTER TABLE server_traffic MODIFY `traff_time` INT(10) UNSIGNED NOT NULL',
            $this->addIndex('server_traffic', 'traff_time')
        ];
    }

    /**
     * Add columns for PHP configuration level (PHP Editor)
     *
     * Prior version 1.6.0, the PHP configuration level was set at system wide, in the /etc/imscp/php/php.data file.
     *
     * @return array string SQL statement to be executed
     */
    protected function r275()
    {
        return [
            $this->addColumn(
                'domain',
                'phpini_perm_config_level',
                "ENUM( 'per_domain', 'per_site', 'per_user' ) NOT NULL DEFAULT 'per_site' AFTER phpini_perm_system"
            ),
            $this->addColumn(
                'reseller_props',
                'php_ini_al_config_level',
                "ENUM( 'per_domain', 'per_site', 'per_user' ) NOT NULL DEFAULT 'per_site' AFTER php_ini_system"
            )
        ];
    }

    /**
     * Remove deprecated CREATE_DEFAULT_EMAIL_ADDRESSES
     *
     * @return null
     */
    protected function r277()
    {
        if (isset($this->dbConfig['CREATE_DEFAULT_EMAIL_ADDRESSES'])) {
            unset($this->dbConfig['CREATE_DEFAULT_EMAIL_ADDRESSES']);
        }

        return NULL;
    }

    /**
     * Update status fields
     *
     * @return array SQL statements to be executed
     */
    protected function r278()
    {
        if ($dropQueries = $this->dropIndexByColumn('mail_users', 'status')) {
            foreach ($dropQueries as $dropQuery) {
                execQuery($dropQuery);
            }
        }

        return [
            $this->changeColumn('admin', 'admin_status', '`admin_status` text collate utf8_unicode_ci NOT NULL'),
            $this->changeColumn('domain', 'domain_status', '`domain_status` text collate utf8_unicode_ci NOT NULL'),
            $this->changeColumn('domain_aliasses', 'alias_status', '`alias_status` text collate utf8_unicode_ci NOT NULL'),
            $this->changeColumn('ftp_users', 'status', "`status` text collate utf8_unicode_ci NOT NULL"),
            $this->changeColumn('htaccess', 'status', '`status` text collate utf8_unicode_ci NOT NULL'),
            $this->changeColumn('htaccess_groups', 'status', '`status` text collate utf8_unicode_ci NOT NULL'),
            $this->changeColumn('htaccess_users', 'status', '`status` text collate utf8_unicode_ci NOT NULL'),
            $this->changeColumn('mail_users', 'status', '`status` text collate utf8_unicode_ci NOT NULL'),
            $this->addIndex('mail_users', 'status(255)', 'INDEX', 'status'),
            $this->changeColumn('server_ips', 'ip_status', '`ip_status` text collate utf8_unicode_ci NOT NULL'),
            $this->changeColumn('ssl_certs', 'status', '`status` text collate utf8_unicode_ci NOT NULL'),
            $this->changeColumn('subdomain', 'subdomain_status', '`subdomain_status` text collate utf8_unicode_ci NOT NULL'),
            $this->changeColumn('subdomain_alias', 'subdomain_alias_status', '`subdomain_alias_status` text collate utf8_unicode_ci NOT NULL')
        ];
    }

    /**
     * Add subdomain.subdomain_ip_id column - Make it possible to assign specific IP addresses to subdomains
     * Add subdomain_alias.subdomain_alias_ip_id column - Make it possible to assign specific IP addresses to subdomains of domain aliases
     *
     * @return array SQL statements to be executed
     */
    protected function r279()
    {
        $sqlQueries = [];

        $sqlQuery = $this->addColumn('subdomain', 'subdomain_ip_id', 'TEXT NOT NULL AFTER subdomain_name');
        if ($sqlQuery !== NULL) {
            $sqlQueries[] = $sqlQuery;
            # Fills the new column with data from domain.domain_ip_id column
            $sqlQueries[] = 'UPDATE subdomain t1 JOIN domain t2 USING(domain_id) SET t1.subdomain_ip_id = t2.domain_ip_id';
        }

        $sqlQuery = $this->addColumn('subdomain_alias', 'subdomain_alias_ip_id', 'TEXT NOT NULL AFTER subdomain_alias_name');
        if ($sqlQuery !== NULL) {
            $sqlQueries[] = $sqlQuery;
            # Fills the new column with data from domain_aliasses.alias_ip_id column
            $sqlQueries[] = 'UPDATE subdomain_alias t1 JOIN domain_aliasses t2 USING(alias_id) SET t1.subdomain_alias_ip_id = t2.alias_ip_id';
        }

        return $sqlQueries;
    }

    /**
     * Add domain.domain_ips column - Make it possible to assigne more than one IP address to one customer
     *
     * @return array SQL statements to be executed
     */
    protected function r280()
    {
        $sqlQueries = [];

        $sqlQuery = $this->addColumn('domain', 'domain_ips', 'TEXT NOT NULL AFTER domain_subd_limit');
        if ($sqlQuery !== NULL) {
            $sqlQueries[] = $sqlQuery;
            # Fills the new column with data from domain.domain_ip_id column
            $sqlQueries[] = 'UPDATE domain SET domain_ips = domain_ip_id';
        }

        return $sqlQueries;
    }

    /**
     * Update domain.domain_ip_id column - Make it possible to set more than one IP address to one domain
     * Update aliasses.alias_ip_id column - Make it possible to set more than one IP address to one domain alias
     *
     * @return array SQL statements to be executed
     */
    protected function r281()
    {
        return [
            $this->changeColumn('domain', 'domain_ip_id', '`domain_ip_id` text NOT NULL'),
            $this->changeColumn('domain_aliasses', 'alias_ip_id', '`alias_ip_id` text NOT NULL')
        ];
    }

    /**
     * Update reseller_props.reseller_ips field ('<ip_id>;<ip_id>;' to '<ip_id>,<ip_id>')
     *
     * @return array SQL statements to be executed
     */
    protected function r282()
    {
        $sqlQueries = [];

        $stmt = execQuery('SELECT reseller_id, reseller_ips FROM reseller_props');
        while ($row = $stmt->fetch()) {
            if (strpos($row['reseller_ips'], ';') === FALSE) {
                continue;
            }

            $row['reseller_ips'] = implode(',', explode(';', trim($row['reseller_ips'], ';')));
            $sqlQueries[] = 'UPDATE reseller_props SET reseller_ips = ' . quoteValue($row['reseller_ips']) . ' WHERE reseller_id = '
                . $row['reseller_id'];
        }

        return $sqlQueries;
    }

    /**
     * Rename columns:
     *  - domain.domain_ips                     to domain_client_ips
     *  - domain.domain_ip_id                   to domain_ips
     *  - subdomain.subdomain_ip_id             to subdomain.subdomain_ips
     *  - domain_aliasses.alias_ip_id           to domain_aliasses.alias_ips
     *  - subdomain_alias.subdomain_alias_ip_id to subdomain_alias.subdomain_alias_ips
     *
     * @return array SQL statements to be executed
     */
    protected function r283()
    {
        return [
            $this->changeColumn('domain', 'domain_ips', '`domain_client_ips` text NOT NULL'),
            $this->changeColumn('domain', 'domain_ip_id', '`domain_ips` text NOT NULL'),
            $this->changeColumn('domain_aliasses', 'alias_ip_id', '`alias_ips` text NOT NULL'),
            $this->changeColumn('subdomain', 'subdomain_ip_id', '`subdomain_ips` text NOT NULL'),
            $this->changeColumn('subdomain_alias', 'subdomain_alias_ip_id', '`subdomain_alias_ips` text NOT NULL')
        ];
    }

    /**
     * Rename domain_aliasses table to domain_aliases
     *
     * @return null|string SQL statement to be executed
     */
    protected function r284()
    {
        return $this->renameTable('domain_aliasses', 'domain_aliases');
    }

    /**
     * Remove all software installer related tables and columns
     *
     * @return array SQL statements to be executed
     */
    protected function r285()
    {
        $sqlQueries = [];

        // Columns in domain table
        $sqlQueries[] = $this->dropColumn('domain', 'domain_software_allowed');

        // Columns in reseller_props table
        $sqlQueries[] = $this->dropColumn('reseller_props', 'software_allowed');
        $sqlQueries[] = $this->dropColumn('reseller_props', 'softwaredepot_allowed');
        $sqlQueries[] = $this->dropColumn('reseller_props', 'websoftwaredepot_allowed');

        // Tables
        $sqlQueries[] = $this->dropTable('web_software');
        $sqlQueries[] = $this->dropTable('web_software_inst');
        $sqlQueries[] = $this->dropTable('web_software_depot');
        $sqlQueries[] = $this->dropTable('web_software_options');

        return $sqlQueries;
    }

    /**
     * Update reseller permissions columns (new permission columns, naming, reordering) 
     *
     * @return array SQL statements to be executed
     */
    protected function r286()
    {
        return [
            $this->dropColumn('reseller_props', 'support_system'),

            $this->changeColumn('reseller_props', 'php_ini_system', "`php_ini` tinyint(1) NOT NULL DEFAULT '0',"),
            $this->changeColumn('reseller_props', 'php_ini_al_config_level', "`php_ini_config_level` ENUM( 'per_domain', 'per_site', 'per_user' ) NOT NULL DEFAULT 'per_site'"),
            $this->changeColumn('reseller_props', 'php_ini_al_disable_functions', "`php_ini_disable_functions` tinyint(1) NOT NULL DEFAULT '0',"),
            $this->changeColumn('reseller_props', 'php_ini_al_mail_function', "`php_ini_mail_function` tinyint(1) NOT NULL DEFAULT '0',"),
            $this->changeColumn('reseller_props', 'php_ini_al_allow_url_fopen', "`php_ini_allow_url_fopen` tinyint(1) NOT NULL DEFAULT '0',"),
            $this->changeColumn('reseller_props', 'php_ini_al_display_errors', "`php_ini_display_errors` tinyint(1) NOT NULL DEFAULT '0',"),
            $this->changeColumn('reseller_props', 'php_ini_max_post_max_size', ""),
            $this->changeColumn('reseller_props', 'php_ini_max_upload_max_filesize', ""),
            $this->changeColumn('reseller_props', 'php_ini_max_max_execution_time', ""),
            $this->changeColumn('reseller_props', 'php_ini_max_max_input_time', ""),
            $this->changeColumn('reseller_props', 'php_ini_max_memory_limit', ""),

            $this->addColumn('reseller_props', 'php', "php tinyint(1) NOT NULL DEFAULT '0' BEFORE php_ini_system"),
            $this->addColumn('reseller_props', 'cgi', "`cgi` tinyint(1) NOT NULL DEFAULT '0'"),
            $this->addColumn('reseller_props', 'custom_dns', "`custom_dns` tinyint(1) NOT NULL DEFAULT '0'"),
            $this->addColumn('reseller_props', 'external_mail_server', "`external_mail_server` tinyint(1) NOT NULL DEFAULT '0'"),
            $this->addColumn('reseller_props', 'support_system', "`support_system` tinyint(1) NOT NULL DEFAULT '0'"),
            $this->addColumn('reseller_props', 'backup', "`backup` tinyint(1) NOT NULL DEFAULT '0',"),
            $this->addColumn('reseller_props', 'webstats', "`webstats` tinyint(1) NOT NULL DEFAULT '0',"),
        ];
    }

    /**
     * Reset hosting_plans table due to major changes
     *
     * @return string SQL statements to be executed
     */
    protected function r287()
    {
        return 'TRUNCATE hosting_plans';
    }
}
