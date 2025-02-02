=head1 NAME

 iMSCP::Servers::Php - Factory and abstract implementation for the i-MSCP PHP servers

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

package iMSCP::Servers::Php;

use strict;
use warnings;
use autouse 'iMSCP::Dialog::InputValidation' => qw/ isOneOfStringsInList isStringInList /;
use autouse 'iMSCP::Rights' => qw/ setRights /;
use Carp qw/ croak /;
use Class::Autouse qw/ :nostat iMSCP::Getopt iMSCP::Servers::Httpd /;
use File::Basename;
use File::Spec;
use iMSCP::Boolean;
use iMSCP::Debug qw/ debug error getMessageByType /;
use iMSCP::Dir;
use iMSCP::File;
use iMSCP::Template::Processor qw/ processBlocByRef /;
use parent 'iMSCP::Servers::Abstract';

=head1 DESCRIPTION

 This class provides a factory and an abstract implementation for the i-MSCP PHP servers.

 TODO (Enterprise Edition):
 - Depending of selected Httpd server, customer should be able to choose between several SAPI:
  - Apache with MPM Event, Worker or Prefork: cgi or fpm
  - Apache with MPM ITK                      : apache2handler or fpm
  - Nginx (Implementation not available yet) : fpm
  - ...
 - Customer should be able to select the PHP version to use (Merge of PhpSwitcher plugin in core)

=head1 CLASS METHODS

=over 4

=item getServerPriority( )

 See iMSCP::Servers::Abstract::getServerPriority()

=cut

sub getServerPriority
{
    250;
}

=back

=head1 PUBLIC METHODS

=over 4

=item registerSetupListeners()

 See iMSCP::Servers::Abstract::RegisterSetupListeners()

=cut

sub registerSetupListeners
{
    my ( $self ) = @_;

    $self->{'eventManager'}->registerOne(
        'beforeSetupDialog',
        sub {
            push @{ $_[0] },
                sub { $self->askForPhpVersion( @_ ) },
                sub { $self->askForPhpSapi( @_ ) },
                sub { $self->askForFastCGIconnectionType( @_ ) };
        },
        # We want show these dialogs after the httpd server dialog because
        # we rely on httpd server configuration parameters (httpd server priority - 10)
        $self->{'httpd'}->getServerPriority()-10
    );
}

=item askForPhpVersion( \%dialog )

 Ask for PHP version (PHP version for customers)

 Param iMSCP::Dialog \%dialog
 Return int 0 (NEXT), 30 (BACK) or 50 (ESC)

=cut

sub askForPhpVersion
{
    my ( $self, $dialog ) = @_;

    my @availablePhpVersions = $self->getAvailablePhpVersions();
    my %choices;
    @{choices}{@availablePhpVersions} = map { "PHP $_" } @availablePhpVersions;

    my $value = ::setupGetQuestion(
        'PHP_VERSION', $self->{'config'}->{'PHP_VERSION'} || ( iMSCP::Getopt->preseed ? ( sort keys %choices )[0] : '' )
    );

    if ( isOneOfStringsInList( iMSCP::Getopt->reconfigure, [ 'php', 'servers', 'all', 'forced' ] ) || !isStringInList( $value, keys %choices ) ) {
        ( my $rs, $value ) = $dialog->radiolist( <<'EOF', \%choices, ( grep ( $value eq $_, keys %choices ) )[0] || ( sort keys %choices )[0] );

\Z4\Zb\ZuPHP version for customers\Zn

Please choose the PHP version for the customers:
\Z \Zn
EOF
        return $rs unless $rs < 30;
    }

    ::setupSetQuestion( 'PHP_VERSION', $value );
    $self->{'config'}->{'PHP_VERSION'} = $value;
    0;
}

=item askForPhpSapi( \%dialog )

 Ask for PHP SAPI

 Param iMSCP::Dialog \%dialog
 Return int 0 (NEXT), 30 (BACK) or 50 (ESC)

=cut

