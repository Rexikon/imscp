=head1 NAME

 iMSCP::Modules::SSLcertificate - Module for processing of SSL certificate entities

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

package iMSCP::Modules::SSLcertificate;

use strict;
use warnings;
use File::Temp;
use iMSCP::Dir;
use iMSCP::File;
use iMSCP::OpenSSL;
use parent 'iMSCP::Modules::Abstract';

=head1 DESCRIPTION

 Module for processing of SSL certificate entities.

=head1 PUBLIC METHODS

=over 4

=item getEntityType( )

 See iMSCP::Modules::Abstract::getEntityType()

=cut

sub getEntityType
{
    my ( $self ) = @_;

    'SSLcertificate';
}

=item handleEntity( $entityId )

 See iMSCP::Modules::Abstract::handleEntity()

=cut

sub handleEntity
{
    my ( $self, $entityId ) = @_;

    $self->_loadEntityData( $entityId );

    # Handle case of orphaned SSL certificate which has been removed
    return unless $self->{'_data'}->{'domain_name'};
    return $self->_add() if $self->{'_data'}->{'status'} =~ /^to(?:add|change)$/;
    return $self->_delete() if $self->{'_data'}->{'status'} eq 'todelete';
}

=back

=head1 PRIVATES METHODS

=over 4

=item _loadEntityData( $entityId )

 See iMSCP::Modules::Abstract::_loadEntityData()

=cut

sub _loadEntityData
{
    my ( $self, $entityId ) = @_;

    $self->{'_data'} = $self->{'_dbh'}->selectrow_hashref( 'SELECT * FROM ssl_certs WHERE cert_id = ?', undef, $entityId );
    $self->{'_data'} or die( sprintf( 'Data not found for SSL certificate (ID %d)', $entityId ));
    
    my $row = $self->{'_dbh'}->selectrow_hashref( 'SELECT domain_name FROM domain WHERE domain_id = ?', undef, $self->{'_data'}->{'domain_id'} );
    unless ( $row ) {
        # Delete orphaned SSL certificate
        $self->{'_dbh'}->do( 'DELETE FROM ssl_certs WHERE cert_id = ?', undef, $entityId );
        return;
    }

    @{ $self->{'_data'} }{qw/ domain_name cert_dir /} = ( $row->{'domain_name'}, "$::imscpConfig{'FRONTEND_ROOT_DIR'}/data/certs" );
}

=item _add()

 See iMSCP::Modules::Abstract::_add()

=cut

sub _add
{
    my ( $self ) = @_;

    eval {
        iMSCP::File->new( filename => "$self->{'_data'}->{'cert_dir'}/$self->{'_data'}->{'domain_name'}.pem" )->remove();

        my $privateKeyContainer = File::Temp->new();
        print $privateKeyContainer $self->{'_data'}->{'private_key'};
        $privateKeyContainer->close();

        my $certificateContainer = File::Temp->new();
        print $certificateContainer $self->{'_data'}->{'certificate'};
        $certificateContainer->close();

        my $caBundleContainer;
        if ( length $self->{'_data'}->{'ca_bundle'} ) {
            $caBundleContainer = File::Temp->new();
            print $caBundleContainer $self->{'_data'}->{'ca_bundle'};
            $caBundleContainer->close();
        }

        iMSCP::OpenSSL->new( {
            certificate_chains_storage_dir => $self->{'_data'}->{'cert_dir'},
            certificate_chain_name         => $self->{'_data'}->{'domain_name'},
            private_key_container_path     => $privateKeyContainer->filename(),
            certificate_container_path     => $certificateContainer->filename(),
            ca_bundle_container_path       => defined $caBundleContainer ? $caBundleContainer->filename() : ''
        } )->validateCertificateChain()->createCertificateChain();
    };

    $self->{'_dbh'}->do(
        'UPDATE ssl_certs SET status = ? WHERE cert_id = ?', undef, ( $@ ? $@ =~ s/iMSCP::OpenSSL::validateCertificate:\s+//r : 'ok' ),
        $self->{'_data'}->{'cert_id'}
    );
}

=item _delete()

 See iMSCP::Modules::Abstract::_delete()

=cut

sub _delete
{
    my ( $self ) = @_;

    eval { iMSCP::File->new( filename => "$self->{'_data'}->{'cert_dir'}/$self->{'_data'}->{'domain_name'}.pem" )->remove(); };
    if ( $@ ) {
        $self->{'_dbh'}->do( 'UPDATE ssl_certs SET status = ? WHERE cert_id = ?', undef, $@, $self->{'_data'}->{'cert_id'} );
        return;
    }

    $self->{'_dbh'}->do( 'DELETE FROM ssl_certs WHERE cert_id = ?', undef, $self->{'_data'}->{'cert_id'} );
}

=back

=head1 AUTHOR

 Laurent Declercq <l.declercq@nuxwin.com>

=cut

1;
__END__
