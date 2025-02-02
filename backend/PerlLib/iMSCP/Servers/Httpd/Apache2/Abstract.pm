=head1 NAME

 iMSCP::Servers::Httpd::Apache2::Abstract - i-MSCP Apache2 server abstract implementation

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

package iMSCP::Servers::Httpd::Apache2::Abstract;

use strict;
use warnings;
use Array::Utils qw/ unique /;
use autouse 'Date::Format' => qw/ time2str /;
use autouse 'iMSCP::Crypt' => qw/ ALNUM decryptRijndaelCBC randomStr /;
use autouse 'iMSCP::Dialog::InputValidation' => qw/ isOneOfStringsInList isStringInList /;
use Carp qw/ croak /;
use Class::Autouse qw/ :nostat iMSCP::Servers::Sqld /;
use File::Basename;
use File::Spec;
use File::Temp;
use iMSCP::Boolean;
use iMSCP::Debug qw/ debug error /;
use iMSCP::Dir;
use iMSCP::Execute qw/ execute /;
use iMSCP::File;
use iMSCP::File::Attributes qw/ :immutable /;
use iMSCP::Getopt;
use iMSCP::Mount qw/ mount umount isMountpoint addMountEntry removeMountEntry /;
use iMSCP::Net;
use iMSCP::Rights qw/ setRights /;
use iMSCP::SystemUser;
use iMSCP::Template::Processor qw/ processBlocByRef /;
use Scalar::Defer;
use parent qw/ iMSCP::Servers::Httpd /;

my $TMPFS = lazy
    {
        my $tmpfs = iMSCP::Dir->new( dirname => "$::imscpConfig{'IMSCP_HOMEDIR'}/tmp/apache_tmpfs" )->make( { umask => 0027 } );
        return $tmpfs if isMountpoint( $tmpfs );

        mount(
            {
                fs_spec         => 'tmpfs',
                fs_file         => $tmpfs,
                fs_vfstype      => 'tmpfs',
                fs_mntops       => 'noexec,nosuid,size=32m',
                ignore_failures => TRUE # Ignore failures in case tmpfs isn't supported/allowed
            }
        );

        $tmpfs;
    };

=head1 DESCRIPTION

 i-MSCP Apache2 server abstract implementation.

=head1 PUBLIC METHODS

=over 4

=item registerSetupListeners()

 See iMSCP::Servers::Abstract::RegisterSetupListeners()

=cut

sub registerSetupListeners
{
    my ( $self ) = @_;

    $self->{'eventManager'}->registerOne(
        'beforeSetupDialog', sub { push @{ $_[0] }, sub { $self->askForApacheMPM( @_ ) }; }, $self->getServerPriority()
    );
}

=item askForApacheMPM( \%dialog )

 Ask for Apache MPM

 Param iMSCP::Dialog \%dialog
 Return int 0 (NEXT), 30 (BACK) or 50 (ESC)

=cut

sub askForApacheMPM
{
    my ( $self, $dialog ) = @_;

    my $default = $::imscpConfig{'DISTRO_CODENAME'} ne 'jessie' ? 'event' : 'worker';
    my $value = ::setupGetQuestion( 'HTTPD_MPM', $self->{'config'}->{'HTTPD_MPM'} || ( iMSCP::Getopt->preseed ? $default : '' ));
    my %choices = (
        # For Debian version prior Stretch we hide the MPM event due to:
        # - https://bz.apache.org/bugzilla/show_bug.cgi?id=53555
        # - https://support.plesk.com/hc/en-us/articles/213901685-Apache-crashes-scoreboard-is-full-not-at-MaxRequestWorkers
        ( $::imscpConfig{'DISTRO_CODENAME'} ne 'jessie' ? ( 'event', 'MPM Event' ) : () ),
        'itk', 'MPM Prefork with ITK module',
        'prefork', 'MPM Prefork ',
        'worker', 'MPM Worker '
    );

    if ( isOneOfStringsInList( iMSCP::Getopt->reconfigure, [ 'httpd', 'servers', 'all', 'forced' ] ) || !isStringInList( $value, keys %choices ) ) {
        ( my $rs, $value ) = $dialog->radiolist( <<"EOF", \%choices, ( grep ( $value eq $_, keys %choices ) )[0] || $default );

\\Z4\\Zb\\ZuApache MPM\\Zn

Please choose the Apache MPM you want use:
\\Z \\Zn
EOF
        return $rs unless $rs < 30;
    }

    ::setupSetQuestion( 'HTTPD_MPM', $value );
    $self->{'config'}->{'HTTPD_MPM'} = $value;
    0;
}

=item install( )

 See iMSCP::Servers::Abstract::install()

=cut

sub install
{
    my ( $self ) = @_;

    $self->_setVersion();
    $self->_makeDirs();
    $self->_copyDomainDisablePages();
    $self->_setupVlogger();
}

=item uninstall( )

 See iMSCP::Servers::Abstract::uninstall()

=cut

sub uninstall
{
    my ( $self ) = @_;

    $self->_removeVloggerSqlUser();
}

=item setBackendPermissions( )

 See iMSCP::Servers::Abstract::setBackendPermissions()

=cut

sub setBackendPermissions
{
    my ( $self ) = @_;

    # e.g. /var/www/imscp/backend/traffic/vlogger
    setRights( "$::imscpConfig{'BACKEND_ROOT_DIR'}/traffic/vlogger", {
        user  => $::imscpConfig{'ROOT_USER'},
        group => $::imscpConfig{'ROOT_GROUP'},
        mode  => '0750'
    } );
    # e.g. /var/log/apache2
    setRights( $self->{'config'}->{'HTTPD_LOG_DIR'}, {
        user  => $::imscpConfig{'ROOT_USER'},
        group => $::imscpConfig{'ADM_GROUP'},
        mode  => '0750'
    } );
    # e.g. /var/log/apache2/* (files only)
    setRights( $self->{'config'}->{'HTTPD_LOG_DIR'}, {
        user      => $::imscpConfig{'ROOT_USER'},
        group     => $::imscpConfig{'ADM_GROUP'},
        filemode  => '0640',
        recursive => iMSCP::Getopt->fixPermissions
    } );
    # e.g. /var/www/virtual/domain_disabled_pages
    setRights( "$::imscpConfig{'USER_WEB_DIR'}/domain_disabled_pages", {
        user      => $::imscpConfig{'ROOT_USER'},
        group     => $self->getRunningGroup(),
        dirmode   => '0550',
        filemode  => '0440',
        recursive => TRUE # Always fix permissions recursively
    } );
}

=item getServerName( )

 See iMSCP::Servers::Abstract::getServerName()

=cut

sub getServerName
{
    my ( $self ) = @_;

    'Apache';
}

=item getServerHumanName( )

 See iMSCP::Servers::Abstract::getServerHumanName()

=cut

sub getServerHumanName
{
    my ( $self ) = @_;

    sprintf( 'Apache %s (MPM %s)', $self->getServerVersion(), ucfirst $self->{'config'}->{'HTTPD_MPM'} );
}

=item getServerVersion( )

 See iMSCP::Servers::Abstract::getServerVersion()

=cut

sub getServerVersion
{
    my ( $self ) = @_;

    $self->{'config'}->{'HTTPD_VERSION'};
}

=item addUser( \%moduleData )

 See iMSCP::Servers::Httpd::addUser()

=cut

