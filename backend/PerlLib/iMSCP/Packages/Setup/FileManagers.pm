=head1 NAME

 iMSCP::Packages::Setup::FileManagers - i-MSCP FileManager package

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

package iMSCP::Packages::Setup::FileManagers;

use strict;
use warnings;
use File::Basename qw/ dirname /;
use iMSCP::Boolean;
use iMSCP::Debug qw/ debug /;
use iMSCP::Dir;
use parent 'iMSCP::Packages::AbstractCollection';

our $VERSION = '2.0.0';

=head1 DESCRIPTION

 i-MSCP FileManager package.

 Handles FileManager packages.

=head1 PUBLIC METHODS

=over 4

=item getPackageName( )

 See iMSCP::Packages::Abstract::getPackageName()

=cut

sub getPackageName
{
    my ( $self ) = @_;

    'FileManagers';
}

=item getPackageHumanName( )

 See iMSCP::Packages::Abstract::getPackageHumanName()

=cut

sub getPackageHumanName
{
    my ( $self ) = @_;

    sprintf( 'i-MSCP FileManager packages collection (%s)', $self->getPackageVersion());
}

=item getPackageVersion( )

 See iMSCP::Packages::Abstract::getPackageVersion()

=cut

sub getPackageVersion
{
    my ( $self ) = @_;

    $self->getPackageImplVersion();
}

=item getSelectedPackages()

 See iMSCP::Packages::AbstractCollection::getSelectedPackages()

=cut

sub getSelectedPackages
{
    my ( $self ) = @_;

    @{ $self->{'_package_instances'} } = sort { $b->getPackagePriority() <=> $a->getPackagePriority() } map {
        my $package = "iMSCP::Packages::Setup::@{ [ $self->getPackageName() ] }::${_}";
        eval "require $package; 1" or die( $@ );
        $package->getInstance();
    } @{ $self->{'SELECTED_PACKAGES'} } unless $self->{'_package_instances'};
    @{ $self->{'_package_instances'} };
}

=item getUnselectedPackages()

 See iMSCP::Packages::AbstractCollection::getUnselectedPackages()

=cut

sub getUnselectedPackages
{
    my ( $self ) = @_;

    unless ( $self->{'_unselected_package_instances'} ) {
        my @unselectedPackages;
        for my $package ( $self->{'AVAILABLE_PACKAGES'} ) {
            next if grep ($package eq $_, @{ $self->{'SELECTED_PACKAGES'} });
            push @unselectedPackages, $package;
        }

        @{ $self->{'_unselected_package_instances'} } = sort { $b->getPackagePriority() <=> $a->getPackagePriority() } map {
            my $package = "iMSCP::Packages::Setup::@{ [ $self->getPackageName() ] }::${_}";
            eval "require $package; 1" or die( $@ );
            $package->getInstance();
        } @unselectedPackages;
    }

    @{ $self->{'_unselected_package_instances'} };
}

=back

=head1 PRIVATE METHODS

=over 4

=item _loadAvailablePackages()

 See iMSCP::Packages::AbstractCollection::_loadAvailablePackages()

=cut

sub _loadAvailablePackages
{
    my ( $self ) = @_;

    s/\.pm$// for @{ $self->{'AVAILABLE_PACKAGES'} } = iMSCP::Dir->new( dirname => dirname( __FILE__ ) . '/' . $self->getPackageName())->getFiles(
        qr/Pydio/, TRUE # Pydio package temporarily disabled due to PHP version constraint that is not met
    );
}

=back

=head1 AUTHOR

 Laurent Declercq <l.declercq@nuxwin.com>

=cut

1;
__END__
