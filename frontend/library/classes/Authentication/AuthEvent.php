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

namespace iMSCP\Authentication;

use Zend\EventManager\Event;

/**
 * Class AuthEvent
 * @package iMSCP\Authentication
 */
class AuthEvent extends Event
{
    public const EVENT_BEFORE_AUTHENTICATION = 'onBeforeAuthentication';
    public const EVENT_AUTHENTICATION = 'onAuthentication';
    public const EVENT_AFTER_AUTHENTICATION = 'onAfterAuthentication';

    /**
     * @var AuthResult
     */
    protected $authenticationResult = NULL;

    /**
     * Has authentication result?
     *
     * @return bool
     */
    public function hasAuthenticationResult(): bool
    {
        return $this->authenticationResult !== NULL;
    }

    /**
     * Get authentication result
     *
     * @return AuthResult|null
     */
    public function getAuthenticationResult(): ?AuthResult
    {
        return $this->authenticationResult;
    }

    /**
     * Set authentication result
     *
     * @param AuthResult $authResult
     * @param void
     */
    public function setAuthenticationResult(AuthResult $authResult): void
    {
        $this->authenticationResult = $authResult;
    }
}