sub addUser
{
    my ( $self, $moduleData ) = @_;

    return if $moduleData->{'STATUS'} eq 'tochangepwd';

    $self->{'eventManager'}->trigger( 'beforeApacheAddUser', $moduleData );
    iMSCP::SystemUser->new( username => $self->getRunningUser())->addToGroup( $moduleData->{'GROUP'} );
    $self->{'eventManager'}->trigger( 'afterApacheAddUser', $moduleData );
}

=item deleteUser( \%moduleData )

 See iMSCP::Servers::Httpd::deleteUser()

=cut

sub deleteUser
{
    my ( $self, $moduleData ) = @_;

    $self->{'eventManager'}->trigger( 'beforeApacheDeleteUser', $moduleData );
    iMSCP::SystemUser->new( username => $self->getRunningUser())->removeFromGroup( $moduleData->{'GROUP'} );
    $self->{'eventManager'}->trigger( 'afterApacheDeleteUser', $moduleData );
}

=item addDomain( \%moduleData )

 See iMSCP::Servers::Httpd::addDomain()

=cut

sub addDomain
{
    my ( $self, $moduleData ) = @_;

    $self->{'eventManager'}->trigger( 'beforeApacheAddDomain', $moduleData );
    $self->_addCfg( $moduleData );
    $self->_addFiles( $moduleData );
    $self->{'eventManager'}->trigger( 'afterApacheAddDomain', $moduleData );
}

=item restoreDomain( \%moduleData )

 See iMSCP::Servers::Httpd::restoreDmn()

=cut

sub restoreDomain
{
    my ( $self, $moduleData ) = @_;

    $self->{'eventManager'}->trigger( 'beforeApacheRestoreDomain', $moduleData );

    unless ( $moduleData->{'DOMAIN_TYPE'} eq 'als' ) {
        # Restore the first backup found
        for my $file ( iMSCP::Dir->new( dirname => "$moduleData->{'HOME_DIR'}/backups" )->getFiles() ) {
            next if $file !~ /^web-backup-.+?\.tar(?:\.(bz2|gz|lzma|xz))?$/ # Do not look like backup archive
                || -l "$moduleData->{'HOME_DIR'}/backups/$file";            # Don't follow symlinks (See #IP-990)

            my $archFormat = $1 || '';

            # Since we are now using immutable bit to protect some folders, we
            # must in order do the following to restore a backup archive:
            #
            # - Un-protect user homedir (clear immutable flag recursively)
            # - Restore web files
            # - Update status of sub, als and alssub, entities linked to the
            #   domain to 'torestore'
            #
            # The third and last task allow to set correct permissions and set
            # immutable flag on folders if needed for each entity

            if ( $archFormat eq 'bz2' ) {
                $archFormat = 'bzip2';
            } elsif ( $archFormat eq 'gz' ) {
                $archFormat = 'gzip';
            }

            # Un-protect homedir recursively
            clearImmutable( $moduleData->{'HOME_DIR'}, TRUE );

            my $cmd;
            if ( length $archFormat ) {
                $cmd = [ 'tar', '-x', '-p', "--$archFormat", '-C', $moduleData->{'HOME_DIR'}, '-f', "$moduleData->{'HOME_DIR'}/backups/$file" ];
            } else {
                $cmd = [ 'tar', '-x', '-p', '-C', $moduleData->{'HOME_DIR'}, '-f', "$moduleData->{'HOME_DIR'}/backups/$file" ];
            }

            my $rs = execute( $cmd, \my $stdout, \my $stderr );
            debug( $stdout ) if length $stdout;
            $rs == 0 or die( $stderr || 'Unknown error' );
            last;
        }
    }

    $self->_addFiles( $moduleData );
    $self->{'eventManager'}->trigger( 'afterApacheRestoreDomain', $moduleData );
}

=item disableDomain( \%moduleData )

 See iMSCP::Servers::Httpd::disableDomain()

=cut

sub disableDomain
{
    my ( $self, $moduleData ) = @_;

    $self->{'eventManager'}->trigger( 'beforeApacheDisableDomain', $moduleData );
    $self->_disableDomain( $moduleData );
    $self->{'eventManager'}->trigger( 'afterApacheDisableDomain', $moduleData );
}

=item deleteDomain( \%moduleData )

 See iMSCP::Servers::Httpd::deleteDomain()

=cut

sub deleteDomain
{
    my ( $self, $moduleData ) = @_;

    $self->{'eventManager'}->trigger( 'beforeApacheDeleteDomain', $moduleData );
    $self->_deleteDomain( $moduleData );
    $self->{'eventManager'}->trigger( 'afterApacheDeleteDomain', $moduleData );
}

=item addSubdomain( \%moduleData )

 See iMSCP::Servers::Httpd::addSubdomain()

=cut

sub addSubdomain
{
    my ( $self, $moduleData ) = @_;

    $self->{'eventManager'}->trigger( 'beforeApacheAddSubdomain', $moduleData );
    $self->_addCfg( $moduleData );
    $self->_addFiles( $moduleData );
    $self->{'eventManager'}->trigger( 'afterApacheAddSubdomain', $moduleData );
}

=item restoreSubdomain( \%moduleData )

 See iMSCP::Servers::Httpd::restoreSubdomain()

=cut

sub restoreSubdomain
{
    my ( $self, $moduleData ) = @_;

    $self->{'eventManager'}->trigger( 'beforeApacheRestoreSubdomain', $moduleData );
    $self->_addFiles( $moduleData );
    $self->{'eventManager'}->trigger( 'afterApacheRestoreSubdomain', $moduleData );
}

=item disableSubdomain( \%moduleData )

 See iMSCP::Servers::Httpd::disableSubdomain()

=cut

sub disableSubdomain
{
    my ( $self, $moduleData ) = @_;

    $self->{'eventManager'}->trigger( 'beforeApacheDisableSubdomain', $moduleData );
    $self->_disableDomain( $moduleData );
    $self->{'eventManager'}->trigger( 'afterApacheDisableSubdomain', $moduleData );
}

=item deleteSubdomain( \%moduleData )

 See iMSCP::Servers::Httpd::deleteSubdomain()

=cut

sub deleteSubdomain
{
    my ( $self, $moduleData ) = @_;

    $self->{'eventManager'}->trigger( 'beforeApacheDeleteSubdomain', $moduleData );
    $self->_deleteDomain( $moduleData );
    $self->{'eventManager'}->trigger( 'afterApacheDeleteSubdomain', $moduleData );
}

=item addHtpasswd( \%moduleData )

 See iMSCP::Servers::Httpd::addHtpasswd()

=cut

sub addHtpasswd
{
    my ( $self, $moduleData ) = @_;

    eval {
        clearImmutable( $moduleData->{'HOME_PATH'} );

        my $file = iMSCP::File->new( filename => "$moduleData->{'HOME_PATH'}/$self->{'config'}->{'HTTPD_HTACCESS_USERS_FILENAME'}" );
        my $fileContentRef = $file->getAsRef( !-f $file->{'filename'} );

        $self->{'eventManager'}->trigger( 'beforeApacheAddHtpasswd', $fileContentRef, $moduleData );
        ${ $fileContentRef } =~ s/^$moduleData->{'HTUSER_NAME'}:[^\n]*\n//gim;
        ${ $fileContentRef } .= "$moduleData->{'HTUSER_NAME'}:$moduleData->{'HTUSER_PASS'}\n";
        $self->{'eventManager'}->trigger( 'afterApacheAddHtpasswd', $fileContentRef, $moduleData );

        $file->save( 0027 )->owner( $::imscpConfig{'ROOT_USER'}, $self->getRunningGroup())->mode( 0640 );
    };

    my $error = $@; # Retain error if any
    # Set immutable bit if needed (even on error)
    setImmutable( $moduleData->{'HOME_PATH'} ) if $moduleData->{'WEB_FOLDER_PROTECTION'} eq 'yes';
    !length $error or die $error; # Propagate error if any
}

