#! /usr/bin/perl

use strict;

my $lang = shift;
my $s_proj = &findProject;
my $missedHtml = "/tmp/$s_proj.missed_$lang.html";
my $comparisonHtml = "/tmp/$s_proj.comparison_$lang.html";
my $txt = "/tmp/$s_proj.missed_$lang.txt";

$lang = 'ro' unless $lang;

my %en = oneLang("en");
my %other = oneLang($lang);

open(my $MISSED, ">", $missedHtml) || die "$missedHtml: $!";
open(my $COMPARISON, ">", $comparisonHtml) || die "$comparisonHtml: $!";
open(my $TXT, ">", $txt) || die "$txt: $!";

print $MISSED <<EOS;
<xhtml>
<body>
<h1>Not available in $lang:</h1>
<table border="1">
<tr><td><b>Key</b>:</td><td><b>English:</b></td>
</tr>
EOS

my $lang2 = $lang;
$lang2 =~ tr/a-z/A-Z/;
print $COMPARISON <<EOS;
<xhtml>
<body>
<h1>Comparison of EN and $lang2:</h1>
<table border="1">
<tr><td><b>Key</b>:</td><td><b>EN / $lang2:</b></td>
</tr>
EOS

my ($text, $text2, $sep);

for my $key (sort keys %en){
		$text = escText($en{$key});
		$text2 = escText($other{$key});
		if ($text2 eq ""){
			print $MISSED "<tr><td>$key</td><td>$text</td>\n</tr>\n";
			print $TXT "# $text\n$key=\n";
		} else {
			if (length($text) + length($text2) < 2*60){
				$sep = "";
			} else {
				$sep = "<br/>";
			}
			print $COMPARISON "<tr><td>$key</td>\n"
				. "<td>$text<br/>$sep$text2</td>\n</tr>\n";
		}
}

print $MISSED <<EOS;
</table>
</body>
EOS
print $COMPARISON <<EOS;
</table>
</body>
EOS

print "results in $missedHtml, $comparisonHtml and $txt\n";
close $MISSED;
close $TXT;
exit 0;
sub escText{
	my $rc = shift;
	$rc =~ s/&/&amp;/g;
	$rc =~ s/>/&gt;/g;
	$rc =~ s/</&lt;/g;
	return $rc;
}
sub oneLang{
	my $lang = shift;

	my %rc;
	my $fn = "${s_proj}_$lang.conf";
	open (INP, "<", $fn) || die "$fn: $!";
	while(<INP>){
		if (/^([a-zA-Z._-]+)%?=(.+)$/){
			$rc{$1} = $2;
		}
	}
	close INP;
	return %rc;
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