sub askForPhpSapi
{
    my ( $self, $dialog ) = @_;

    my $value = ::setupGetQuestion( 'PHP_SAPI', $self->{'config'}->{'PHP_SAPI'} || ( iMSCP::Getopt->preseed ? 'fpm' : '' ));
    my %choices = ( 'fpm', 'PHP through PHP FastCGI Process Manager (fpm SAPI)' );

    if ( $self->{'httpd'}->getServerName() eq 'Nginx' ) {
        ::setupSetQuestion( 'PHP_SAPI', 'fpm' );
        $self->{'config'}->{'PHP_SAPI'} = 'fpm';
        return 0;
    }

    if ( $self->{'httpd'}->getServerName() eq 'Apache' ) {
        if ( $self->{'httpd'}->{'config'}->{'HTTPD_MPM'} eq 'itk' ) {
            # Apache PHP module only works with Apache's prefork based MPM
            # We allow it only with the Apache's ITK MPM because the Apache's prefork MPM
            # doesn't allow to constrain each individual vhost to a particular system user/group.
            $choices{'apache2handler'} = 'PHP through Apache PHP module (apache2handler SAPI)';
        } else {
            # Apache Fcgid module doesn't work with Apache's ITK MPM
            # https://lists.debian.org/debian-apache/2013/07/msg00147.html
            $choices{'cgi'} = 'PHP through Apache Fcgid module (cgi SAPI)';
        }
    } else {
        error( 'Unsupported Httpd server implementation' );
        return 1;
    }

    if ( isOneOfStringsInList( iMSCP::Getopt->reconfigure, [ 'php', 'servers', 'all', 'forced' ] ) || !isStringInList( $value, keys %choices ) ) {
        ( my $rs, $value ) = $dialog->radiolist( <<'EOF', \%choices, ( grep ( $value eq $_, keys %choices ) )[0] || 'fpm' );

\Z4\Zb\ZuPHP SAPI for customers\Zn

Please choose the PHP SAPI for the customers:
\Z \Zn
EOF
        return $rs unless $rs < 30;
    }

    ::setupSetQuestion( 'PHP_SAPI', $value );
    $self->{'config'}->{'PHP_SAPI'} = $value;
    0;
}

=item askForFastCGIconnectionType( )

 Ask for FastCGI connection type (PHP-FPM)

 Param iMSCP::Dialog \%dialog
 Return int 0 (NEXT), 30 (BACK) or 50 (ESC)

=cut

sub askForFastCGIconnectionType
{
    my ( $self, $dialog ) = @_;

    return 0 unless $self->{'config'}->{'PHP_SAPI'} eq 'fpm';

    my $value = ::setupGetQuestion( 'PHP_FPM_LISTEN_MODE', $self->{'config'}->{'PHP_FPM_LISTEN_MODE'} || ( iMSCP::Getopt->preseed ? 'uds' : '' ));
    my %choices = ( 'tcp', 'TCP sockets over the loopback interface', 'uds', 'Unix Domain Sockets (recommended)' );

    if ( isOneOfStringsInList( iMSCP::Getopt->reconfigure, [ 'php', 'servers', 'all', 'forced' ] ) || !isStringInList( $value, keys %choices ) ) {
        ( my $rs, $value ) = $dialog->radiolist( <<'EOF', \%choices, ( grep ( $value eq $_, keys %choices ) )[0] || 'uds' );

\Z4\Zb\ZuPHP-FPM - FastCGI connection type\Zn

Please choose the FastCGI connection type that you want use:
\Z \Zn
EOF
        return $rs unless $rs < 30;
    }

    ::setupSetQuestion( 'PHP_FPM_LISTEN_MODE', $value );
    $self->{'config'}->{'PHP_FPM_LISTEN_MODE'} = $value;
    0;
}

=item preinstall( )

 See iMSCP::Servers::Abstract::preinstall()

=cut

sub preinstall
{
    my ( $self ) = @_;

    $self->_setFullVersion();
}

=item setBackendPermissions( )

 See iMSCP::Servers::Abstract::setBackendPermissions()

=cut

sub setBackendPermissions
{
    my ( $self ) = @_;

    return unless $self->{'config'}->{'PHP_SAPI'} eq 'cgi';

    setRights( $self->{'config'}->{'PHP_FCGI_STARTER_DIR'}, {
        user  => $::imscpConfig{'ROOT_USER'},
        group => $::imscpConfig{'ROOT_GROUP'},
        mode  => '0555'
    } );
}

=item getServerName( )

 See iMSCP::Servers::Abstract::getServerName()

=cut

sub getServerName
{
    my ( $self ) = @_;

    'Php';
}

=item getServerHumanName( )

 See iMSCP::Servers::Abstract::getServerHumanName()

=cut

sub getServerHumanName
{
    my ( $self ) = @_;

    sprintf( 'PHP %s (%s)', $self->{'config'}->{'PHP_VERSION_FULL'}, $self->{'config'}->{'PHP_SAPI'} );
}

=item getServerVersion( )

 See iMSCP::Servers::Abstract::getServerVersion()

=cut

sub getServerVersion
{
    my ( $self ) = @_;

    $self->{'config'}->{'PHP_VERSION'};
}

=item getAvailablePhpVersions( )

 Return list of available PHP versions

 List must be PHP MAJOR.MINOR version strings such as 5.6, 7.0, 7.1, 7.2 ...

 Return List of available PHP versions, die on failure

=cut

