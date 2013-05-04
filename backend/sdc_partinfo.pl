#! /usr/bin/perl
use strict;
my $gdisk = shift;
# If there is a GPT and a MBR gdisk asks which partition table should be used.
# 1: GPT 2: MBR 3: clean GPT
$gdisk = "echo 1 | gdisk -l DISK|" unless $gdisk;
my $blkid = shift;
$blkid = "/sbin/blkid -c /dev/null|" unless $blkid;
my %disks;
my $verbose = 0;
my $gv_mount_base = "/tmp/partinfo-mount";
my $gv_log = "/tmp/partinfo_err.log";
my $gv_mount_no = 0;
my %lvm;
my %months = ( 
	"Jan" => "01",
	"Feb" => "02",
	"Mar" => "03",
	"Apr" => "04",
	"May" => "05",
	"Jun" => "06",
	"Jul" => "07",
	"Aug" => "08",
	"Sep" => "09",
	"Oct" => "10",
	"Nov" => "11",
	"Dec" => "12"
);
my @diskDevs;
my %devs;
my %blkids;
my (%sorted, $key, $dev);

system ("./automount-control.sh disabled");
&main();
system ("./automount-control.sh enabled");

exit 0;

sub main{
	if (! -d $gv_mount_base){
		mkdir $gv_mount_base;
		print STDERR $gv_mount_base, " created\n" if $verbose;
	}
	@diskDevs = &getDiskDev;
	#&getFdiskInfo;
	foreach(@diskDevs){
		&getGdiskInfo($_, $gdisk);
	}
	# get the info from gdisk:
	
	# get the info from blkid
	
	%blkids = &getBlockId;
	&mergeDevs;
	
	foreach $dev (keys %blkids){
		if ($dev =~ /(\D+)(\d+)/){
			$key = $1 . sprintf ("%03d", $2);
		} else {
			$key = $dev;
		} 
		$sorted{$key} = $dev;
	}
	foreach $key (sort keys %sorted){
		$dev = $sorted{$key};
		print $dev, "\t", $blkids{$dev}, "\n";
	}
	foreach $key (sort keys %disks){
		print $key, "\t", $disks{$key}, "\n";
	}
	&physicalView;
	&logicalView;
	&vgInfo;
	&UnmountAll;
}
sub UnmountAll{
	opendir(DIR, $gv_mount_base);
	my $dir;
	my $run = 0;
	while ($run < 2){
		$run++;
		my $errors = 0;
		while ($dir = readdir(DIR)){
			next if $dir =~ /\.{1,2}/;
			my $full = "$gv_mount_base/$dir";
			system ("echo $full >>$gv_log 2>&1");
			system ("umount $full >>$gv_log 2>&1");
			rmdir $full;
			if (-d $full){
				print 
				system ("lsof +d $full >>$gv_log 2>&1");
				$errors++;
			}
		}
		last if $errors == 0;
		sleep 1;
	}
}	
sub detective{
	my $dev = shift;
	my $fs = shift;
	my $info = "";
	my $dirMount = &getMountPoint($dev);
	if ($dirMount eq ""){
		$dirMount = sprintf("$gv_mount_base/m%03d", ++$gv_mount_no);
		mkdir $dirMount;
		system ("mount -o ro $dev $dirMount >>$gv_log 2>&1");
	}
	if ($fs eq "ntfs" || $fs =~ /^vfat|fat\d/){
		if (-d "$dirMount/windows/system32"){
			$info .= "\tos:windows";
		}
	}
	if (-d "$dirMount/etc"){
		$info .= &firstLineOf("$dirMount/etc/debian_version", "distro");
		$info .= &firstLineOf("$dirMount/etc/aptosid-version", "subdistro");
		$info .= &firstLineOf("$dirMount/etc/sidux-version", "subdistro");
		$info .= &firstLineOf("$dirMount/etc/siduction-version", "subdistro");
		$info .= "\tos:unix" if $info eq "" && -d "$dirMount/etc/passwd";		
	}
	if ($dirMount =~ /^$gv_mount_base/){
		system ("umount $dirMount >>$gv_log 2>&1");
		rmdir $dirMount;
	}
	
	if ($fs =~ /fs:ext\d/){
		open(TUNE, "tune2fs -l $dev|");
		my $date;
		while(<TUNE>){
			#Filesystem created:       Sun May  1 07:53:47 2011
			if (/Filesystem created:\s+\w+\s+(\w+)\s+(\d+)\s+\S+\s+(\d+)/){
				my $month = $months{$1};
				$info .= "\tcreated:$3.$month.$2";
			}
			#Last write time:          Thu May 12 00:33:04 2011
			if (/write time:\s+\w+\s+(\w+)\s+(\d+)\s+\S+\s+(\d+)/){
				my $month = $months{$1};
				$info .= "\tmodified:$3.$month.$2";
			}
		}
		close TUNE;
	}
	return $info;
}
my %s_mounts;
my $s_mounts;
sub getMountPoint{
	my $dev = shift;
	if ($s_mounts eq ""){
		open(INP, "mount|");
		while(<INP>){
			if (/^(\S+)\s+on\s+(\S+)/){
				$s_mounts{$1} = $2;
			}
		}
		close INP;
		$s_mounts = 1;
	}
	return $s_mounts{$dev};
}
sub firstLineOf{
	my $file = shift;
	my $prefix = shift;
	my $rc = "";
	if (-f $file){
		open(INP, $file);
		$rc = "\t$prefix:" . <INP>;
		chomp $rc;
		close INP;
	}
	return $rc;
}
sub getDiskDev{
	opendir(DIR, "/sys/block");
	my @files = readdir(DIR);
	my @rc;
	closedir DIR;
	foreach (@files){
		# find all names without a digit:
		push @rc, $_ unless /\d/ && ! /mmcblk\d/ || /^\.{1,2}$/;
	}
	return @rc;
}