=item deleteHtpasswd( \%moduleData )

 See iMSCP::Servers::Httpd::deleteHtpasswd()

=cut

sub deleteHtpasswd
{
    my ( $self, $moduleData ) = @_;

    eval {
        clearImmutable( $moduleData->{'HOME_PATH'} );

        my $file = iMSCP::File->new( filename => "$moduleData->{'HOME_PATH'}/$self->{'config'}->{'HTTPD_HTACCESS_USERS_FILENAME'}" );
        my $fileContentRef = $file->getAsRef( !-f $file->{'filename'} );

        $self->{'eventManager'}->trigger( 'beforeApacheDeleteHtpasswd', $fileContentRef, $moduleData );
        ${ $fileContentRef } =~ s/^$moduleData->{'HTUSER_NAME'}:[^\n]*\n//gim;
        $self->{'eventManager'}->trigger( 'afterApacheDeleteHtpasswd', $fileContentRef, $moduleData );

        $file->save()->owner( $::imscpConfig{'ROOT_USER'}, $self->getRunningGroup())->mode( 0640 );
    };

    my $error = $@; # Retain error if any
    # Set immutable bit if needed (even on error)
    setImmutable( $moduleData->{'HOME_PATH'} ) if $moduleData->{'WEB_FOLDER_PROTECTION'} eq 'yes';
    !length $error or die $error; # Propagate error if any
}

=item addHtgroup( \%moduleData )

 See iMSCP::Servers::Httpd::addHtgroup()

=cut

sub addHtgroup
{
    my ( $self, $moduleData ) = @_;

    eval {
        clearImmutable( $moduleData->{'HOME_PATH'} );

        my $file = iMSCP::File->new( filename => "$moduleData->{'HOME_PATH'}/$self->{'config'}->{'HTTPD_HTACCESS_GROUPS_FILENAME'}" );
        my $fileContentRef = $file->getAsRef( !-f $file->{'filename'} );

        $self->{'eventManager'}->trigger( 'beforeApacheAddHtgroup', $fileContentRef, $moduleData );
        ${ $fileContentRef } =~ s/^$moduleData->{'HTGROUP_NAME'}:[^\n]*\n//gim;
        ${ $fileContentRef } .= "$moduleData->{'HTGROUP_NAME'}:$moduleData->{'HTGROUP_USERS'}\n";

        $self->{'eventManager'}->trigger( 'afterApacheAddHtgroup', $fileContentRef, $moduleData );
        $file->save( 0027 )->owner( $::imscpConfig{'ROOT_USER'}, $self->getRunningGroup())->mode( 0640 );
    };

    my $error = $@; # Retain error if any
    # Set immutable bit if needed (even on error)
    setImmutable( $moduleData->{'HOME_PATH'} ) if $moduleData->{'WEB_FOLDER_PROTECTION'} eq 'yes';
    !length $error or die $error; # Propagate error if any
}

=item deleteHtgroup( \%moduleData )

 See iMSCP::Servers::Httpd::deleteHtgroup()

=cut

sub deleteHtgroup
{
    my ( $self, $moduleData ) = @_;

    eval {
        clearImmutable( $moduleData->{'HOME_PATH'} );

        my $file = iMSCP::File->new( filename => "$moduleData->{'HOME_PATH'}/$self->{'config'}->{'HTTPD_HTACCESS_GROUPS_FILENAME'}" );
        my $fileContentRef = $file->getAsRef( !-f $file->{'filename'} );

        $self->{'eventManager'}->trigger( 'beforeApacheDeleteHtgroup', $fileContentRef, $moduleData );
        ${ $fileContentRef } =~ s/^$moduleData->{'HTGROUP_NAME'}:[^\n]*\n//gim;
        $self->{'eventManager'}->trigger( 'afterApacheDeleteHtgroup', $fileContentRef, $moduleData );

        $file->save()->owner( $::imscpConfig{'ROOT_USER'}, $self->getRunningGroup())->mode( 0640 );
    };

    my $error = $@; # Retain error if any
    # Set immutable bit if needed (even on error)
    setImmutable( $moduleData->{'HOME_PATH'} ) if $moduleData->{'WEB_FOLDER_PROTECTION'} eq 'yes';
    !length $error or die $error; # Propagate error if any
}

=item addHtaccess( \%moduleData )

 See iMSCP::Servers::Httpd::addHtaccess()

=cut

sub addHtaccess
{
    my ( $self, $moduleData ) = @_;

    return unless -d $moduleData->{'AUTH_PATH'};

    my $isImmutable = isImmutable( $moduleData->{'AUTH_PATH'} );

    eval {
        clearImmutable( $moduleData->{'AUTH_PATH'} ) if $isImmutable;

        my $file = iMSCP::File->new( filename => "$moduleData->{'AUTH_PATH'}/.htaccess" );
        my $fileContentRef = $file->getAsRef( !-f $file->{'filename'} );

        $self->{'eventManager'}->trigger( 'beforeApacheAddHtaccess', $fileContentRef, $moduleData );

        my $bc = <<"EOF";
AuthType $moduleData->{'AUTH_TYPE'}
AuthName "$moduleData->{'AUTH_NAME'}"
AuthBasicProvider file
AuthUserFile $moduleData->{'HOME_PATH'}/$self->{'config'}->{'HTTPD_HTACCESS_USERS_FILENAME'}
EOF
        if ( length $moduleData->{'HTUSERS'} ) {
            $bc .= <<"EOF";
Require user $moduleData->{'HTUSERS'}
EOF
        } elsif ( length $moduleData->{'HTGROUPS'} ) {
            $bc .= <<"EOF";
AuthGroupFile $moduleData->{'HOME_PATH'}/$self->{'config'}->{'HTTPD_HTACCESS_GROUPS_FILENAME'}
Require group $moduleData->{'HTGROUPS'}
EOF
        }

        chomp( $bc );

        # Add or replace entries
        processBlocByRef( $fileContentRef, '### START i-MSCP PROTECTION ###', '### END i-MSCP PROTECTION ###', <<"EOF", FALSE, FALSE, TRUE );
### START i-MSCP PROTECTION ###
$bc
### END i-MSCP PROTECTION ###
EOF
        $self->{'eventManager'}->trigger( 'afterApacheAddHtaccess', $fileContentRef, $moduleData );
        $file->save( 0027 )->owner( $moduleData->{'USER'}, $moduleData->{'GROUP'} )->mode( 0640 );
    };

    my $error = $@; # Retain error if any
    # Set immutable bit if needed (even on error)
    setImmutable( $moduleData->{'AUTH_PATH'} ) if $isImmutable;
    !length $error or die $error; # Propagate error if any
}

=item deleteHtaccess( \%moduleData )

 See iMSCP::Servers::Httpd::deleteHtaccess()

=cut