sub getAvailablePhpVersions
{
    my ( $self ) = @_;

    die( sprintf( 'The %s class must implement the getAvailablePhpVersions() method', ref $self ));
}

=item addDomain( \%moduleData )

 Process addDomain tasks
 
  The following events *MUST* be triggered:
  - beforePhpAddDomain( \%moduleData )
  - afterPhpAddDomain( \%moduleData )

 Param hashref \%moduleData Data as provided by the Alias|Domains modules
 Return void, die on failure

=cut

sub addDomain
{
    my ( $self ) = @_;

    die( sprintf( 'The %s class must implement the addDomain() method', ref $self ));
}

=item disableDomain( \%moduleData )

 Process disableDomain tasks
 
  The following events *MUST* be triggered:
  - beforePhpDisableDomain( \%moduleData )
  - afterPhpDisableDomain( \%moduleData )

 Param hashref \%moduleData Data as provided by the Alias|Domain modules
 Return void, die on failure

=cut

sub disableDomain
{
    my ( $self ) = @_;

    die( sprintf( 'The %s class must implement the disableDomain() method', ref $self ));
}

=item deleteDomain( \%moduleData )

 Process deleteDomain tasks
 
  The following events *MUST* be triggered:
  - beforePhpDeleteDomain( \%moduleData )
  - afterPhpDeleteDomain( \%moduleData )

 Param hashref \%moduleData Data as provided by the Alias|Domain modules
 Return void, die on failure

=cut

sub deleteDomain
{
    my ( $self ) = @_;

    die( sprintf( 'The %s class must implement the deleteDomain() method', ref $self ));
}

=item addSubdomain( \%moduleData )

 Process addSubdomain tasks
 
  The following events *MUST* be triggered:
  - beforePhpAddSubdomain( \%moduleData )
  - afterPhpAddSubdomain( \%moduleData )

 Param hashref \%moduleData Data as provided by the Subdomain|SubAlias modules
 Return void, die on failure

=cut

sub addSubdomain
{
    my ( $self ) = @_;

    die( sprintf( 'The %s class must implement the addSubdomain() method', ref $self ));
}

=item disableSubdomain( \%moduleData )

 Process disableSubdomain tasks
 
  The following events *MUST* be triggered:
  - beforePhpDisableSubdomain( \%moduleData )
  - afterPhpDisableSubdomain( \%moduleData )

 Param hashref \%moduleData Data as provided by the Subdomain|SubAlias modules
 Return void, die on failure

=cut

sub disableSubdomain
{
    my ( $self ) = @_;

    die( sprintf( 'The %s class must implement the disableSubdomain() method', ref $self ));
}

=item deleteSubdomain( \%moduleData )

 Process deleteSubdomain tasks
 
  The following events *MUST* be triggered:
  - beforePhpDeleteSubdomain( \%moduleData )
  - afterPhpDeleteSubdomain( \%moduleData )

 Param hashref \%moduleData Data as provided by the Subdomain|SubAlias modules
 Return void, die on failure

=cut

sub deleteSubdomain
{
    my ( $self ) = @_;

    die( sprintf( 'The %s class must implement the deleteSubdomain() method', ref $self ));
}

=item enableModules( \@modules [, $phpVersion = $self->{'config'}->{'PHP_VERSION'} [, $phpSapi = $self->{'config'}->{'PHP_SAPI'} ] ] )

 Enable the given PHP modules

 Param array \@modules Array containing list of modules to enable
 Param string $phpVersion OPTIONAL PHP version to operate on (default to selected PHP alternative)
 Param string phpSApi OPTIONAL PHP SAPI to operate on (default to selected PHP SAPI)
 Return void, die on failure

=cut

sub enableModules
{
    my ( $self ) = @_;

    die( sprintf( 'The %s class must implement the enableModules() method', ref $self ));
}

=item disableModules( \@modules [, $phpVersion = $self->{'config'}->{'PHP_VERSION'} [, $phpSapi = $self->{'config'}->{'PHP_SAPI'} ] ] )

 Disable the given PHP modules

 Param array \@modules Array containing list of modules to disable
 Param string $phpVersion OPTIONAL PHP version to operate on (default to selected PHP alternative)
 Param string phpSApi OPTIONAL PHP SAPI to operate on (default to selected PHP SAPI)
 Return void, die on failure

=cut

sub disableModules
{
    my ( $self ) = @_;

    die( sprintf( 'The %s class must implement the disableModules() method', ref $self ));
}

=back

=head1 PRIVATE METHODS

=over 4

=item _init( )

 See iMSCP::Servers::Abstract::_init()

=cut