sub getFdiskInfo{
	my $fdisk = "fdisk -l |";
	open(CMD, $fdisk) || die "$fdisk failed: $!";
	my ($dev, $size, $ptype, $info);
	while(<CMD>){
		s/\*/ /;
		if (/^(\S+)\s+\d+\s+\d+\s+(\d+)[+]?\s+([0-9a-fA-F]{1,2})\s+(.*)/){
			$dev = $1;
			$size = $2;
			$ptype = $3;
			$info = $4;
			# forget extended:
			if ($ptype != 5){
				$devs{$dev} = "size:$size\tptype:$ptype\tpinfo:$info";
			}
		} elsif (/^Disk\s+([^:]+):.*\s(\d+)\s+bytes/){
			my ($dev, $bytes) = ($1, $2);
			my $mb = length($bytes) <= 6 ? 1 : substr($bytes, 0, length($bytes) - 6);
			$disks{$1} = $mb;
		}
	}
	close CMD;
}

sub getGdiskInfo{
	my $disk = shift;
	my $cmd = shift;
	$cmd =~ s!DISK!/dev/$disk! if $cmd =~ /\|/; 
	open(CMD, $cmd) || die "$gdisk failed: $!";
	my $sector = 512;
	my $sectors = 0;
	while(<CMD>){
		s/\*/ /;
#   5        96392048        98799749   1.1 GiB     8200  Linux swap
#   6        98801798       176714999   37.2 GiB    8300  Linux filesystem
		if (/^\s+(\d+)\s+(\d+)\s+(\d+)\s+\S+\s+\S+B\s+([0-9A-Fa-f]+)\s+(.*)/){
			my ($partno, $min, $max, $ptype, $info) = ($1, $2, $3, $4, $5);
			my $dev = "/dev/$disk$partno";
			my $size = int(($max - $min + 1) * 1.0 * $sector / 1024);
			$devs{$dev} = "size:$size\tptype:$ptype\tpinfo:$info";
			if ($ptype eq '8E00'){
				$lvm{$dev} = $size;
			}
		} elsif (/sector size:\s+(\d+)/){
			$sector = $1;
#Disk /dev/sda: 3907029168 sectors, 1.8 TiB
		} elsif (/^Disk\s+\S+:\s+(\d+)/){
			$sectors = $1;
		}
	}
	$disks{$disk} = int($sectors * 1.0 * $sector / 1024); 
	close CMD;
}
sub getBlockId{
	my ($label, $uuid, $fs, $info2);
	my ($dev, $info);
	my %blkids;
	open(CMD, $blkid) || die "$blkid failed: $!";
	while(<CMD>){
		if (/^(\S+):/){
			my $dev = $1;
			my ($info, $label, $uuid, $fs, $size, $info);
			if (/LABEL="([^"]+)"/){
				$label = "\tlabel:$1";
			}
			if (/TYPE="([^"]+)"/){
				$fs = "\tfs:$1";
			}
			if (/UUID="([^"]+)"/){
				$uuid = "\tuuid:$1";
			}
			if ($devs{$dev} ne ""){
				$info=$devs{$info}
			}
			if (/mapper/){
				$size = getSizeOfLvmPartition($dev);
				$size = $size == 0 ? "" : "\tsize:$size";
			}
			$info .= &detective($dev, $fs);
			$blkids{$dev} = "$label$fs$uuid$info$size";
		}
	}
	close CMD;
	return %blkids;
}

