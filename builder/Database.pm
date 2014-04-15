package Database;

#
# Included packages.
#

use DBI;
use Data::Dumper;
our $DB;

#
# Singleton
#

sub connect {
	my ($dns, $user, $pass) = @_;
	$DB = new Database($dns, $user, $pass);
}

#
# Constructor.
#

sub new {
	my ($class) = shift;
	my $self = { _DBI => DBI->connect($_[0], $_[1], $_[2], { RaiseError => 1 }) || die("Could not connect to database: $DBI::errstr") };
	bless $self, $class;
	return $self;
}

#
# Execute non-query.
#

sub do {
	my ($self, $statement, $params) = @_;
	return $params ? ($self->{_DBI}->do($statement, undef, @$params) || {'state' => 'FAIL', 'reason' => $DBI::errstr}) : ($self->{_DBI}->do($statement) || {'state' => 'FAIL', 'reason' => $DBI::errstr});
}

#
# Execute query.
#

sub query {
	my ($self, $query, $params, $key) = @_;
	$sth = $self->{_DBI}->prepare($query);
	$sth->execute(@$params);
	my $result;
	while (my $data = $sth->fetchrow_hashref) {
		push(@$result, $data);
	}
	return $result;
}

#
# Get last inserted record's id.
#

sub getLastInsertedId {
	return shift->{_DBI}{q{mysql_insertid}};
}

#
# Get database handle.
#

sub getHandle {
	return shift->{_DBI};
}

1;