sub _init
{
    my ( $self ) = @_;

    ref $self ne __PACKAGE__ or croak( sprintf( 'The %s class is an abstract class which cannot be instantiated', __PACKAGE__ ));

    # Check for properties that must be defined in concret server implementations
    for my $property ( qw/ PHP_FPM_POOL_DIR PHP_FPM_RUN_DIR PHP_PEAR_DIR / ) {
        defined $self->{$property} or die( sprintf( 'The %s package must define the %s property', ref $self, $property ));
    }

    @{ $self }{qw/ reload restart _templates cfgDir httpd /} = ( {}, {}, {}, "$::imscpConfig{'CONF_DIR'}/php", iMSCP::Servers::Httpd->factory() );

    $self->{'eventManager'}
        ->register( "before@{ [ $self->{'httpd'}->getServerName() ] }BuildConfFile", $self )
        ->register( 'afterHttpdAddFiles', sub { $self->afterHttpdAddFiles( @_ ) } );
    $self->SUPER::_init();
}

=item _setFullVersion()

 Set full version for selected PHP version

 Full version = MAJOR.MINOR.TINY version such as 5.6.32
 
 Return void, croak on failure

=cut

sub _setFullVersion
{
    my ( $self ) = @_;

    die( sprintf( 'The %s class must implement the _setFullVersion() method', ref $self ));
}

=item _buildApacheHandlerConfig( \%moduleData )

 Build PHP apache2handler configuration for the given domain
 
 There are nothing special to do here. We trigger events for consistency reasons.

 Param hashref \%moduleData Data as provided by the Alias|Domain|SubAlias|Subdomain modules
 Return void, die on failure

=cut

sub _buildApacheHandlerConfig
{
    my ( $self, $moduleData ) = @_;

    if ( $moduleData->{'PHP_SUPPORT'} eq 'no'
        || $moduleData->{'PHP_CONFIG_LEVEL_DOMAIN'} ne $moduleData->{'DOMAIN_NAME'}
        || ( $moduleData->{'PHP_CONFIG_LEVEL'} eq 'per_site' && $moduleData->{'FORWARD'} eq 'yes' )
    ) {
        return;
    }

    $self->{'eventManager'}->trigger( 'beforePhpApacheHandlerSapiBuildConf', $moduleData );
    debug( sprintf( 'Building Apache2Handler configuration for the %s domain', $moduleData->{'DOMAIN_NAME'} ));
    $self->{'eventManager'}->trigger( 'afterPhpApacheHandlerSapiBuildConf', $moduleData );
}

=item _buildCgiConfig( \%moduleData )

 Build PHP CGI/FastCGI configuration for the given domain

 Param hashref \%moduleData Data as provided by the Alias|Domain|SubAlias|Subdomain modules
 Return void, die on failure

=cut

sub _buildCgiConfig
{
    my ( $self, $moduleData ) = @_;

    if ( $moduleData->{'PHP_SUPPORT'} eq 'no'
        || $moduleData->{'PHP_CONFIG_LEVEL_DOMAIN'} ne $moduleData->{'DOMAIN_NAME'}
        || ( $moduleData->{'PHP_CONFIG_LEVEL'} eq 'per_site' && $moduleData->{'FORWARD'} eq 'yes' )
    ) {
        return;
    }

    $self->{'eventManager'}->trigger( 'beforePhpCgiSapiBuildConf', $moduleData );

    debug( sprintf( 'Building PHP CGI/FastCGI configuration for the %s domain', $moduleData->{'DOMAIN_NAME'} ));

    #iMSCP::Dir->new( dirname => "$self->{'config'}->{'PHP_FCGI_STARTER_DIR'}/$moduleData->{'PHP_CONFIG_LEVEL_DOMAIN'}" )->remove();
    iMSCP::Dir->new( dirname => $self->{'config'}->{'PHP_FCGI_STARTER_DIR'} )->make( {
        user  => $::imscpConfig{'ROOT_USER'},
        group => $::imscpConfig{'ROOT_GROUP'},
        mode  => 0555
    } );

    for my $dir ( "$self->{'config'}->{'PHP_FCGI_STARTER_DIR'}/$moduleData->{'PHP_CONFIG_LEVEL_DOMAIN'}",
        "$self->{'config'}->{'PHP_FCGI_STARTER_DIR'}/$moduleData->{'PHP_CONFIG_LEVEL_DOMAIN'}/php$self->{'config'}->{'PHP_VERSION'}"
    ) {
        iMSCP::Dir->new( dirname => $dir )->make( {
            user  => $moduleData->{'USER'},
            group => $moduleData->{'GROUP'},
            mode  => 0550
        } );
    }

    my $serverData = {
        EMAIL_DOMAIN          => $moduleData->{'PHP_CONFIG_LEVEL_DOMAIN'},
        PHP_FCGI_CHILDREN     => $self->{'config'}->{'PHP_FCGI_CHILDREN'},
        PHP_FCGI_MAX_REQUESTS => $self->{'config'}->{'PHP_FCGI_MAX_REQUESTS'},
        PHP_VERSION           => $self->{'config'}->{'PHP_VERSION'},
        TMPDIR                => $moduleData->{'HOME_DIR'} . '/phptmp'
    };

    $self->buildConfFile(
        'cgi/php-fcgi-starter',
        "$self->{'config'}->{'PHP_FCGI_STARTER_DIR'}/$moduleData->{'PHP_CONFIG_LEVEL_DOMAIN'}/php-fcgi-starter",
        $moduleData,
        $serverData,
        {
            user   => $moduleData->{'USER'},
            group  => $moduleData->{'GROUP'},
            mode   => 0550,
            cached => TRUE
        }
    );
    $self->buildConfFile(
        'cgi/php.ini.user',
        "$self->{'config'}->{'PHP_FCGI_STARTER_DIR'}/$moduleData->{'PHP_CONFIG_LEVEL_DOMAIN'}/php$self->{'config'}->{'PHP_VERSION'}/php.ini",
        $moduleData,
        $serverData,
        {
            user   => $moduleData->{'USER'},
            group  => $moduleData->{'GROUP'},
            mode   => 0440,
            cached => TRUE
        }
    );
    $self->{'eventManager'}->trigger( 'afterPhpCgiSapiBuildConf', $moduleData );
}