sub mergeDevs{
	# merge the two fields:
	foreach $dev (keys %devs){
		my $info = $blkids{$dev};
		my $val = $devs{$dev};
		if ($info eq ""){
			$blkids{$dev} = $val;
		} else {
			my $size = "\t$1" if $val =~ /(size:\d+)/;
			my $ptype = "\t$1" if $val =~ /(id:\w+)/;
			my $info2 = "\t$1" if $val =~ /(pinfo:[^\t]+)/;
			$blkids{$dev} = "$info$size$ptype$info2"; 
		}
	}
}

sub getSizeOfLvmPartition{
	my $dev = shift;
	my ($sectors, $size);
	open (INP, "gdisk -l $dev|") || die "gdisk: $!";
	while(<INP>){
		if (/(\d+)\s+sectors/){
			$sectors = $1;
		} elsif (/sector\s+size:\s+(\d+)/){
			$size = int($1 * 1.0 * $sectors / 1024);
			last;
		}
	}
	close INP;
	return $size;
}
sub prettySize{
	my $kbyte = shift;
	my $rc;
	if ($kbyte >= 9*1024*1024){
		$rc = int($kbyte / 1024 / 1024) . ' GiB';
	} elsif ($kbyte >= 1024*1024){
		$rc = sprintf("%.2f", $kbyte / 1024.0 / 1024) . ' GiB';
	} elsif ($kbyte >= 1024){
		$rc = int($kbyte / 1024) . ' MiB';
	} else{
		$rc = sprintf("%.2f", $kbyte / 1024.0) . ' MiB';
	}
	return $rc;
}
	
sub physicalView{
	if (open(INP, "pvdisplay|")){
		my ($pvName, $vgName, $size, %devs, %unassigned, %assigned);
		while(<INP>){
			chomp;
			if (/PV Name\s+(\S+)/){
				$pvName = $1;
			} elsif (/VG Name\s+(\S+)/){
				$vgName = $1;
			} elsif (/VG Name\s*$/){
				$vgName = '?';
			} elsif (m!PV Size\s+(\S.*)!){
				$size = $1;
				$size =~ s!\s*/.*$!!;
			} elsif (/---/){
				if ($pvName ne ""){
					if ($vgName eq '?'){
						$unassigned{$pvName} = "|$pvName|$size";
					} else {
						$devs{$vgName} .= "\t|$pvName|$size";
						$assigned{$pvName} = 1;
					}
					$vgName = "";
				}
			}
		}
		if ($vgName eq '?'){
			$unassigned{$pvName} = "|$pvName|$size";
		} elsif ($pvName ne ""){
			$devs{$vgName} .= "\t|$pvName|$size";
			$assigned{$pvName} = 1;
		}
		my ($key, $out);
		foreach $key (sort keys %devs){
			$out .= "\f\t$key" . $devs{$key};
		}
		foreach $key (%assigned){
			if ($lvm{$key}){ 
				delete($lvm{$key});
			}
		}
		print "PhLVM:$out\n";
		$out = '';
		for $key (sort keys %unassigned){
			delete($lvm{$key}) if $unassigned{$key};
			$out .= "\t" . $unassigned{$key}; 
		} 
		print "FreeLVM:", $out, "\n";
		$out = '';
		for $key (sort keys %lvm){
			$out .= "\t|$key|" . &prettySize($lvm{$key}); 
		} 
		print "MarkedLVM:", $out, "\n";
		close INP;
	}
}


