=head1 NAME

 iMSCP::Servers::Server - Factory and abstract implementation for the i-MSCP server servers

=cut

# i-MSCP - internet Multi Server Control Panel
# Copyright (C) 2010-2018 Laurent Declercq <l.declercq@nuxwin.com>
#
# This library is free software; you can redistribute it and/or
# modify it under the terms of the GNU Lesser General Public
# License as published by the Free Software Foundation; either
# version 2.1 of the License, or (at your option) any later version.
#
# This library is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU
# Lesser General Public License for more details.
#
# You should have received a copy of the GNU Lesser General Public
# License along with this library; if not, write to the Free Software
# Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA 02110-1301 USA

package iMSCP::Servers::Server;

use strict;
use warnings;
use Carp qw/ croak /;
use parent 'iMSCP::Servers::Abstract';

=head1 DESCRIPTION

 This class provides a factory and an abstract implementation for the i-MSCP server servers.

=head1 CLASS METHODS

=over 4

=item getServerPriority( )

 See iMSCP::Servers::Abstract::getServerPriority()

=cut

sub getServerPriority
{
    350;
}

=back

=head1 PUBLIC METHODS

=over 4

=item addIpAddress( \%moduleData )

 Process addIpAddress tasks

 The following events *MUST* be triggered:
  - before<SNAME>AddIpAddress( \%moduleData )
  - after<SNAME>AddIpAddress( \%moduleData )

 where <SNAME> is the server name as returned by the iMSCP::Servers::Abstract::getServerName() method.

 Param hashref \%moduleData Data as provided by the IpAddress module
 Return void, die on failure

=cut

sub addIpAddress
{
    my ( $self ) = @_;

    die( sprintf( 'The %s class must implement the addIpAddress() method', ref $self ));
}

=item deleteIpAddress( \%moduleData )

 Process deleteIpAddress tasks

 The following events *MUST* be triggered:
  - before<SNAME>DeleteIpAddress( \%moduleData )
  - after<SNAME>DeleteIpAddress( \%moduleData )

 where <SNAME> is the server name as returned by the iMSCP::Servers::Abstract::getServerName() method.

 Param hashref \%moduleData Data as provided by the IpAddress module
 Return void, die on failure

=cut

sub deleteIpAddress
{
    my ( $self ) = @_;

    die( sprintf( 'The %s class must implement the deleteIpAddress() method', ref $self ));
}

=item addUser( \%moduleData )

 Process addUser tasks
 
  The following events *MUST* be triggered:
  - before<SNAME>AddUser( \%moduleData )
  - after<SNAME>AddUser( \%moduleData )

 where <SNAME> is the server name as returned by the iMSCP::Servers::Abstract::getServerName() method.

 Param hashref \%moduleData Data as provided by the User module
 Return void, die on failure

=cut

sub addUser
{
    my ( $self ) = @_;

    die( sprintf( 'The %s class must implement the addUser() method', ref $self ));
}

=item deleteUser( \%moduleData )

 Process deleteUser tasks
 
  The following events *MUST* be triggered:
  - before<SNAME>DeleteUser( \%moduleData )
  - after<SNAME>DeleteUser( \%moduleData )

 where <SNAME> is the server name as returned by the iMSCP::Servers::Abstract::getServerName() method.

 Param hashref \%moduleData Data as provided by the User module
 Return void, die on failure

=cut

sub deleteUser
{
    my ( $self ) = @_;

    die( sprintf( 'The %s class must implement the deleteUser() method', ref $self ));
}

=back

=head1 PRIVATE METHODS

=over

=item _init( )

 See iMSCP::Servers::Abstract::_init()

=cut

sub _init
{
    my ( $self ) = @_;

    ref $self ne __PACKAGE__ or croak( sprintf( 'The %s class is an abstract class which cannot be instantiated', __PACKAGE__ ));

    $self->SUPER::_init();
}

=back

=head1 AUTHOR

 Laurent Declercq <l.declercq@nuxwin.com>

=cut

1;
__END__
