=head1 NAME

 iMSCP::Packages::Setup::Backup - i-MSCP backup

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

package iMSCP::Packages::Setup::Backup;

use strict;
use warnings;
use autouse 'iMSCP::Dialog::InputValidation' => qw/ isOneOfStringsInList isStringInList /;
use Class::Autouse qw/ :nostats iMSCP::Servers::Cron /;
use iMSCP::Getopt;
use parent 'iMSCP::Packages::Abstract';

our $VERSION = '1.0.0';

=head1 DESCRIPTION

 Package responsible to setup the i-MSCP backup feature.

=head1 CLASS METHODS

=over 4

=item getPackagePriority( )

 See iMSCP::Packages::Abstract::getPackagePriority()

=cut

sub getPackagePriority
{
    my ( $self ) = @_;

    200;
}

=back

=head1 PUBLIC METHODS

=over 4

=item registerSetupListeners( )

 See iMSCP::Packages::Abstract::registerSetupListeners()

=cut

sub registerSetupListeners
{
    my ( $self ) = @_;

    $self->{'eventManager'}->registerOne(
        'beforeSetupDialog', sub { push @{ $_[0] }, sub { $self->imscpBackupDialog( @_ ) }, sub { $self->customersBackupDialog( @_ ) }; }
    );
}

=item imscpBackupDialog( \%dialog )

 Ask for i-MSCP backup

 Param iMSCP::Dialog \%dialog
 Return int 0 (NEXT), 30 (BACK) or 50 (ESC)

=cut

sub imscpBackupDialog
{
    my ( $self, $dialog ) = @_;

    my $value = ::setupGetQuestion( 'BACKUP_IMSCP', iMSCP::Getopt->preseed ? 'yes' : '' );
    my %choices = ( 'yes', 'Yes', 'no', 'No' );

    if ( isOneOfStringsInList( iMSCP::Getopt->reconfigure, [ 'backup', 'all', 'forced' ] ) || !isStringInList( $value, keys %choices ) ) {
        ( my $rs, $value ) = $dialog->radiolist( <<"EOF", \%choices, ( grep ( $value eq $_, keys %choices ) )[0] || 'yes' );

\\Z4\\Zb\\Zui-MSCP Backup Feature\\Zn

Do you want to activate the backup feature for i-MSCP?
\\Z \\Zn
EOF
        return $rs if $rs >= 30;
    }

    ::setupSetQuestion( 'BACKUP_IMSCP', $value );
    0;
}

=item customersBackupDialog( \%dialog )

 Ask for customers backup

 Param iMSCP::Dialog \%dialog
 Return int 0 (NEXT), 30 (BACK) or 50 (ESC)

=cut

sub customersBackupDialog
{
    my ( $self, $dialog ) = @_;

    my $value = ::setupGetQuestion( 'BACKUP_FEATURE', iMSCP::Getopt->preseed ? 'yes' : '' );
    my %choices = ( 'yes', 'Yes', 'no', 'No' );

    if ( isOneOfStringsInList( iMSCP::Getopt->reconfigure, [ 'backup', 'all', 'forced' ] ) || !isStringInList( $value, keys %choices ) ) {
        ( my $rs, $value ) = $dialog->radiolist( <<"EOF", \%choices, ( grep ( $value eq $_, keys %choices ) )[0] || 'yes' );

\\Z4\\Zb\\ZuDomains Backup Feature\\Zn

Do you want to activate the backup feature for customers?
\\Z \\Zn
EOF
        return $rs unless $rs < 30;
    }

    ::setupSetQuestion( 'BACKUP_FEATURE', $value );
    0;
}

=item getPackageName( )

 See iMSCP::Packages::Abstract::getPackageName()

=cut

sub getPackageName
{
    my ( $self ) = @_;

    'Backup';
}

=item getPackageHumanName( )

 See iMSCP::Packages::Abstract::getPackageHumanName()

=cut

sub getPackageHumanName
{
    my ( $self ) = @_;

    sprintf( 'i-MSCP backup configurator (%s)', $self->getPackageVersion());
}

=item getPackageVersion( )

 See iMSCP::Packages::Abstract::getPackageVersion()

=cut

sub getPackageVersion
{
    my ( $self ) = @_;

    $self->getPackageImplVersion();
}

=item install( )

 See iMSCP::Packages::Abstract::install()

=cut

sub install
{
    my ( $self ) = @_;

    my $cronServer = iMSCP::Servers::Cron->factory();

    if ( ::setupGetQuestion( 'BACKUP_IMSCP' ) eq 'yes' ) {
        $cronServer->addTask( {
            TASKID  => __PACKAGE__ . '::iMSCP',
            MINUTE  => '@daily',
            COMMAND => "perl $::imscpConfig{'BACKEND_ROOT_DIR'}/backup/imscp-backup-imscp > $::imscpConfig{'LOG_DIR'}/imscp-backup-imscp.log 2>&1"
        } );
    }

    if ( ::setupGetQuestion( 'BACKUP_FEATURE' ) eq 'yes' ) {
        $cronServer->addTask( {
            TASKID  => __PACKAGE__ . '::Customers',
            MINUTE  => length $::imscpConfig{'BACKUP_MINUTE'} ? $::imscpConfig{'BACKUP_MINUTE'} : 40,
            HOUR    => length $::imscpConfig{'BACKUP_HOUR'} ? $::imscpConfig{'BACKUP_HOUR'} : 23,
            COMMAND => "perl $::imscpConfig{'BACKEND_ROOT_DIR'}/backup/imscp-backup-all > $::imscpConfig{'LOG_DIR'}/imscp-backup-all.log 2>&1"
        } );
    }
}

=item uninstall( )

 See iMSCP::Packages::Abstract::uninstall()

=cut

sub uninstall
{
    my ( $self ) = @_;

    my $cronServer = iMSCP::Servers::Cron->factory();

    $cronServer->deleteTask( { TASKID => __PACKAGE__ . '::iMSCP' } );
    $cronServer->deleteTask( { TASKID => __PACKAGE__ . '::Customers' } );
}

=back

=head1 AUTHOR

 Laurent Declercq <l.declercq@nuxwin.com>

=cut

1;
__END__
