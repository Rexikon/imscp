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

namespace iMSCP\Functions;

use iMSCP\Application;
use iMSCP\Crypt;

/**
 * Class LostPassword
 * @package iMSCP\Functions
 */
class LostPassword
{
    /**
     * Generate captcha
     *
     * @param  string $strSessionVar
     * @return void
     */
    public static function generateCaptcha(string $strSessionVar): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            throw new \RuntimeException(tr('PHP GD extension not loaded.'));
        }

        $config = Application::getInstance()->getConfig();
        $rgBgColor = $config['LOSTPASSWORD_CAPTCHA_BGCOLOR'];
        $rgTextColor = $config['LOSTPASSWORD_CAPTCHA_TEXTCOLOR'];

        if (!($image = imagecreate($config['LOSTPASSWORD_CAPTCHA_WIDTH'], $config['LOSTPASSWORD_CAPTCHA_HEIGHT']))) {
            throw new \RuntimeException('Cannot initialize new GD image stream.');
        }

        imagecolorallocate($image, $rgBgColor[0], $rgBgColor[1], $rgBgColor[2]);
        $textColor = imagecolorallocate($image, $rgTextColor[0], $rgTextColor[1], $rgTextColor[2]);
        $nbLetters = 6;

        $x = ($config['LOSTPASSWORD_CAPTCHA_WIDTH'] / 2) - ($nbLetters * 20 / 2);
        $y = mt_rand(15, 25);

        $string = Crypt::randomStr($nbLetters, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789');
        for ($i = 0; $i < $nbLetters; $i++) {
            $fontFile = LIBRARY_PATH . '/resources/fonts/'
                . $config['LOSTPASSWORD_CAPTCHA_FONTS'][mt_rand(0, count($config['LOSTPASSWORD_CAPTCHA_FONTS']) - 1)];
            imagettftext($image, 17, rand(-30, 30), $x, $y, $textColor, $fontFile, $string[$i]);
            $x += 20;
            $y = mt_rand(15, 25);
        }

        Application::getInstance()->getSession()[$strSessionVar] = $string;

        // obfuscation
        $white = imagecolorallocate($image, 0xFF, 0xFF, 0xFF);
        for ($i = 0; $i < 5; $i++) {
            $x1 = mt_rand(0, $x - 1);
            $y1 = mt_rand(0, round($y / 10, 0));
            $x2 = mt_rand(0, round($x / 10, 0));
            $y2 = mt_rand(0, $y - 1);
            imageline($image, $x1, $y1, $x2, $y2, $white);
            $x1 = mt_rand(0, $x - 1);
            $y1 = $y - mt_rand(1, round($y / 10, 0));
            $x2 = $x - mt_rand(1, round($x / 10, 0));
            $y2 = mt_rand(0, $y - 1);
            imageline($image, $x1, $y1, $x2, $y2, $white);
        }

        header('Content-type: image/png');
        imagepng($image); // create and send PNG image
        imagedestroy($image); // destroy image from server
    }

    /**
     * Remove old keys
     *
     * @param int $ttl
     * @return void
     */
    public static function removeOldKeys(int $ttl): void
    {
        execQuery('UPDATE imscp_user SET lastLostPasswordRequestTime = NULL, lostPasswordKey = NULL WHERE uniqkey_time < ?', [
            date('Y-m-d H:i:s', time() - $ttl * 60)
        ]);
    }

    /**
     * Sets unique key
     *
     * @param string $adminName
     * @param string $uniqueKey
     * @return void
     */
    public static function setUniqKey(string $adminName, string $uniqueKey): void
    {
        execQuery('UPDATE imscp_user SET lastLostPasswordRequestTime = ?, lostPasswordKey = ? WHERE username = ?', [
            $uniqueKey, date('Y-m-d H:i:s', time()), $adminName
        ]);
    }

    /**
     * Set password
     *
     * @param string $uniqueKey
     * @param string $userPassword
     * @return void
     */
    public static function setPassword(string $uniqueKey, string $userPassword): void
    {
        $passwordHash = Crypt::bcrypt($userPassword);

        execQuery('UPDATE imscp_user SET passwordHash = ?, lastLostPasswordRequestTime = NULL, lostPasswordKey = NULL WHERE uniqkey = ?', [
            $passwordHash, $uniqueKey
        ]);
    }

    /**
     * Checks for unique key existence
     *
     * @param string $uniqueKey
     * @return bool TRUE if the key exists, FALSE otherwise
     */
    public static function uniqueKeyExists(string $uniqueKey): bool
    {
        return execQuery('SELECT 1 FROM imscp_user WHERE lostPasswordKey = ?', [$uniqueKey])->fetchColumn() !== false;
    }

    /**
     * generate unique key
     *
     * @return string Unique key
     */
    public static function uniqkeygen(): string
    {
        do {
            $uniqueKey = sha1(Crypt::randomStr(32));
        } while (static::uniqueKeyExists($uniqueKey));

        return $uniqueKey;
    }

    /**
     * Send password request validation
     *
     * @param string $adminName
     * @return bool TRUE on success, FALSE otherwise
     */
    public static function sendPasswordRequestValidation(string $adminName): bool
    {
        $stmt = execQuery('SELECT userID, createdBy, firstName, lastName, email FROM imscp_user WHERE username = ?', [$adminName]);

        if (!$stmt->rowCount()) {
            View::setPageMessage(tr('Wrong username.'), 'error');
            return false;
        }

        $row = $stmt->fetch();
        $createdBy = $row['createdBy'];

        if ($createdBy == 0) {
            $createdBy = $row['userID']; // Force usage of default template for any admin request
        }

        $data = Mail::getLostpasswordActivationEmail($createdBy);

        # Create uniq key for password request validation
        $uniqueKey = static::uniqkeygen();
        static::setUniqKey($adminName, $uniqueKey);

        $ret = Mail::sendMail([
            'mailID'      => 'lostpw-msg-1',
            'firstName'    => $row['firstName'],
            'lastName'     => $row['lastName'],
            'username'     => $adminName,
            'email'        => $row['email'],
            'emailSubject' => $data['emailSubject'],
            'emailBody'    => $data['emailBody'],
            'placeholders' => [
                '{LINK}' => getRequestBaseUrl() . '/lostpassword.php?key=' . $uniqueKey
            ]
        ]);

        if (!$ret) {
            writeLog(sprintf("Couldn't send new password request validation to %s", $adminName), E_USER_ERROR);
            View::setPageMessage(tr('An unexpected error occurred. Please contact your administrator.'));
            return false;
        }

        return true;
    }

    /**
     * Send new password
     *
     * @param string $uniqueKey
     * @return bool TRUE when new password is sent successfully, FALSE otherwise
     */
    public static function sendPassword(string $uniqueKey): bool
    {
        $stmt = execQuery(
            'SELECT userID, username, type, createdBy, firstName, lastName, email, lostPasswordKey FROM imscp_user WHERE uniqkey = ?',
            [$uniqueKey]
        );

        if (!$stmt->rowCount()) {
            View::setPageMessage(tr('Your request for password renewal is either invalid or has expired.'), 'error');
            return false;
        }

        $row = $stmt->fetch();

        /*        
        if ($row['admin_status'] != 'ok') {
            View::setPageMessage(tr('Your request for password renewal cannot be honored. Please retry in few minutes.'), 'error');
            return false;
        }
        */

        $cfg = Application::getInstance()->getConfig();
        $userPassword = Crypt::randomStr(
            isset($cfg['PASSWD_CHARS']) ? $cfg['PASSWD_CHARS'] : 6, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789'
        );
        static::setPassword($uniqueKey, $userPassword);
        writeLog(sprintf('Lostpassword: A New password has been set for the %s user', $row['userrname']), E_USER_NOTICE);

        $createdBy = $row['createdBy'];
        if ($createdBy == 0) {
            $createdBy = $row['userId'];
        }

        $data = Mail::getLostpasswordEmail($createdBy);
        $ret = Mail::sendMail([
            'mailID'       => 'lostpw-msg-2',
            'firstName'    => $row['firstName'],
            'lastName'     => $row['lastName'],
            'username'     => $row['username'],
            'email'        => $row['email'],
            'emailSubject' => $data['emailSubject'],
            'emailBody'    => $data['emailBody'],
            'placeholders' => [
                '{PASSWORD}' => $userPassword
            ]
        ]);

        if (!$ret) {
            writeLog(sprintf("Couldn't send new passsword to %s", $row['username']), E_USER_ERROR);
            View::setPageMessage(tr('An unexpected error occurred. Please contact your administrator.'));
            return false;
        }

        return true;
    }
}