=item _buildFpmConfig( \%moduleData )

 Build PHP fpm configuration for the given domain

 Param hashref \%moduleData Data as provided by the Alias|Domain|SubAlias|Subdomain modules
 Return void, die on failure

=cut

sub _buildFpmConfig
{
    my ( $self, $moduleData ) = @_;

    if ( $moduleData->{'PHP_SUPPORT'} eq 'no'
        || $moduleData->{'PHP_CONFIG_LEVEL_DOMAIN'} ne $moduleData->{'DOMAIN_NAME'}
        || ( $moduleData->{'PHP_CONFIG_LEVEL'} eq 'per_site' && $moduleData->{'FORWARD'} eq 'yes' )
    ) {
        return;
    }

    $self->{'eventManager'}->trigger( 'beforePhpFpmSapiBuildConf', $moduleData );

    debug( sprintf( 'Building PHP-FPM configuration for the %s domain', $moduleData->{'DOMAIN_NAME'} ));

    my $serverData = {
        EMAIL_DOMAIN                 => $moduleData->{'PHP_CONFIG_LEVEL_DOMAIN'},
        PHP_FPM_LISTEN_ENDPOINT      => ( $self->{'config'}->{'PHP_FPM_LISTEN_MODE'} eq 'uds' )
            ? "{PHP_FPM_RUN_DIR}/php{PHP_VERSION}-fpm-{PHP_CONFIG_LEVEL_DOMAIN}.sock"
            : '127.0.0.1:' . ( $self->{'config'}->{'PHP_FPM_LISTEN_PORT_START'}+$moduleData->{'PHP_FPM_LISTEN_PORT'} ),
        PHP_FPM_MAX_CHILDREN         => $self->{'config'}->{'PHP_FPM_MAX_CHILDREN'} // 6,
        PHP_FPM_MAX_REQUESTS         => $self->{'config'}->{'PHP_FPM_MAX_REQUESTS'} // 1000,
        PHP_FPM_MAX_SPARE_SERVERS    => $self->{'config'}->{'PHP_FPM_MAX_SPARE_SERVERS'} // 2,
        PHP_FPM_MIN_SPARE_SERVERS    => $self->{'config'}->{'PHP_FPM_MIN_SPARE_SERVERS'} // 1,
        PHP_FPM_PROCESS_IDLE_TIMEOUT => $self->{'config'}->{'PHP_FPM_PROCESS_IDLE_TIMEOUT'} || '60s',
        PHP_FPM_PROCESS_MANAGER_MODE => $self->{'config'}->{'PHP_FPM_PROCESS_MANAGER_MODE'} || 'ondemand',
        PHP_FPM_RUN_DIR              => $self->{'PHP_FPM_RUN_DIR'},
        PHP_FPM_START_SERVERS        => $self->{'config'}->{'PHP_FPM_START_SERVERS'} // 1,
        PHP_VERSION                  => $self->{'config'}->{'PHP_VERSION'},
        TMPDIR                       => "$moduleData->{'HOME_DIR'}/phptmp"
    };

    $self->buildConfFile(
        'fpm/pool.conf', "$self->{'PHP_FPM_POOL_DIR'}/$moduleData->{'PHP_CONFIG_LEVEL_DOMAIN'}.conf", $moduleData, $serverData, { cached => TRUE }
    );
    $self->{'reload'}->{$serverData->{'PHP_VERSION'}} ||= 1;
    $self->{'eventManager'}->trigger( 'afterPhpFpmSapiBuildConf', $moduleData );
}