sub deleteHtaccess
{
    my ( $self, $moduleData ) = @_;

    return unless -d $moduleData->{'AUTH_PATH'} && -f "$moduleData->{'AUTH_PATH'}/.htaccess";

    my $isImmutable = isImmutable( $moduleData->{'AUTH_PATH'} );

    eval {
        clearImmutable( $moduleData->{'AUTH_PATH'} ) if $isImmutable;

        my $file = iMSCP::File->new( filename => "$moduleData->{'AUTH_PATH'}/.htaccess" );
        my $fileExist = -f $file->{'filename'};
        my $fileContentRef;

        if ( $fileExist ) {
            $fileContentRef = $file->getAsRef();
        } else {
            my $stamp = '';
            $fileContentRef = \$stamp;
        }

        $self->{'eventManager'}->trigger( 'beforeApacheDeleteHtaccess', $fileContentRef, $moduleData );
        processBlocByRef( $fileContentRef, '### START i-MSCP PROTECTION ###', '### END i-MSCP PROTECTION ###' );
        $self->{'eventManager'}->trigger( 'afterApacheDeleteHtaccess', $fileContentRef, $moduleData );

        if ( length ${ $fileContentRef } ) {
            $file->save()->owner( $moduleData->{'USER'}, $moduleData->{'GROUP'} )->mode( 0640 );
        } elsif ( $fileExist ) {
            $file->remove();
        }
    };

    my $error = $@; # Retain error if any
    # Set immutable bit if needed (even on error)
    setImmutable( $moduleData->{'AUTH_PATH'} ) if $isImmutable;
    !length $error or die $error; # Propagate error if any
}

=item buildConfFile( $srcFile, $trgFile, [, \%mdata = { } [, \%sdata [, \%params = { } ] ] ] )

 See iMSCP::Servers::Abstract::buildConfFile()

=cut

sub buildConfFile
{
    my ( $self, $srcFile, $trgFile, $mdata, $sdata, $params ) = @_;

    $self->{'eventManager'}->registerOne(
        'beforeApacheBuildConfFile',
        sub {
            return unless grep ( $_ eq $_[1], ( 'domain.tpl', 'domain_disabled.tpl' ) );

            if ( grep ( $_ eq $sdata->{'VHOST_TYPE'}, 'domain', 'domain_disabled' ) ) {
                processBlocByRef( $_[0], '# SECTION ssl BEGIN.', '# SECTION ssl ENDING.' );
                processBlocByRef( $_[0], '# SECTION fwd BEGIN.', '# SECTION fwd ENDING.' );
            } elsif ( grep ( $_ eq $sdata->{'VHOST_TYPE'}, 'domain_fwd', 'domain_ssl_fwd', 'domain_disabled_fwd' ) ) {
                if ( $sdata->{'VHOST_TYPE'} ne 'domain_ssl_fwd' ) {
                    processBlocByRef( $_[0], '# SECTION ssl BEGIN.', '# SECTION ssl ENDING.' );
                }

                processBlocByRef( $_[0], '# SECTION dmn BEGIN.', '# SECTION dmn ENDING.' );
            } elsif ( grep ( $_ eq $sdata->{'VHOST_TYPE'}, 'domain_ssl', 'domain_disabled_ssl' ) ) {
                processBlocByRef( $_[0], '# SECTION fwd BEGIN.', '# SECTION fwd ENDING.' );
            }
        },
        100
    );
    $self->SUPER::buildConfFile( $srcFile, $trgFile, $mdata, $sdata, $params );
    $self->{'reload'} ||= TRUE;
}

=item getTraffic( \%trafficDb )

 See iMSCP::Servers::Httpd::getTraffic()

=cut

sub getTraffic
{
    my ( $self, $trafficDb ) = @_;

    my $ldate = time2str( '%Y%m%d', time());

    debug( sprintf( 'Collecting HTTP traffic data' ));

    eval {
        $self->{'dbh'}->begin_work();
        my $sth = $self->{'dbh'}->prepare( 'SELECT vhost, bytes FROM httpd_vlogger WHERE ldate <= ? FOR UPDATE' );
        $sth->execute( $ldate );

        while ( my $row = $sth->fetchrow_hashref() ) {
            next unless exists $trafficDb->{$row->{'vhost'}};
            $trafficDb->{$row->{'vhost'}} += $row->{'bytes'};
        }

        $self->{'dbh'}->do( 'DELETE FROM httpd_vlogger WHERE ldate <= ?', undef, $ldate );
        $self->{'dbh'}->commit();
    };
    if ( $@ ) {
        $self->{'dbh'}->rollback();
        %{ $trafficDb } = ();
        die( sprintf( "Couldn't collect traffic data: %s", $@ ));
    }
}

=item getRunningUser( )

 See iMSCP::Servers::Httpd::getRunningUser()

=cut

sub getRunningUser
{
    my ( $self ) = @_;

    $self->{'config'}->{'HTTPD_USER'};
}

=item getRunningGroup( )

 See iMSCP::Servers::Httpd::getRunningGroup()

=cut

sub getRunningGroup
{
    my ( $self ) = @_;

    $self->{'config'}->{'HTTPD_GROUP'};
}

=back

=head1 PRIVATE METHODS

=over 4

=item _init( )

 See iMSCP::Servers::Httpd::_init()

=cut

sub _init
{
    my ( $self ) = @_;

    ref $self ne __PACKAGE__ or croak( sprintf( 'The %s class is an abstract class which cannot be instantiated', __PACKAGE__ ));

    @{ $self }{qw/ restart reload _templates cfgDir _web_folder_skeleton /} = ( FALSE, FALSE, {}, "$::imscpConfig{'CONF_DIR'}/apache", undef );
    $self->{'eventManager'}->register( 'afterApacheBuildConfFile', $self, -999 );
    $self->SUPER::_init();
}

=item _setVersion( )

 Set Apache version

 Return void, die on failure

=cut

sub _setVersion
{
    my ( $self ) = @_;

    die( sprintf( 'The %s class must implement the _setVersion() method', ref $self ));
}

=item _deleteDomain( \%moduleData )

 Process deleteDomain tasks

 Param hashref \%moduleData Data as provided by the Alias|Domain|Subdomain|SubAlias modules modules
 Return void, die on failure

=cut

sub _deleteDomain
{
    my ( $self, $moduleData ) = @_;

    $self->removeSites( $moduleData->{'DOMAIN_NAME'}, $moduleData->{'DOMAIN_NAME'} . '_ssl' );
    $self->_umountLogsFolder( $moduleData, $moduleData->{'DOMAIN_TYPE'} eq 'dmn' );

    unless ( $moduleData->{'SHARED_MOUNT_POINT'} || !-d $moduleData->{'WEB_DIR'} ) {
        my $userWebDir = File::Spec->canonpath( $::imscpConfig{'USER_WEB_DIR'} );
        my $parentDir = dirname( $moduleData->{'WEB_DIR'} );

        clearImmutable( $parentDir );
        clearImmutable( $moduleData->{'WEB_DIR'}, TRUE );

        iMSCP::Dir->new( dirname => $moduleData->{'WEB_DIR'} )->remove();

        if ( $parentDir ne $userWebDir ) {
            my $dir = iMSCP::Dir->new( dirname => $parentDir );
            if ( $dir->isEmpty() ) {
                clearImmutable( dirname( $parentDir ));
                $dir->remove();
            }
        }

        if ( $moduleData->{'WEB_FOLDER_PROTECTION'} eq 'yes' && $parentDir ne $userWebDir ) {
            do { setImmutable( $parentDir ) if -d $parentDir; } while ( $parentDir = dirname( $parentDir ) ) ne $userWebDir;
        }
    }

    for my $dir ( "$moduleData->{'HOME_DIR'}/logs/$moduleData->{'DOMAIN_NAME'}",
        "$self->{'config'}->{'HTTPD_LOG_DIR'}/$moduleData->{'DOMAIN_NAME'}"
    ) {
        iMSCP::Dir->new( dirname => $dir )->remove();
    }
}

