#! /usr/bin/perl

use strict;

my $arg = shift;
my $s_proj = &findProject;

while ($arg ne ""){
	$arg = "${s_proj}_$arg.conf" if length($arg) == 2;
	if (-f $arg){
		oneFile($arg);
	} else {
		print "not found: $arg\n";
	}
	$arg = shift;
}

exit 0;

sub oneFile{
	my $fn = shift;
	open(my $IN, "<", $fn) || die "$fn: $!";
	print "$fn ...\n";
	my $head = 1;
	my @header;
	while(<$IN>){
		push(@header, $_);
		last unless /\S/;
	}
	my $part;
	my %content;
	while(<$IN>){
		next unless /\S/;
		chomp;
		#print $_ if /%=/;
		s/^([\w.-]+)%=/$1=/;
		$part .= "\n" if $part ne "";
		$part .= $_;
		if (/^([-\w.]+)=/){
			$content{$1} = $part;
			$part = "";
		}
	}
	close $IN;

	my $fn2 = $fn;
	open (my $OUT, ">", $fn2) || die "$fn2: $!";
	print $OUT @header;
	print $OUT "\n";
	my $lastGroup = "\t";
	foreach my $key (sort keys %content){
		if ($key =~ /([^.])*\./ && $1 ne $lastGroup){
			$lastGroup = $1;
			print $OUT "\n";
		}
		print $OUT $content{$key}, "\n";
	}
	close $OUT;
}

sub findProject{
	opendir (my $DIR, ".") || die ".: $!";

	my @files = readdir $DIR;
	close $DIR;
	my $rc;
	foreach (@files){
		if (/(.*)_en.conf/){
			$rc = $1;
			last;
		}
	}
	die "unknown project: *_en.conf not found" unless $rc;
	return $rc;
}