sub logicalView{
	if (open(INP, "lvdisplay|")){
		my ($lvName, $vgName, $size, $access, %devs, %snaps, $parent);
		while(<INP>){
			chomp;
			if (/LV Name\s+(\S+)/){
				$lvName = $1;
				$lvName =~ s!/dev/[^/]+/!!;
			} elsif (/VG Name\s+(\S+)/){
				$vgName = $1;
			} elsif (m!LV Size\s+(\S.*)!){
				$size = $1;
			} elsif (m!LV snapshot status.*\sfor\s+(\S+)!){
				$parent = $1;
				$parent =~ s!/dev/[^/]+/!!;
			} elsif (m!LV Write Access\s+(\S.*)!){
				$access = $1;
			} elsif (/--- Logical volume/){
				if ($lvName ne ""){
					if ($parent){
						$snaps{$vgName} .= "\t|$lvName|$size|$access|$parent";
						$parent = '';
					} else {
						$devs{$vgName} .= "\t|$lvName|$size|$access";
					}
				}
			}
		}
		if ($lvName ne ""){
			if ($parent){
				$snaps{$vgName} .= "\t|$lvName|$size|$access|$parent";
			} else {
				$devs{$vgName} .= "\t|$lvName|$size|$access";
			}
		}
		close INP;
		my ($key, $out);
		foreach $key (sort keys %devs){
			$out .= "\f\t$key" . $devs{$key}; 
		}
		if ($out ne ""){
			print "LogLVM:$out\n";
		}
		$out = '';
		foreach $key (sort keys %snaps){
			$out .= "\f\t$key" . $snaps{$key}; 
		}
		if ($out ne ""){
			print "SnapLVM:$out\n";
		}
	}
}
sub vgInfo {
	if (open(INP, "vgdisplay|")){
		my ($vgName, $size, $access, $status, $free, $alloc, %vgs, $peSize);
		while(<INP>){
			chomp;
			if (/VG Name\s+(\S+)/){
				$vgName = $1;
			} elsif (m!VG Size\s+(\S.*)!){
				$size = $1;
			} elsif (m!VG Access\s+(\S.*)!){
				$access = $1;
			} elsif (m!VG Status\s+(\S.*)!){
				$status = $1;
			} elsif (m!Alloc PE / Size.*/\s+(\S.*)!){
				$alloc = $1;
			} elsif (m!Free  PE / Size.*/\s+(\S.*)!){
				$free = $1;
			} elsif (m!PE Size\s+(\S.*)!){
				$peSize = $1;
			} elsif (/--- Volume group/){
				if ($vgName ne ""){
					$vgs{$vgName} .= "|$size|$alloc|$free|$peSize|$status|$access";
				}
			}
		}
		if ($vgName ne ""){
			$vgs{$vgName} .= "|$size|$alloc|$free|$peSize|$status|$access";
		}
		my ($key, $out);
		foreach $key (sort keys %vgs){
			$out .= "\t|$key" . $vgs{$key}; 
		}
		if ($out ne ""){
			print "VgLVM:$out\n";
		}
		close INP;
	}

}