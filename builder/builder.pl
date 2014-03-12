#!/usr/bin/perl

use Database;
use XML::Simple;
use Data::Dumper;

Database::connect('DBI:mysql:waste', 'waste', 'RoskaKuskit');

#
# First re-creating the tables required.
#

my $tables = readXML('builder.xml', 'Table');
# Dropping.
for (sort { $tables->{$b}->{order} cmp $tables->{$a}->{order} } keys %$tables) {
	print "[DROP] ".$_."\n";
	$Database::DB->do("DROP TABLE IF EXISTS ".$_.";");
}
# Creating.
for (sort { $tables->{$a}->{order} cmp $tables->{$b}->{order} } keys %$tables) {
	print "[CREATE] ".$_."\n";
	$Database::DB->do($tables->{$_}->{content});
}

#
# Next executing additional statements.
#

my $data = readXML('data.xml', 'Statement');
for (sort { $data->{$a}->{order} cmp $data->{$b}->{order} } keys %$data) {
	print "[EXTRA] ".$_."\n";
	$Database::DB->do($data->{$_}->{content});
}


sub readXML {
	my ($file, $root) = @_;
	my $config = new XML::Simple->XMLin($file);
	my $hash = {};
	$hash->{$_} = $config->{$root}->{$_} foreach (keys %{$config->{$root}});
	return $hash;
}