=item _mountLogsFolder( \%moduleData )

 Mount httpd logs folder for the domain as referred to by module data

 Param hashref \%moduleData Data as provided by the Alias|Domain|Subdomain|SubAlias modules
 Return void, die on failure

=cut

sub _mountLogsFolder
{
    my ( $self, $moduleData ) = @_;

    my $fields = {
        fs_spec    => "$self->{'config'}->{'HTTPD_LOG_DIR'}/$moduleData->{'DOMAIN_NAME'}",
        fs_file    => "$moduleData->{'HOME_DIR'}/logs/$moduleData->{'DOMAIN_NAME'}",
        fs_vfstype => 'none',
        fs_mntops  => 'bind'
    };

    iMSCP::Dir->new( dirname => $fields->{'fs_file'} )->make( {
        umask          => 0027,
        user           => $::imscpConfig{'ROOT_USER'},
        group          => $moduleData->{'GROUP'},
        mode           => 0750,
        fixpermissions => iMSCP::Getopt->fixPermissions
    } );

    addMountEntry( "$fields->{'fs_spec'} $fields->{'fs_file'} $fields->{'fs_vfstype'} $fields->{'fs_mntops'}" );
    mount( $fields ) unless isMountpoint( $fields->{'fs_file'} );
}

=item _umountLogsFolder( \%moduleData [, $recursive = FALSE ] )

 Umount httpd logs folder for the domain as referred to by module data

 Param hashref \%moduleData Data as provided by the Alias|Domain|Subdomain|SubAlias modules
 Param boolean $recusive Flag indicating whether operation must be recursive
 Return void, die on failure

=cut

sub _umountLogsFolder
{
    my ( undef, $moduleData, $recursive ) = @_;

    my $fsFile = "$moduleData->{'HOME_DIR'}/logs";
    $fsFile .= "/$moduleData->{'DOMAIN_NAME'}" unless $recursive;

    removeMountEntry( qr%.*?[ \t]+\Q$fsFile\E(?:/|[ \t]+)[^\n]+% );
    umount( $fsFile, $recursive );
}

=item _disableDomain( \%moduleData )

 Disable a domain

 Param hashref \%moduleData Data as provided by the Alias|Domain modules
 Return void, die on failure

=cut

sub _disableDomain
{
    my ( $self, $moduleData ) = @_;

    iMSCP::Dir->new( dirname => "$self->{'config'}->{'HTTPD_LOG_DIR'}/$moduleData->{'DOMAIN_NAME'}" )->make( {
        umask          => 0027,
        user           => $::imscpConfig{'ROOT_USER'},
        group          => $moduleData->{'GROUP'},
        mode           => 02750,
        fixpermissions => iMSCP::Getopt->fixPermissions
    } );

    my $net = iMSCP::Net->getInstance();
    my @domainIPs = ( @{ $moduleData->{'DOMAIN_IPS'} }, ( $::imscpConfig{'CLIENT_DOMAIN_ALT_URLS'} eq 'yes' ? $moduleData->{'BASE_SERVER_IP'} : () ) );

    $self->{'eventManager'}->trigger( 'onApacheAddVhostIps', $moduleData, \@domainIPs );

    # If INADDR_ANY is found, map it to the wildcard sign and discard any other
    # IP, else, remove any duplicate IP address from the list
    @domainIPs = sort grep ($_ eq '0.0.0.0', @domainIPs) ? ( '*' ) : unique( map { $net->compressAddr( $_ ) } @domainIPs );

    my $serverData = {
        DOMAIN_IPS      => join( ' ', map { ( ( $_ eq '*' || $net->getAddrVersion( $_ ) eq 'ipv4' ) ? $_ : "[$_]" ) . ':80' } @domainIPs ),
        HTTP_URI_SCHEME => 'http://',
        HTTPD_LOG_DIR   => $self->{'config'}->{'HTTPD_LOG_DIR'},
        USER_WEB_DIR    => $::imscpConfig{'USER_WEB_DIR'},
        SERVER_ALIASES  => "www.$moduleData->{'DOMAIN_NAME'}" . ( $::imscpConfig{'CLIENT_DOMAIN_ALT_URLS'} eq 'yes'
            ? " $moduleData->{'ALIAS'}.$::imscpConfig{'BASE_SERVER_VHOST'}" : ''
        )
    };

    # Create http vhost

    if ( $moduleData->{'HSTS_SUPPORT'} ) {
        @{ $serverData }{qw/ FORWARD FORWARD_TYPE VHOST_TYPE /} = ( "https://$moduleData->{'DOMAIN_NAME'}/", 301, 'domain_disabled_fwd' );
    } else {
        $serverData->{'VHOST_TYPE'} = 'domain_disabled';
    }

    $self->buildConfFile( 'parts/domain_disabled.tpl', "$self->{'config'}->{'HTTPD_SITES_AVAILABLE_DIR'}/$moduleData->{'DOMAIN_NAME'}.conf",
        $moduleData, $serverData, { cached => TRUE }
    );
    $self->enableSites( $moduleData->{'DOMAIN_NAME'} );

    # Create https vhost (or delete it if SSL is disabled)

    if ( $moduleData->{'SSL_SUPPORT'} ) {
        @{ $serverData }{qw/ CERTIFICATE DOMAIN_IPS HTTP_URI_SCHEME VHOST_TYPE /} = (
            "$::imscpConfig{'FRONTEND_ROOT_DIR'}/data/certs/$moduleData->{'DOMAIN_NAME'}.pem",
            join( ' ', map { ( ( $_ eq '*' || $net->getAddrVersion( $_ ) eq 'ipv4' ) ? $_ : "[$_]" ) . ':443' } @domainIPs ),
            'https://',
            'domain_disabled_ssl'
        );
        $self->buildConfFile( 'parts/domain_disabled.tpl',
            "$self->{'config'}->{'HTTPD_SITES_AVAILABLE_DIR'}/$moduleData->{'DOMAIN_NAME'}_ssl.conf", $moduleData, $serverData, { cached => TRUE }
        );
        $self->enableSites( "$moduleData->{'DOMAIN_NAME'}_ssl" );
    } else {
        $self->removeSites( "$moduleData->{'DOMAIN_NAME'}_ssl" );
    }

    # Make sure that custom httpd conffile exists (cover case where file has been removed for any reasons)
    unless ( -f "$self->{'config'}->{'HTTPD_CUSTOM_SITES_DIR'}/$moduleData->{'DOMAIN_NAME'}.conf" ) {
        $serverData->{'SKIP_TEMPLATE_CLEANER'} = TRUE;
        $self->buildConfFile( 'parts/custom.conf.tpl', "$self->{'config'}->{'HTTPD_CUSTOM_SITES_DIR'}/$moduleData->{'DOMAIN_NAME'}.conf",
            $moduleData, $serverData, { cached => TRUE }
        );
    }
}

=item _addCfg( \%data )

 Add configuration files for the given domain

 Param hashref \%data Data as provided by the Alias|Domain|Subdomain|SubAlias modules
 Return void, die on failure

=cut