=back

=head1 EVENT LISTENERS

=over 4

=item beforeApacheBuildConfFile( $phpServer, \$cfgTpl, $filename, \$trgFile, \%mdata, \%sdata, \%sconfig, $params )

 Event listener that inject PHP configuration in Apache2 vhosts

 Param iMSCP::Servers::Php $phpServer instance
 Param scalarref \$cfgTpl Reference to Apache template content
 Param string $filename Apache template name
 Param scalar \$trgFile Target file path
 Param hashref \%mdata Data as provided by the Alias|Domain|Subdomain|SubAlias modules
 Param hashref \%sconfig Apache server data
 Param hashref \%params OPTIONAL parameters:
  - umask   : UMASK(2) for a new file. For instance if the given umask is 0027, mode will be: 0666 & ~0027 = 0640 (in octal)
  - user    : File owner (default: EUID for a new file, no change for existent file)
  - group   : File group (default: EGID for a new file, no change for existent file)
  - mode    : File mode (default: 0666 & ~(UMASK(2) || 0) for a new file, no change for existent file )
  - cached  : Whether or not loaded file must be cached in memory
  - srcname : Make it possible to override default source filename passed into event listeners. Most used when $srcFile is a TMPFILE(3) file
 Return void, die on failure

=cut

sub beforeApacheBuildConfFile
{
    my ( $phpServer, $cfgTpl, $filename, $trgFile, $mdata, $sdata, $sconfig, $params ) = @_;

    return unless $filename eq 'domain.tpl' && grep ( $_ eq $sdata->{'VHOST_TYPE'}, 'domain', 'domain_ssl' );

    $phpServer->{'eventManager'}->trigger(
        'beforePhpApacheBuildConfFile', $phpServer, $cfgTpl, $filename, $trgFile, $mdata, $sdata, $sconfig, $params
    );

    debug( sprintf( 'Injecting PHP configuration in Apache2 vhost for the %s domain', $mdata->{'DOMAIN_NAME'} ));

    if ( $phpServer->{'config'}->{'PHP_SAPI'} eq 'apache2handler' ) {
        if ( $mdata->{'FORWARD'} eq 'no' && $mdata->{'PHP_SUPPORT'} eq 'yes' ) {
            @{ $sdata }{qw/ EMAIL_DOMAIN PHP_PEAR_DIR TMPDIR /} = (
                $mdata->{'PHP_CONFIG_LEVEL_DOMAIN'},
                $phpServer->{'PHP_PEAR_DIR'},
                $mdata->{'HOME_DIR'} . '/phptmp'
            );

            processBlocByRef( $cfgTpl, '# SECTION custom document root BEGIN.', '# SECTION custom document root ENDING.', <<'EOF', TRUE );
        AllowOverride All
        DirectoryIndex index.php
        php_admin_value open_basedir "{HOME_DIR}/:{PHP_PEAR_DIR}/:dev/random:/dev/urandom"
        php_admin_value upload_tmp_dir "{TMPDIR}"
        php_admin_value session.save_path "{TMPDIR}"
        php_admin_value soap.wsdl_cache_dir "{TMPDIR}"
        php_admin_value sendmail_path "/usr/sbin/sendmail -t -i -f webmaster\@{EMAIL_DOMAIN}"
        php_admin_value max_execution_time {MAX_EXECUTION_TIME}
        php_admin_value max_input_time {MAX_INPUT_TIME}
        php_admin_value memory_limit "{MEMORY_LIMIT}M"
        php_flag display_errors {DISPLAY_ERRORS}
        php_admin_value post_max_size "{POST_MAX_SIZE}M"
        php_admin_value upload_max_filesize "{UPLOAD_MAX_FILESIZE}M"
        php_admin_flag allow_url_fopen {ALLOW_URL_FOPEN}
EOF
        } else {
            processBlocByRef( $cfgTpl, '# SECTION custom document root BEGIN.', '# SECTION custom document root ENDING.', <<'EOF', TRUE );
      AllowOverride AuthConfig Indexes Limit Options=Indexes,MultiViews \
        Fileinfo=RewriteEngine,RewriteOptions,RewriteBase,RewriteCond,RewriteRule Nonfatal=Override
EOF
            processBlocByRef( $cfgTpl, '# SECTION addons BEGIN.', '# SECTION addons ENDING.', <<"EOF", TRUE );
    RemoveHandler .php .php3 .php4 .php5 .php7 .pht .phtml
    php_admin_flag engine off
EOF
        }
    } elsif ( $phpServer->{'config'}->{'PHP_SAPI'} eq 'cgi' ) {
        if ( $mdata->{'FORWARD'} eq 'no' && $mdata->{'PHP_SUPPORT'} eq 'yes' ) {
            @{ $sdata }{qw/ PHP_FCGI_STARTER_DIR PHP_FCGID_BUSY_TIMEOUT PHP_FCGID_MIN_PROCESSES_PER_CLASS PHP_FCGID_MAX_PROCESS_PER_CLASS /} = (
                $phpServer->{'config'}->{'PHP_FCGI_STARTER_DIR'},
                $mdata->{'MAX_EXECUTION_TIME'}+10,
                $phpServer->{'config'}->{'PHP_FCGID_MIN_PROCESSES_PER_CLASS'} || 0,
                $phpServer->{'config'}->{'PHP_FCGID_MAX_PROCESS_PER_CLASS'} || 6
            );

            processBlocByRef( $cfgTpl, '# SECTION custom document root BEGIN.', '# SECTION custom document root ENDING.', <<'EOF', TRUE );
        AllowOverride All
        DirectoryIndex index.php
        Options +ExecCGI
        FCGIWrapper {PHP_FCGI_STARTER_DIR}/{PHP_CONFIG_LEVEL_DOMAIN}/php-fcgi-starter
EOF
            processBlocByRef( $cfgTpl, '# SECTION addons BEGIN.', '# SECTION addons ENDING.', <<'EOF', TRUE );
    FcgidBusyTimeout {PHP_FCGID_BUSY_TIMEOUT}
    FcgidMinProcessesPerClass {PHP_FCGID_MIN_PROCESSES_PER_CLASS}
    FcgidMaxProcessesPerClass {PHP_FCGID_MAX_PROCESS_PER_CLASS}
EOF
        } else {
            processBlocByRef( $cfgTpl, '# SECTION custom document root BEGIN.', '# SECTION custom document root ENDING.', <<'EOF', TRUE );
        AllowOverride AuthConfig Indexes Limit Options=Indexes,MultiViews \
          Fileinfo=RewriteEngine,RewriteOptions,RewriteBase,RewriteCond,RewriteRule Nonfatal=Override
EOF
            processBlocByRef( $cfgTpl, '# SECTION addons BEGIN.', '# SECTION addons ENDING.', <<'EOF', TRUE );
    RemoveHandler .php .php3 .php4 .php5 .php7 .pht .phtml
EOF
        }
    } elsif ( $phpServer->{'config'}->{'PHP_SAPI'} eq 'fpm' ) {
        if ( $mdata->{'FORWARD'} eq 'no' && $mdata->{'PHP_SUPPORT'} eq 'yes' ) {
            @{ $sdata }{
                qw/ PHP_FPM_RUN_DIR PHP_VERSION PROXY_FCGI_PATH PROXY_FCGI_URL PROXY_FCGI_RETRY PROXY_FCGI_CONNECTION_TIMEOUT PROXY_FCGI_TIMEOUT /
            } = (
                $phpServer->{'PHP_FPM_RUN_DIR'},
                $phpServer->{'config'}->{'PHP_VERSION'},
                ( $phpServer->{'config'}->{'PHP_FPM_LISTEN_MODE'} eq 'uds'
                    ? "unix:{PHP_FPM_RUN_DIR}/php{PHP_VERSION}-fpm-{PHP_CONFIG_LEVEL_DOMAIN}.sock|" : ''
                ),
                ( 'fcgi://' . ( $phpServer->{'config'}->{'PHP_FPM_LISTEN_MODE'} eq 'uds'
                    ? '{PHP_CONFIG_LEVEL_DOMAIN}'
                    : '127.0.0.1:' . ( $phpServer->{'config'}->{'PHP_FPM_LISTEN_PORT_START'}+$mdata->{'PHP_FPM_LISTEN_PORT'} )
                ) ),
                $phpServer->{'config'}->{'PROXY_FCGI_RETRY'},
                $phpServer->{'config'}->{'PROXY_FCGI_CONNECTION_TIMEOUT'},
                $mdata->{'MAX_EXECUTION_TIME'}+$phpServer->{'config'}->{'PROXY_FCGI_TIMEOUT'}
            );

            processBlocByRef( $cfgTpl, '# SECTION custom document root BEGIN.', '# SECTION custom document root ENDING.', <<'EOF', TRUE );
        AllowOverride All
        DirectoryIndex index.php
        <If "%{REQUEST_FILENAME} =~ /\.ph(?:p[3457]?|t|tml)\$/ && -f %{REQUEST_FILENAME}">
            SetEnvIfNoCase ^Authorization\$ "(.+)" HTTP_AUTHORIZATION=\$1
            SetHandler proxy:{PROXY_FCGI_URL}
        </If>
EOF
            processBlocByRef( $cfgTpl, '# SECTION addons BEGIN.', '# SECTION addons ENDING.', <<'EOF', TRUE );
    <Proxy "{PROXY_FCGI_PATH}{PROXY_FCGI_URL}" retry={PROXY_FCGI_RETRY}>
        ProxySet connectiontimeout={PROXY_FCGI_CONNECTION_TIMEOUT} timeout={PROXY_FCGI_TIMEOUT}
    </Proxy>
EOF
        } else {
            processBlocByRef( $cfgTpl, '# SECTION custom document root BEGIN.', '# SECTION custom document root ENDING.', <<'EOF', TRUE );
        AllowOverride AuthConfig Indexes Limit Options=Indexes,MultiViews \
          Fileinfo=RewriteEngine,RewriteOptions,RewriteBase,RewriteCond,RewriteRule Nonfatal=Override
EOF
            processBlocByRef( $cfgTpl, '# SECTION addons BEGIN.', '# SECTION addons ENDING.', <<'EOF', TRUE );
    RemoveHandler .php .php3 .php4 .php5 .php7 .pht .phtml
EOF
        }
    } else {
        die( 'Unknown PHP SAPI' );
    }

    $phpServer->{'eventManager'}->trigger(
        'afterPhpApacheBuildConfFile', $phpServer, $cfgTpl, $filename, $trgFile, $mdata, $sdata, $sconfig, $params
    );
}