sub _addCfg
{
    my ( $self, $moduleData ) = @_;

    $self->{'eventManager'}->trigger( 'beforeApacheAddCfg', $moduleData );

    my $net = iMSCP::Net->getInstance();
    my @domainIPs = ( @{ $moduleData->{'DOMAIN_IPS'} }, ( $::imscpConfig{'CLIENT_DOMAIN_ALT_URLS'} eq 'yes' ? $moduleData->{'BASE_SERVER_IP'} : () ) );

    $self->{'eventManager'}->trigger( 'onApacheAddVhostIps', $moduleData, \@domainIPs );

    # If INADDR_ANY is found, map it to the wildcard sign and discard any other
    # IP, else, remove any duplicate IP address from the list
    @domainIPs = sort grep ($_ eq '0.0.0.0', @domainIPs) ? ( '*' ) : unique( map { $net->compressAddr( $_ ) } @domainIPs );

    my $serverData = {
        DOMAIN_IPS             => join( ' ', map { ( ( $_ eq '*' || $net->getAddrVersion( $_ ) eq 'ipv4' ) ? $_ : "[$_]" ) . ':80' } @domainIPs ),
        HTTPD_CUSTOM_SITES_DIR => $self->{'config'}->{'HTTPD_CUSTOM_SITES_DIR'},
        HTTPD_LOG_DIR          => $self->{'config'}->{'HTTPD_LOG_DIR'},
        SERVER_ALIASES         => "www.$moduleData->{'DOMAIN_NAME'}" . (
            $::imscpConfig{'CLIENT_DOMAIN_ALT_URLS'} eq 'yes' ? " $moduleData->{'ALIAS'}.$::imscpConfig{'BASE_SERVER_VHOST'}" : ''
        )
    };

    # Create http vhost

    if ( $moduleData->{'HSTS_SUPPORT'} ) {
        @{ $serverData }{qw/ FORWARD FORWARD_TYPE VHOST_TYPE /} = ( "https://$moduleData->{'DOMAIN_NAME'}/", 301, 'domain_fwd' );
    } elsif ( $moduleData->{'FORWARD'} ne 'no' ) {
        $serverData->{'VHOST_TYPE'} = 'domain_fwd';
        @{ $serverData }{qw/ X_FORWARDED_PROTOCOL X_FORWARDED_PORT /} = ( 'http', 80 ) if $moduleData->{'FORWARD_TYPE'} eq 'proxy';
    } else {
        $serverData->{'VHOST_TYPE'} = 'domain';
    }

    $self->buildConfFile( 'parts/domain.tpl', "$self->{'config'}->{'HTTPD_SITES_AVAILABLE_DIR'}/$moduleData->{'DOMAIN_NAME'}.conf", $moduleData,
        $serverData, { cached => TRUE }
    );
    $self->enableSites( $moduleData->{'DOMAIN_NAME'} );

    # Create https vhost (or delete it if SSL is disabled)

    if ( $moduleData->{'SSL_SUPPORT'} ) {
        @{ $serverData }{qw/ CERTIFICATE DOMAIN_IPS /} = (
            "$::imscpConfig{'FRONTEND_ROOT_DIR'}/data/certs/$moduleData->{'DOMAIN_NAME'}.pem",
            join( ' ', map { ( ( $_ eq '*' || $net->getAddrVersion( $_ ) eq 'ipv4' ) ? $_ : "[$_]" ) . ':443' } @domainIPs )
        );

        if ( $moduleData->{'FORWARD'} ne 'no' ) {
            @{ $serverData }{qw/ FORWARD FORWARD_TYPE VHOST_TYPE /} = ( $moduleData->{'FORWARD'}, $moduleData->{'FORWARD_TYPE'}, 'domain_ssl_fwd' );
            @{ $serverData }{qw/ X_FORWARDED_PROTOCOL X_FORWARDED_PORT /} = ( 'https', 443 ) if $moduleData->{'FORWARD_TYPE'} eq 'proxy';
        } else {
            $serverData->{'VHOST_TYPE'} = 'domain_ssl';
        }

        $self->buildConfFile( 'parts/domain.tpl', "$self->{'config'}->{'HTTPD_SITES_AVAILABLE_DIR'}/$moduleData->{'DOMAIN_NAME'}_ssl.conf",
            $moduleData, $serverData, { cached => TRUE }
        );
        $self->enableSites( "$moduleData->{'DOMAIN_NAME'}_ssl" );
    } else {
        $self->removeSites( "$moduleData->{'DOMAIN_NAME'}_ssl" );
    }

    unless ( -f "$self->{'config'}->{'HTTPD_CUSTOM_SITES_DIR'}/$moduleData->{'DOMAIN_NAME'}.conf" ) {
        $serverData->{'SKIP_TEMPLATE_CLEANER'} = TRUE;
        $self->buildConfFile( 'parts/custom.conf.tpl', "$self->{'config'}->{'HTTPD_CUSTOM_SITES_DIR'}/$moduleData->{'DOMAIN_NAME'}.conf",
            $moduleData, $serverData, { cached => TRUE }
        );
    }

    $self->{'eventManager'}->trigger( 'afterApacheAddCfg', $moduleData );
}


=item _getWebfolderSkeleton( \%moduleData )

 Get Web folder skeleton

 Param hashref \%moduleData Data as provided by the Alias|Domain|Subdomain|SubAlias modules
 Return string Path to Web folder skeleton on success, die on failure

=cut

sub _getWebfolderSkeleton
{
    my ( undef, $moduleData ) = @_;

    my $webFolderSkeleton = $moduleData->{'DOMAIN_TYPE'} eq 'dmn' ? 'domain' : ( $moduleData->{'DOMAIN_TYPE'} eq 'als' ? 'alias' : 'subdomain' );

    unless ( -d "$TMPFS/$webFolderSkeleton" ) {
        iMSCP::Dir->new( dirname => "$::imscpConfig{'CONF_DIR'}/skel/$webFolderSkeleton" )->copy( "$TMPFS/$webFolderSkeleton" );

        if ( $moduleData->{'DOMAIN_TYPE'} eq 'dmn' ) {
            for my $dir ( qw/ errors logs / ) {
                next if -d "$TMPFS/$webFolderSkeleton/$dir";
                iMSCP::Dir->new( dirname => "$TMPFS/$webFolderSkeleton/$dir" )->make();
            }
        }

        iMSCP::Dir->new( dirname => "$TMPFS/$webFolderSkeleton/htdocs" )->make() unless -d "$TMPFS/$webFolderSkeleton/htdocs";
    }

    "$TMPFS/$webFolderSkeleton";
}

=item _addFiles( \%moduleData )

 Add default directories and files for the given domain

 Param hashref \%moduleData Data as provided by the Alias|Domain|Subdomain|SubAlias modules
 Return void, die on failure

=cut

sub _addFiles
{
    my ( $self, $moduleData ) = @_;

    my $userWebDir = File::Spec->canonpath( $::imscpConfig{'USER_WEB_DIR'} );

    eval {
        $self->{'eventManager'}->trigger( 'beforeApacheAddFiles', $moduleData );

        # Whether or not permissions must be fixed recursively
        my $fixPermissions = iMSCP::Getopt->fixPermissions || index( $moduleData->{'ACTION'}, 'restore' ) != -1;

        iMSCP::Dir->new( dirname => "$self->{'config'}->{'HTTPD_LOG_DIR'}/$moduleData->{'DOMAIN_NAME'}" )->make( {
            umask          => 0027,
            user           => $::imscpConfig{'ROOT_USER'},
            group          => $moduleData->{'GROUP'},
            mode           => 02750,
            fixpermissions => $fixPermissions
        } );

        #
        ## Prepare Web folder
        #

        my $webFolderSkeleton = $self->_getWebfolderSkeleton( $moduleData );
        my $workingWebFolder = File::Temp->newdir( DIR => $TMPFS );

        iMSCP::Dir->new( dirname => $webFolderSkeleton )->copy( $workingWebFolder );

        if ( -d "$moduleData->{'WEB_DIR'}/htdocs" ) {
            iMSCP::Dir->new( dirname => "$workingWebFolder/htdocs" )->remove();
        } else {
            # Always fix permissions recursively for newly created Web folders
            $fixPermissions = TRUE;
        }

        if ( $moduleData->{'DOMAIN_TYPE'} eq 'dmn' && -d "$moduleData->{'WEB_DIR'}/errors" ) {
            iMSCP::Dir->new( dirname => "$workingWebFolder/errors" )->remove();
        }

        # Make sure that parent Web folder exists
        my $parentDir = dirname( $moduleData->{'WEB_DIR'} );
        unless ( -d $parentDir ) {
            clearImmutable( dirname( $parentDir ));

            if ( $userWebDir eq $parentDir ) {
                # Cover the case where $parentDir is equal to $::imscpConfig{'USER WEB DIR'},
                # even though, such a situation should never occurs
                $self->_makeDirs();
            } else {
                iMSCP::Dir->new( dirname => $parentDir )->make( {
                    umask => 0027,
                    user  => $moduleData->{'USER'},
                    group => $moduleData->{'GROUP'}
                } );
            }
        } else {
            clearImmutable( $parentDir );
        }

        clearImmutable( $moduleData->{'WEB_DIR'} ) if -d $moduleData->{'WEB_DIR'};

        if ( $moduleData->{'DOMAIN_TYPE'} eq 'dmn' && $self->{'config'}->{'HTTPD_MOUNT_CUSTOMER_LOGS'} ne 'yes' ) {
            $self->_umountLogsFolder( $moduleData, TRUE );
            iMSCP::Dir->new( dirname => "$moduleData->{'WEB_DIR'}/logs" )->remove();
            iMSCP::Dir->new( dirname => "$workingWebFolder/logs" )->remove();
        }

        #
        ## Create Web folder
        #

        iMSCP::Dir->new( dirname => $workingWebFolder )->copy( $moduleData->{'WEB_DIR'} );

        # Set ownership and permissions

        # Set ownership and permissions for the Web folder root
        # Web folder root vuxxx:vuxxx 0750 (no recursive)
        setRights( $moduleData->{'WEB_DIR'}, {
            user  => $moduleData->{'USER'},
            group => $moduleData->{'GROUP'},
            mode  => '0750'
        } );

        # Get list of possible files/directories inside the Web folder root
        my @files = iMSCP::Dir->new( dirname => $webFolderSkeleton )->getAll();

        # Set ownership for file/directory
        for my $file ( @files ) {
            next unless -e "$moduleData->{'WEB_DIR'}/$file";
            setRights( "$moduleData->{'WEB_DIR'}/$file", {
                user      => $moduleData->{'USER'},
                group     => $moduleData->{'GROUP'},
                recursive => $fixPermissions
            } );
        }

        if ( $moduleData->{'DOMAIN_TYPE'} eq 'dmn' ) {
            # Set specific ownership and permissions for .htgroup and .htpasswd files
            for my $file ( qw/ .htgroup .htpasswd / ) {
                next unless -f "$moduleData->{'WEB_DIR'}/$file";
                setRights( "$moduleData->{'WEB_DIR'}/$file", {
                    user  => $::imscpConfig{'ROOT_USER'},
                    group => $self->getRunningGroup(),
                    mode  => '0640'
                } );
            }

            # Set specific ownership for logs directory
            if ( $self->{'config'}->{'HTTPD_MOUNT_CUSTOMER_LOGS'} eq 'yes' ) {
                setRights( "$moduleData->{'WEB_DIR'}/logs", {
                    user  => $::imscpConfig{'ROOT_USER'},
                    group => $moduleData->{'GROUP'},
                    #recursive => $fixPermissions
                } );
            }
        }

        # Set permissions for files/directories
        for my $file ( @files ) {
            next unless -e "$moduleData->{'WEB_DIR'}/$file";
            setRights( "$moduleData->{'WEB_DIR'}/$file", {
                dirmode   => '0750',
                filemode  => '0640',
                recursive => $file =~ /^(?:00_private|cgi-bin|htdocs)$/ ? 0 : $fixPermissions
            } );
        }

        $self->_mountLogsFolder( $moduleData ) if $self->{'config'}->{'HTTPD_MOUNT_CUSTOMER_LOGS'} eq 'yes';
        $self->{'eventManager'}->trigger( 'afterApacheAddFiles', $moduleData );
    };

    my $error = $@; # Retain error if any

    # Set immutable bit if needed (even on error)
    if ( $moduleData->{'WEB_FOLDER_PROTECTION'} eq 'yes' ) {
        my $dir = $moduleData->{'WEB_DIR'};
        do { setImmutable( $dir ); } while ( $dir = dirname( $dir ) ) ne $userWebDir;
    }

    !length $error or die $error; # Propagate error if any
}

=item _makeDirs( )

 Create directories

 Return void, die on failure

=cut

sub _makeDirs
{
    my ( $self ) = @_;

    iMSCP::Dir->new( dirname => $self->{'config'}->{'HTTPD_LOG_DIR'} )->make( {
        umask          => 0027,
        user           => $::imscpConfig{'ROOT_USER'},
        group          => $::imscpConfig{'ADM_GROUP'},
        mode           => 0750,
        fixpermissions => iMSCP::Getopt->fixPermissions
    } );

    iMSCP::Dir->new( dirname => $::imscpConfig{'USER_WEB_DIR'} )->make( {
        user           => $::imscpConfig{'ROOT_USER'},
        group          => $::imscpConfig{'ROOT_GROUP'},
        mode           => 0755,
        fixpermissions => iMSCP::Getopt->fixPermissions
    } );
}

=item _copyDomainDisablePages( )

 Copy pages for disabled domains

 Return int 0 on success, other on failure

=cut

sub _copyDomainDisablePages
{
    iMSCP::Dir->new( dirname => "$::imscpConfig{'CONF_DIR'}/skel/domain_disabled_pages" )->copy(
        "$::imscpConfig{'USER_WEB_DIR'}/domain_disabled_pages"
    );
}

=item _setupVlogger( )

 Setup vlogger

 Return void, die on failure

=cut