=item beforeNginxBuildConfFile( $phpServer, \$cfgTpl, $filename, \$trgFile, \%mdata, \%sdata, \%sconfig, $params )

 Event listener that inject PHP configuration in Nginx vhosts

 Param iMSCP::Servers::Php $phpServer instance
 Param scalar \$cfgTpl Reference to Nginx template content
 Param string $filename Nginx template name
 Param scalar \$trgFile Target file path
 Param hashref \%mdata Data as provided by the Alias|Domain|Subdomain|SubAlias modules
 Param hashref \%sconfig Nginx server data
 Param hashref \%params OPTIONAL parameters:
  - umask   : UMASK(2) for a new file. For instance if the given umask is 0027, mode will be: 0666 & ~0027 = 0640 (in octal)
  - user    : File owner (default: EUID for a new file, no change for existent file)
  - group   : File group (default: EGID for a new file, no change for existent file)
  - mode    : File mode (default: 0666 & ~(UMASK(2) || 0) for a new file, no change for existent file )
  - cached  : Whether or not loaded file must be cached in memory
  - srcname : Make it possible to override default source filename passed into event listeners. Most used when $srcFile is a TMPFILE(3) file
 Return void, die on failure

=cut

sub beforeNginxBuildConfFile
{
    my ( $phpServer, $cfgTpl, $filename, $trgFile, $mdata, $sdata, $sconfig, $params ) = @_;

    return unless $filename eq 'domain.tpl' && grep ( $_ eq $sdata->{'VHOST_TYPE'}, ( 'domain', 'domain_ssl' ) );

    $phpServer->{'eventManager'}->trigger(
        'beforePhpNginxBuildConfFile', $phpServer, $cfgTpl, $filename, $trgFile, $mdata, $sdata, $sconfig, $params
    );

    debug( sprintf( 'Injecting PHP configuration in Nginx vhost for the %s domain', $mdata->{'DOMAIN_NAME'} ));

    # TODO

    $phpServer->{'eventManager'}->trigger(
        'afterPhpNginxBuildConfFile', $phpServer, $cfgTpl, $filename, $trgFile, $mdata, $sdata, $sconfig, $params
    );
}

=item afterHttpdAddFiles( \%moduleData )

 Event listener that create PHP (phptmp) directory in customer Web folders

 FIXME: Should we remove the directory when PHP is being disabled?

 Param hashref \%moduleData Data as provided by te Alias|Domain|Subdomain|SubAlias modules
 Return void, die on failure

=cut

sub afterHttpdAddFiles
{
    my ( $self, $moduleData ) = @_;

    return unless $moduleData->{'DOMAIN_TYPE'} eq 'dmn';

    iMSCP::Dir->new( dirname => "$moduleData->{'WEB_DIR'}/phptmp" )->make( {
        user  => $moduleData->{'USER'},
        group => $moduleData->{'GROUP'},
        mode  => 0750
    } );
}

=back

=head1 AUTHOR

 Laurent Declercq <l.declercq@nuxwin.com>

=cut

1;
__END__