sub _setupVlogger
{
    my ( $self ) = @_;

    {
        my $dbSchemaFile = File::Temp->new();
        print $dbSchemaFile <<"EOF";
USE `{DATABASE_NAME}`;

CREATE TABLE IF NOT EXISTS httpd_vlogger (
  vhost varchar(255) NOT NULL,
  ldate int(8) UNSIGNED NOT NULL,
  bytes int(32) UNSIGNED NOT NULL DEFAULT '0',
  PRIMARY KEY(vhost,ldate)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
EOF
        $dbSchemaFile->close();
        $self->buildConfFile( $dbSchemaFile, undef, undef, { DATABASE_NAME => ::setupGetQuestion( 'DATABASE_NAME' ) }, { srcname => 'vlogger.sql' } );

        my $mysqlDefaultsFile = File::Temp->new();
        print $mysqlDefaultsFile <<"EOF";
[mysql]
host = {HOST}
port = {PORT}
user = "{USER}"
password = "{PASSWORD}"
EOF
        $mysqlDefaultsFile->close();
        $self->buildConfFile( $mysqlDefaultsFile, undef, undef,
            {
                HOST     => ::setupGetQuestion( 'DATABASE_HOST' ),
                PORT     => ::setupGetQuestion( 'DATABASE_PORT' ),
                USER     => ::setupGetQuestion( 'DATABASE_USER' ) =~ s/"/\\"/gr,
                PASSWORD => decryptRijndaelCBC( $::imscpKEY, $::imscpIV, ::setupGetQuestion( 'DATABASE_PASSWORD' )) =~ s/"/\\"/gr
            },
            { srcname => 'mysql-defaults-file' }
        );

        my $rs = execute( "mysql --defaults-file=$mysqlDefaultsFile < $dbSchemaFile", \my $stdout, \my $stderr );
        debug( $stdout ) if length $stdout;
        $rs == 0 or die( $stderr || 'Unknown error' );
    }

    my $dbHost = ::setupGetQuestion( 'DATABASE_HOST' );
    $dbHost = '127.0.0.1' if $dbHost eq 'localhost';
    my $dbPort = ::setupGetQuestion( 'DATABASE_PORT' );
    my $dbName = ::setupGetQuestion( 'DATABASE_NAME' );
    my $dbUser = 'vlogger_user';
    my $dbUserHost = ::setupGetQuestion( 'DATABASE_USER_HOST' );
    $dbUserHost = '127.0.0.1' if $dbUserHost eq 'localhost';
    my $oldUserHost = $::imscpOldConfig{'DATABASE_USER_HOST'};
    my $dbPass = randomStr( 16, ALNUM );

    my $sqlServer = iMSCP::Servers::Sqld->factory();

    for my $host ( $dbUserHost, $oldUserHost, 'localhost' ) {
        next unless length $host;
        $sqlServer->dropUser( $dbUser, $host );
    }

    $sqlServer->createUser( $dbUser, $dbUserHost, $dbPass );

    # No need to escape wildcard characters. See https://bugs.mysql.com/bug.php?id=18660
    my $qDbName = $self->{'dbh'}->quote_identifier( $dbName );
    $self->{'dbh'}->do( "GRANT SELECT, INSERT, UPDATE ON $qDbName.httpd_vlogger TO ?\@?", undef, $dbUser, $dbUserHost );

    $self->buildConfFile( iMSCP::File->new( filename => "$self->{'config'}->{'HTTPD_CONF_DIR'}/vlogger.conf" )->set( <<"EOF" ),
# vlogger configuration file - auto-generated by i-MSCP
#     DO NOT EDIT THIS FILE BY HAND -- YOUR CHANGES WILL BE OVERWRITTEN
dsn    dbi:mysql:database={DATABASE_NAME};host={DATABASE_HOST};port={DATABASE_PORT}
user   {DATABASE_USER}
pass   {DATABASE_PASSWORD}
dump   30
EOF
        undef,
        undef,
        {
            DATABASE_NAME         => $dbName,
            DATABASE_HOST         => $dbHost,
            DATABASE_PORT         => $dbPort,
            DATABASE_USER         => $dbUser,
            DATABASE_PASSWORD     => $dbPass,
            SKIP_TEMPLATE_CLEANER => TRUE
        },
        {
            umask   => 0027,
            mode    => 0640,
            srcname => 'vlogger.conf'
        }
    );
}

=item _removeVloggerSqlUser( )

 Remove vlogger SQL user

 Return void, die on failure

=cut

sub _removeVloggerSqlUser
{

    return iMSCP::Servers::Sqld->factory()->dropUser( 'vlogger_user', '127.0.0.1' ) if $::imscpConfig{'DATABASE_USER_HOST'} eq 'localhost';

    iMSCP::Servers::Sqld->factory()->dropUser( 'vlogger_user', $::imscpConfig{'DATABASE_USER_HOST'} );
}

=back

=head1 EVENT LISTENERS

=over 4

=item afterApacheBuildConfFile( $apacheServer, \$cfgTpl, $filename, \$trgFile, \%moduleData, \%apacheServerData, \%apacheServerConfig, \%parameters )

 Event listener that minify the Apache2 production files by removing unwanted sections, comments and empty new lines

 Param scalar $apacheServer iMSCP::Servers::Httpd::Apache2::Abstract instance
 Param scalar \$scalar Reference to Apache conffile
 Param string $filename Apache template name
 Param scalar \$trgFile Target file path
 Param hashref \%moduleData Data as provided by the Alias|Domain|Subdomain|SubAlias modules
 Param hashref \%apacheServerData Apache server data
 Param hashref \%apacheServerConfig Apache server data
 Param hashref \%parameters OPTIONAL Parameters:
  - user  : File owner (default: root)
  - group : File group (default: root
  - mode  : File mode (default: 0644)
  - cached : Whether or not loaded file must be cached in memory
 Return void, die on failure

=cut

sub afterApacheBuildConfFile
{
    my ( $self, $cfgTpl, $filename, undef, $moduleData, $apacheServerData ) = @_;

    if ( $apacheServerData->{'SKIP_TEMPLATE_CLEANER'} ) {
        $apacheServerData->{'SKIP_TEMPLATE_CLEANER'} = FALSE;
        return;
    }

    # Remove unwanted sections
    if ( $filename eq 'domain.tpl' ) {
        if ( index( $apacheServerData->{'VHOST_TYPE'}, 'fwd' ) == -1 ) {
            if ( $self->{'config'}->{'HTTPD_MPM'} eq 'itk' ) {
                processBlocByRef( $cfgTpl, '# SECTION suexec BEGIN.', '# SECTION suexec ENDING.' );
            } else {
                processBlocByRef( $cfgTpl, '# SECTION itk BEGIN.', '# SECTION itk ENDING.' );
            }

            processBlocByRef( $cfgTpl, '# SECTION cgi BEGIN.', '# SECTION cgi ENDING.' ) if $moduleData->{'CGI_SUPPORT'} ne 'yes';
        } elsif ( $moduleData->{'FORWARD'} ne 'no' ) {
            if ( $moduleData->{'FORWARD_TYPE'} eq 'proxy'
                && ( !$moduleData->{'HSTS_SUPPORT'} || index( $apacheServerData->{'VHOST_TYPE'}, 'ssl' ) != -1 )
            ) {
                processBlocByRef( $cfgTpl, '# SECTION std_fwd BEGIN.', '# SECTION std_fwd ENDING.' );

                if ( index( $moduleData->{'FORWARD'}, 'https' ) != 0 ) {
                    processBlocByRef( $cfgTpl, '# SECTION ssl_proxy BEGIN.', '# SECTION ssl_proxy ENDING.' );
                }
            } else {
                processBlocByRef( $cfgTpl, '# SECTION proxy_fwd BEGIN.', '# SECTION proxy_fwd ENDING.' );
            }
        } else {
            processBlocByRef( $cfgTpl, '# SECTION proxy_fwd BEGIN.', '# SECTION proxy_fwd ENDING.' );
        }
    }

    # Minify final configuration file by removing comments and empty newlines
    ${ $cfgTpl } =~ s/^\s*(?:#.*?)?\n//gm;
}

=item _shutdown( )

 See iMSCP::Servers::Abstract::_shutdown()

=cut

sub _shutdown
{
    my ( $self ) = @_;

    my $tmpfs = "$::imscpConfig{'IMSCP_HOMEDIR'}/tmp/apache_tmpfs";
    return unless -d $tmpfs;
    umount( $tmpfs ) if isMountpoint( $tmpfs );
    iMSCP::Dir->new( dirname => $tmpfs )->remove();
}

=back

=head1 AUTHOR

 Laurent Declercq <l.declercq@nuxwin.com>

=cut

1;
__END__
