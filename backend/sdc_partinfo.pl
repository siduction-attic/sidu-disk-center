#! /usr/bin/perl
# Gets the partition info.
# @param fnProgress name of the file containing progress info
# @param answer     the partition info 
# @param testRun	"": normal run otherwise: name of the test
#
use strict;
use sidu_basic;
use sidu_recorder;

my $s_answer = shift;
$s_answer = "/var/cache/sidu-base/partinfo.txt" unless $s_answer;

my $s_fnProgress = shift;
$s_fnProgress = "/tmp/partinfo.progress" unless $s_fnProgress;

my $s_testRun = shift;
# /dev/sda -> 123456 (KiByte)
my %s_disks;
my $verbose = 0;
my $gv_mount_base = "/tmp/partinfo-mount";
my $gv_log = "/tmp/partinfo_err.log";
my $gv_mount_no = 0;
my %s_hasLVMFlag;
my @s_output;
my @s_labels;
my %s_physicalDisks;

# minimal size of a partition in bytes:
my $s_minPartSize = 10*1024*1024;
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
my $s_gptDisks;
my @s_vg;
my @s_vgSize;
my @s_lv;
my @s_fdFree;
my @s_fdEmpty;
my %s_devs;
my %s_blkids;	
my $s_gapPart;
my $s_maxTasks = 10;
my $s_currTask = 0;
my $s_appl = "pi";
# <name> -> <from>-<to>
my %s_extParts;
my %s_damagedDisks;
my $s_hints;

if ($s_testRun){
	# replaying
	recorder::Init($s_appl, 2, $s_testRun);
    basic::Init($s_fnProgress, $s_testRun ne "");
} else {
    # recording:
	recorder::Init($s_appl, 1, "/tmp/$s_appl.recorder.data");
}
# we need no arg saving/restoring
basic::Init($s_fnProgress, $s_testRun ne "");
system ("./automount-control.sh disabled");
&Main();
system ("./automount-control.sh enabled");

my ($refExecs, $refLogs) = basic::GetVars();
recorder::Finish("execLines", $refExecs, "logLines", $refLogs);
if ($s_testRun){
	&finishTest;
}
exit 0;

# ===
# main routine.
sub Main{
	if (! -d $gv_mount_base){
		mkdir $gv_mount_base;
		print STDERR $gv_mount_base, " created\n" if $verbose;
	}
	&Progress("disk info (partprobe)");
	my @diskDevs = &GetDiskDev;
	
	&Progress("volume group info");
	&GetVG;
	# get the info from blkid
	
	&Progress("block id info");
	&GetBlockId;
	&Progress("-merging infos");
	&MergeDevs;
	&Progress("logical volume info");
	&GetLvmPartitions;
	&LogicalView;
	
	&Progress("-writing info");
	my $dev;
	my @sorted = &SortDevNames(keys %s_blkids);
	foreach $dev (@sorted){
		&Out("$dev\t$s_blkids{$dev}");
	}
	&Out("!phDisk=" . JoinPhysicalDiskInfo());
	&Out("!labels=;" . join(";", @s_labels));
	my $val = "!VG=";
	foreach my $ix (0..$#s_vg){
	    $val .= ";" . $s_vg[$ix] . ":" . $s_vgSize[$ix];
	}
	&Out($val);
	&Out('!LV=' . join(';', @s_lv));
	&Out("!GapPart=$s_gapPart");
    &Out("!osinfo=" . &GetSiduInfo());
	&PhysicalView;
	&VgInfo;
	&basic::Progress("writing info", 1);
	recorder::Put("partition_info", \@s_output);

	my $temp = recorder::WriteFile(join("\n", @s_output), ".out", "", 0, 
	   "/var/cache/sidu-base");
	unlink($s_answer) if -e $s_answer;
	print STDERR "cannot rename: $temp -> $s_answer $!" unless rename($temp, $s_answer);
	&UnmountAll();
}
# ===
# Normalizes a number to N digits.
# Example: sda, 1, 3 -> sda001
# @param prefix     non number part
# @param number     number part
# @param width      count of digits of the result
# @return           <prefix><<>width>-digit-number>
sub NormNumber{
    my $prefix = shift;
    my $number = shift;
    my $width = shift;
    $width = 3 unless $width;
    my $rc = sprintf ("$prefix%0${width}d", $number);
    return $rc;
}
# ===
# Sorts device names.
# e.g. (sda1, sda2, sda10) instead of (sda1, sda10, sda2)
# @param names  names to sort
# @return       all names but sorted
sub SortDevNames{
    my @names = @_;
	my ($key, $name, %sorted);
    foreach $name (@names){
		$key = $name;
		$key =~ s/(\D+)(\d+)/&NormNumber($1,$2)/ge;
		$sorted{$key} = $name;
	}
	my @rc;
	foreach $key (sort keys %sorted){
		push(@rc, $sorted{$key});
	}
    return @rc;    
}
# ===
# Joins the info about the physical disks.
# @return   e.g "\tsda;12345;msdos;1;2;;WD Green 17383UI\tsdb;45689;gpt;3;0;efiboot damaged;USB"
sub JoinPhysicalDiskInfo{
    my $rc;
    my @sorted =  keys %s_physicalDisks;
    foreach my $dev (@sorted){
        my @vals = split(/\t/, $s_physicalDisks{$dev});
        if ($s_damagedDisks{$dev} ne ""){
            # size pType primaries extendeds attr model
            $vals[4] .= " damaged";
        }
        $rc .= "\t$dev;" . join(";", @vals);
    }
    return $rc;
}

# ===
# Gets info about the current os
# @return:  <flavour>;<arch>,<version> e.g. kde;32;11.1
sub GetSiduInfo{
    my $info = &recorder::FirstLineOf("/etc/siduction-version");
    # siduction 11.1 One Step Beyond - kde - 
    my ($version, $flavour) = ("x.y", "z");
    if ($info =~ /^\S+\s+([.\drc]+)\s.*-\s+(\w+) -/){
        ($version, $flavour) = ($1, $2);
    }
    # Linux version 3.7-8.towo-siduction-amd64 (Debian 3.7-14)...
    $info = &recorder::FirstLineOf("/proc/version");
    my $arch = $info =~ /amd64/ ? "64" : "32";
    return "$flavour;$arch;$version";
}
# ===
# release all mounts done by the script itself
sub UnmountAll{
	my @files = recorder::ReadStream("UnmountAll", $gv_mount_base);
	my $dir;
	my $run = 0;
	while ($run < 2){
		$run++;
		my $errors = 0;
		foreach $dir (@files){
			next if $dir =~ /\.{1,2}/;
			my $full = "$gv_mount_base/$dir";
			system ("echo $full >>$gv_log 2>&1");
			system ("umount $full >>$gv_log 2>&1");
			rmdir $full;
			if (-d $full){
				system ("lsof +d $full >>$gv_log 2>&1");
				$errors++;
			}
		}
		last if $errors == 0;
		sleep 1;
	}
}
	
# ===
# searches for extended info of a partition
# @param dev	e.g. /dev/sda2
# @param fs		file system, e.g. ext4
sub Detective{
	my $dev = shift;
	my $fs = shift;
	my $info = "";
	my $dirMount = &GetMountPoint($dev);
	if ($dirMount eq ""){
		$dirMount = sprintf("$gv_mount_base/p%03d", ++$gv_mount_no);
		mkdir $dirMount;
		system ("mount -o ro $dev $dirMount >>$gv_log 2>&1");
	}
	if ($fs eq "ntfs" || $fs =~ /^vfat|fat\d/){
		if (-d "$dirMount/windows/system32"){
			$info .= "\tos:windows";
		}
	}
	if (-d "$dirMount/etc"){
		$info .= &recorder::FirstLineOf("$dirMount/etc/debian_version", "distro");
		$info .= &recorder::FirstLineOf("$dirMount/etc/aptosid-version", "subdistro");
		$info .= &recorder::FirstLineOf("$dirMount/etc/sidux-version", "subdistro");
		$info .= &recorder::FirstLineOf("$dirMount/etc/siduction-version", "subdistro");
		$info .= "\tos:unix" if $info eq "" && -d "$dirMount/etc/passwd";
	}
	if ($dirMount =~ /^$gv_mount_base/){
		system ("umount $dirMount >>$gv_log 2>&1");
		rmdir $dirMount;
	}

	if ($dev !~ /swap/ && ($fs =~ /fs:ext\d/ || $fs eq "auto")){
		my @lines = recorder::ReadStream("Detective", "tune2fs -l $dev|");
		my $date;
		foreach(@lines){
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
	}
	return $info;
}

my %s_mounts;
my $s_mounts;
# ===
# Finds the mountpoint of a device
# @param dev	e.g. /dev/sda
# @return 		"": not found
#				otherwise: the mountpoint
sub GetMountPoint{
	my $dev = shift;
	if ($s_mounts eq ""){
		my @lines = recorder::ReadStream("GetMountPoint", "mount|");
		foreach(@lines){
			if (/^(\S+)\s+on\s+(\S+)/){
				$s_mounts{$1} = $2;
			}
		}
		$s_mounts = 1;
	}
	return $s_mounts{$dev};
}
# ===
# Returns the factor associated to the unit, e.g. 1024 belongs to Ki
# @param unit   K(i), M(i), G(i), T(i)
# @return       1024**N with N in (0..4)
sub UnitToFactor{
    my $rc = 1;
    my $unit = shift;
    if ($unit =~ /^kb/i){
        $rc = 1000;
    } elsif ($unit =~ /^k/i){
        $rc = 1024;
    } elsif ($unit =~ /^mb/i){
        $rc = 1000*1000;
    } elsif ($unit =~ /^m/i){
        $rc = 1024*1024;
    } elsif ($unit =~ /^gb/i){
        $rc = 1000*1000*1000;
    } elsif ($unit =~ /^g/i){
        $rc = 1024*1024*1024;
    } elsif ($unit =~ /^tb/i){
        $rc = 1000*1000*1000*1000;
    } elsif ($unit =~ /^t/i){
        $rc = 1024*1024*1024*1024;
    }
    return $rc;
}
# ===
# Gets the volume group info
# The info will be stored in @lvs
sub GetVG{
	my @lines = recorder::ReadStream("GetVG", "vgdisplay|");
	my $vgs = "";
	foreach(@lines){
		if (/VG Name\s+(\S+)/){
			push(@s_vg, $1);
		} elsif (/VG Size\s+(\d+)([.,](\d+))? (\w+)/){
			# VG Size   14,71 GiB
			my ($val, $val2, $unit) = ($1, $2, $3);
			$val2 .= "0" x (3 - length($val2));
			$val = int(1000*$val + $val2 * &UnitToFactor($unit) / 1024); 
            push(@s_vgSize, $val);
		}
	}

	my ($vg, @lvs, $lv);
	foreach $vg (@s_vg){
		@lvs = recorder::ReadStream("GetVG-2", "/dev/$vg");
		foreach $lv(@lvs){		
			next if $lv =~ /^\.{1,2}$/;
			push(@s_lv, "$vg/$lv");
		}
	}
}

# ===
# Gets the disk device info
# fills: %s_damagedDisks
# @return 	e.g. ("sda", "sdc")
sub GetDiskDev{
	&Progress("partprobe");
	my @lines = recorder::ReadStream("GetDiskDev", "partprobe -s|");
	my @rc;
	
	# count the interesting disks:
	foreach (@lines){
		if (m!Warning: /dev/(\w+)!){
			$s_damagedDisks{$1} = 1;
		} elsif (m!/dev/(\w+):\s+(msdos|gpt)\s+partitions\s(.+)!){
			$s_maxTasks += 1;
		}
	}

	foreach (@lines){
#/dev/sda: msdos partitions 1 2 3 4 <5 6>                                                                                          
#/dev/sdb: gpt partitions 1 5 6 7 
		if (m!/dev/(\w+):\s+(msdos|gpt)\s+partitions\s(.+)!){
			my ($dev, $class, $parts) = ($1, $2, $3);
			next if $s_damagedDisks{$dev};
			push(@rc, $dev);
			if ($class eq "msdos"){
				&Progress("fdisk");
				&GetFdiskInfo($dev);
			} else {
				&Progress("gdisk");
				&GetGdiskInfo($dev);
			}
		}
        if (m!/dev/(\w+):\s+(\w+)!){                                                                                                
			GetPhysicalDiskInfo($1, $2);
        }
	}
	my @disks = GetEmptyDisks();
	foreach (@disks){
		&Progress("empty disk");
		# fdisk calculates the partition gaps (here the whole disk)
		GetFdiskInfo($_);
	}
	return @rc;
}

# ===
# Gets the basic info about a disk.
# @param dev    disk name, e.g. sda
# @param class  "gpt"or "msdos""
sub GetPhysicalDiskInfo {
    my $disk = shift;
    my $class = shift;
    
    my @lines = recorder::ReadStream("GetPhysicalDiskInfo", "parted -s /dev/$disk print|");
    my ($model, $primaries, $extendeds) = (0, 0, "");
    my ($pType, $size, $unit, $info);
    foreach(@lines){
        if (/Model: (.*)/){ 
            $model = $1;
            $model =~ s/;/ /;
        } elsif (/Partition Table: (\S+)/){
            $pType = $1;
        } elsif (/Disk.*:\s+([\d,.]+)(\S+)/){
            ($size, $unit) = ($1, $2);
            $size =~ s/,/./;
            $size = int($size / 1024 / 1024 * UnitToFactor($unit));
        } elsif (/\s*(\d+)/){
            if ($1 <= 4 && $class ne "gpt"){
                $primaries++;
            } else {
                $extendeds++;
            }
        }
        $info = "efiboot" if /bios_grub/;
    }  
    $s_physicalDisks{$disk} = "$size\t$pType\t$primaries\t$extendeds\t$info\t$model";
}
# ===
# Finds an unused primary partition number
# Fills: $s_hints
# @param ref_partList	In/Out: e.g. " 1 4 "
# @return				0: no unused number
#						otherwise: an unused number
sub FindFreePrimary{
	my $refPartList = shift;
	my $rc = 0;
	if ($$refPartList != /\S/){
		$rc = 1;
	} else {
		my $last = 0;
		for (sort split/ /, $$refPartList){
			if (/(\d+)/){
				if ($1 > $last + 1){
					$rc = $last + 1;
					last;
				}
				$last = $1;
			}
		}
		$rc = 0 if $rc > 4;
		if ($rc == 0){
			$s_hints .= ";missingPrimary";
		} else {
			$$refPartList .= "$rc ";
		}
	}
	return $rc;
}

# ===
# Gets the disks without a partition table.
# @return	e.g ("sdc", "sdx")
sub GetEmptyDisks{
	my @files = recorder::ReadStream("getEmptyDisk", "/sys/block");
	my @rc;
	my $dev;
	foreach $dev (@files){
		# find all names without a digit:
		next if $dev =~ /^\.{1,2}$/;
		next if $dev =~ /\d/ && $dev !~ /mmcblk\d/;
		# already recognised by partprobe?
		next if $s_disks{$dev} ne "";
		push @rc, $dev;
	}
	return @rc;
}


# ===
# Gets the info from fdisk.
# @param disk		partition device, e.g. sda
# Fills: %s_devs, %s_disks, %s_extParts, $s_hints, $s_gapPart
sub GetFdiskInfo{
	my $disk = shift;
	my @lines = recorder::ReadStream("GetFdiskInfo", "fdisk -l /dev/$disk|");
	my ($dev, $size, $ptype, $info, $min, $max);
	my $sectorSize = 512;
	my $sectorCount = -1;
	my @sectors;
	my @partNos;
	my ($extMin, $extMax) = (-1, -1);
	foreach(@lines){
#/dev/sda6       118061056  1000215215   441077080   8e  Linux LVM
		if (m!^/dev/([a-z]+(\d+))\s\D*(\d+)\s+(\d+)\s+(\d+)[+]?\s+([0-9a-fA-F]{1,2})\s+(.*)!){
			my ($dev, $partno, $min, $max, $size, $ptype, $info)  = ($1, $2, $3, $4, $5, $6, $7);
			# extended partition?
			if ($ptype == 5){
				$s_extParts{$disk} = "$min-$max";
				($extMin, $extMax) = ($min, $max);
			} elsif ($ptype ne "ee"){
				# ignore extended and protective partitions:
				$s_devs{$dev} = "size:$size\tptype:$ptype\tpinfo:$info";
				push(@sectors, sprintf("%012d-%012d-%d", $min, $max, $partno));
				push(@partNos, $partno);
			}
		    $s_hasLVMFlag{"$disk$partno"} = $size if $ptype =~ /8e/i;
		} elsif (/total\s+(\d+)\s+sectors/){
			$sectorCount = $1;
		} elsif (m!logical/physical\): (\d+) bytes!){
			$sectorSize = $1;
		} elsif (/^Disk\s+([^:]+):.*\s(\d+)\s+bytes/){
			my ($dev, $bytes) = ($1, $2);
			my $mb = length($bytes) <= 6 ? 1 : substr($bytes, 0, length($bytes) - 6);
			$s_disks{$dev} = $mb;
		}
	}
	@sectors = sort(@sectors);
	@partNos = sort(@partNos);
	my $lastMax;
	my ($no);
	my $maxPartNo = $partNos[$#partNos];
	my $partList = " " . join(" ", @partNos) . " ";
	# 4 primary partitions makes it impossible to expand
	if ($partList eq " 1 2 3 4 " && $extMin > 0){
		$s_hints .= ";4primeries";
	} else {
		my ($noGap, $lbound, $ubound);
		my $lastMax = 2047;
		foreach(@sectors){
			# forget the ext. partition?
			$extMax = -1 if $extMin < 0;
			my ($min, $max, $no) = split(/-/);
			my $count = $min - $lastMax;
			if ($count*$sectorSize > $s_minPartSize){
				# in extended?
				if ($min >= $extMin && $min < $extMax){
					# is there a rest of the primary?
					if ($lastMax < $extMin - $s_minPartSize / $sectorSize){
						$lbound = $lastMax + 1;
						$ubound = $extMin - 1;
						$noGap = FindFreePrimary(\$partList);
						$s_gapPart .= ";$disk!$noGap-$lbound-$ubound";
					}
					$noGap = $maxPartNo <= 4 ? 5 : $maxPartNo + 1;
					$maxPartNo = $noGap;
					# the first 48 sectors in the extendet partition are reserved:
					$lastMax = $extMin + 47 if $lastMax < $extMin + 47;
					$max = $extMax if $max > $extMax;
				} else {
					if ($min > $extMax){
						# is there a rest of the extended partition?
						if ($lastMax < $extMax - $s_minPartSize/$sectorSize){
							$lbound = $lastMax + 1;
							# the first 48 sectors in the extendet partition are reserved:
							$lbound = $extMin + 47 if $lbound < $extMin + 47;
							$ubound = $extMax;
							$noGap = $maxPartNo >= 5 ? $maxPartNo + 1 : 5;
							$maxPartNo = $noGap;
							$s_gapPart .= ";$disk!$noGap-$lbound-$ubound";
							$extMin = -1;	
						}
					}
					$lastMax = $extMax if $min > $extMin && $lastMax < $extMax;
					next if ($min - $lastMax) * $sectorSize < $s_minPartSize;
					$noGap = FindFreePrimary(\$partList);
					next if $noGap <= 0;
				}
				$s_gapPart .= ";$disk!$noGap-" . ($lastMax + 1) . "-" . ($min - 1);
				$partList .= "$no ";
			}
			$lastMax = $max;		
		}
		if (($sectorCount - $lastMax) * $sectorSize > $s_minPartSize){
			my $ubound = $sectorCount - 1;
			my $lbound = $lastMax + 1;
			# in extended?
			if ($lastMax >= $extMin && $lastMax <= $extMax){
				$noGap = $maxPartNo <= 4 ? 5 : $maxPartNo + 1;
				$maxPartNo = $noGap;
				$lbound = $extMin + 48 if $lbound < $extMin + 48;
				$ubound = $extMax if $ubound > $extMax;
			} else {
				$noGap = FindFreePrimary(\$partList);
			}
			if ($noGap > 0){
				$s_gapPart .= ";$disk!$noGap-$lbound-$ubound";
			}
		}
	}
}


# ===
# Gets the info from gdisk
# Fills: %s_devs, $s_disks, $s_gapPart, s_gptDisks
# @param disk	e.g. sda
# @param cmd	the call of gdisk, e.g. sdc
sub GetGdiskInfo{
	my $disk = shift;
	my @lines = recorder::ReadStream("GetGdiskInfo", "echo 1 | gdisk -l /dev/$disk|");
	my $sectorSize = 512;
	my $lastSector = -1;
	my @sectors;
	my @partNos;
	my $isGpt = 0;
	foreach(@lines){
		s/\*/ /;
#   5        96392048        98799749   1.1 GiB     8200  Linux swap
#   6        98801798       176714999   37.2 GiB    8300  Linux filesystem
		if (/^\s+(\d+)\s+(\d+)\s+(\d+)\s+\S+\s+\S+B\s+([0-9A-Fa-f]+)\s+(.*)/){
			my ($partno, $min, $max, $ptype, $info) = ($1, $2, $3, $4, $5);
			push(@sectors, sprintf("%012d-%012d-%d", $min, $max, $partno));
			push(@partNos, $partno);
			my $dev = "$disk$partno";
			my $size = int(($max - $min + 1) * 1.0 * $sectorSize / 1024);
			$s_hasLVMFlag{$dev} = $size if $ptype =~ /8e00/i;
			$s_devs{$dev} = "size:$size\tptype:$ptype\tpinfo:$info";
#Disk /dev/sda: 3907029168 sectors, 1.8 TiB
		} elsif (m!Logical sector size: (\d+) bytes!){
			$sectorSize = $1;
		} elsif (/last usable sector is (\d+)/){
			$lastSector = $1;
#   GPT: present
		} elsif (/^\s*GPT: present/){
			$isGpt = 1;
		}
	}
	$s_gptDisks .= ';' . $disk if $isGpt;
	if ($lastSector >= 0){
		$s_disks{$disk} = int(($lastSector - 34) * 1.0 * $sectorSize / 1024);
	}
	@sectors = sort(@sectors);
	@partNos = sort(@partNos);
	my $lastMax = 2047;
	my ($no, $lbound, $ubound);
	my $maxPartNo = $partNos[$#partNos];
	my $partList = " " . join(" ", @partNos) . " ";
	foreach(@sectors){
		my ($min, $max, $no) = split(/-/);
		my $count = $min - $lastMax;
		if ($count*$sectorSize > $s_minPartSize){
			my $noGap = $no - 1;
			$noGap = ++$maxPartNo if $partList =~ / $noGap /;
			$lbound = $lastMax + 1;
			$ubound = $min - 1;
			$s_gapPart .= ";$disk!$noGap-$lbound-$ubound"; 
		}
		$lastMax = $max;		
	}
	if (($lastSector - $lastMax) * $sectorSize > $s_minPartSize){
		$maxPartNo++;
		$lastMax++;
		$s_gapPart .= ";$disk!$maxPartNo-$lastMax-$lastSector";
	}
}
# ===
# Normalizes a LV device name
# @param dev    device name with the format /dev/mapper/<vg>-<lv>
# @return       the device name with the format <vg>/<lv>
sub NormalizeLvmDevname{
    my $dev = shift;
    # the '-' in the VG or LV parts are doublicated to distinct from 
    # the separator between VG and LV part.
    $dev =~ s!mapper/!!;
    $dev =~ s/--/\t/g;
    my ($vg, $lv) = split(/-/, $dev);
    my $rc = "$vg/$lv";
    $rc =~ s/\t/-/g;
    return $rc;
}
# ===
# Evaluates the output of the command blkid.
# Fills: %s_blkids
sub GetBlockId{
	my ($label, $uuid, $fs, $info2, $dev);
	my @lines = recorder::ReadStream("GetBlockId", "/sbin/blkid -c /dev/null|");
	foreach(@lines){
		if (/^(\S+):/){
			my $dev = $1;
			$dev =~ s!/dev/!!;
			my ($info, $label, $uuid, $fs, $size);
			if (/LABEL="([^"]+)"/){
				$label = "\tlabel:$1";
				push(@s_labels, $1);
			}
			if (/TYPE="([^"]+)"/){
				$fs = "\tfs:$1";
			}
			if (/UUID="([^"]+)"/){
				$uuid = "\tuuid:$1";
			}
			if ($s_devs{$dev} ne ""){
				$info=$s_devs{$info}
			}
			$info .= &Detective("/dev/$dev", $fs);
			if ($dev =~ /mapper/){
			    $dev = NormalizeLvmDevname($dev);
			}
			$s_blkids{$dev} = "$label$fs$uuid$info$size";
		}
	}
}

# ===
# Moves info from @s_lv to %s_blkids
sub GetLvmPartitions{
	my ($dev, $info, $fs);
	for $dev (@s_lv){
		$fs = "auto";
		if ($s_blkids{$dev} eq ""){
    		$info = &Detective("/dev/$dev", $fs);
    		$s_blkids{$dev} = $info;
		}
	}
}
# ===
# Replaces a property of a partition with another value.
# If the property is not available it will be added.
# @param name   property name, e.g. "size"
# @param value  new value, e.g. "1234"
# @param info   summary of properties "size:0 ptype:0"
# @return       info with replaced property, e.g. "size:1234 ptype:0"
sub ReplaceProperty{
    my $name = shift;
    my $value = shift;
    my $info = shift;
    my $found = 0;
    my @cols = split(/\t/, $info);
    for my $ix (0..$#cols){
        if (index($cols[$ix], $name) == 0){
            $cols[$ix] = "$name:$value";
            $found = 1;
            last;
        }
    }
    push(@cols, "$name:$value") unless $found;
    $info = join("\t", @cols);
    return $info;
}
# ===
# Merge the info about the devices
# Enrichs %s_blkids with info from %s_devs
sub MergeDevs{
	# merge the two fields:
	foreach my $dev (keys %s_devs){
		my $info = $s_blkids{$dev};
		my $val = $s_devs{$dev};
		if ($info eq ""){
			$s_blkids{$dev} = $val;
		} else {
			my $size = "\t$1" if $val =~ /(size:\d+)/;
			my $ptype = "\t$1" if $val =~ /(id:\w+)/;
			my $info2 = "\t$1" if $val =~ /(pinfo:[^\t]+)/;
			$s_blkids{$dev} = "$info$size$ptype$info2";
		}
	}
}
# ===
# Converts a size (number + unit) into an amount of KiBytes.
# @param size		e.g. 4M or 243,3K or "22.7 GiByte"
# @return			amount in KiBytes
sub SizeToKiByte{
	my $size = shift;
	die "not a size (number+unit): $size" unless $size =~ /^([\d+.,]+)\s*([TGMK])?/i;
	my ($rc, $unit) = ($1, $2);
	$rc =~ s/,/./;
	$unit =~ tr/a-z/A-Z/;
	if ($unit eq "M"){
	    $rc *= 1024;
	} elsif ($unit eq "G"){
	    $rc *= 1024 * 1024;
	} elsif ($unit eq "T"){
	    $rc *= 1024 * 1024 * 1024;
	}
	return int($rc);
}
# ===
# Prepares the data for the physical view
sub PhysicalView{
	my @lines = recorder::ReadStream("PhysicalView", "pvdisplay|");
	# Put an end marker:
	push(@lines, "  --- Physical volume ---");
	my ($pvName, $vgName, $size, %devs, %unassigned, %assigned);
	foreach(@lines){
		chomp;
		if (/PV Name\s+(\S+)/){
			$pvName = $1;
			$pvName =~ s!/dev/!!;
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
					$devs{$pvName} .= "\t|$pvName|$size";
					$assigned{$pvName} = 1;
				}
				$vgName = "";
			}
		}
	}
	my ($key, $out);
	my @sorted = SortDevNames(keys %unassigned);
	for $key (@sorted){
		$out .= ";$key"; 
	} 
	&Out("!FreeLVM:$out");
	$out = '';
	my @sorted = SortDevNames(keys %s_hasLVMFlag);
	for $key (@sorted){
	    next if $assigned{$key} || $unassigned{$key};
		$out .= ";$key"; 
	} 
	&Out("!MarkedLVM:$out");
}

# ===
# Prepares the data for the logical view
sub LogicalView{
	my @lines = recorder::ReadStream("LogicalView", "lvdisplay|");
	my ($lvName, $vgName, $size, $access, %devs, %snaps, $parent);
	# set end marker:
	push(@lines, "--- Logical volume");
	foreach(@lines){
		chomp;
		if (/LV Name\s+(\S+)/){
			$lvName = $1;
			$lvName =~ s!/dev/[^/]+/!!;
		} elsif (/VG Name\s+(\S+)/){
			$vgName = $1;
		} elsif (m!LV Size\s+(\S.*)!){
			$size = SizeToKiByte($1);
		} elsif (m!LV snapshot status.*\sfor\s+(\S+)!){
			$parent = $1;
			$parent =~ s!/dev/[^/]+/!!;
		} elsif (m!LV Write Access\s+(\S.*)!){
			$access = $1;
		} elsif (/--- Logical volume/){
			if ($lvName ne ""){
			    my $dev = "$vgName/$lvName";
				if ($parent){
					$snaps{$vgName} .= "\t|$lvName|$size|$access|$parent";
					$parent = '';
				} else {
					$s_devs{$dev} .= "\t|$lvName|$size|$access";
				}
				if ($s_blkids{$dev} eq ""){
				    $s_blkids{$dev} = "\tsize:$size\tinfo:LV";
				} else {
				    my $info = ReplaceProperty("size", $size, 
				        $s_blkids{$dev});
				    $info .= "\tptype:LV";
				    $s_blkids{$dev} = $info;
				}
			}
		}
	}
	my ($key, $out);
	my @sorted = &SortDevNames(keys %snaps);
	foreach $key (@sorted){
		$out .= "\f\t$key" . $snaps{$key}; 
	}
	&Out("!SnapLVM:$out");
}
# ===
# Prepares the data for the volume group
sub VgInfo {
	my @lines = recorder::ReadStream("VgInfo", "vgdisplay|");
	my ($vgName, $size, $access, $status, $free, $alloc, %vgs, $peSize);
	# set end marker:
	push(@lines, "--- Volume group"); 
	foreach(@lines){
		chomp;
		if (/VG Name\s+(\S+)/){
			$vgName = $1;
		} elsif (m!VG Size\s+(\S.*)!){
			$size = SizeToKiByte($1);
		} elsif (m!VG Access\s+(\S.*)!){
			$access = $1;
		} elsif (m!VG Status\s+(\S.*)!){
			$status = $1;
		} elsif (m!Alloc PE / Size.*/\s+(\S.*)!){
			$alloc = SizeToKiByte($1);
		} elsif (m!Free  PE / Size.*/\s+(\S.*)!){
			$free = SizeToKiByte($1);
		} elsif (m!PE Size\s+(\S.*)!){
			$peSize = SizeToKiByte($1);
		} elsif (/--- Volume group/){
			if ($vgName ne ""){
				$vgs{$vgName} .= "|$size|$alloc|$free|$peSize|$status|$access";
			}
		}
	}
	my ($key, $out);
	foreach $key (sort keys %vgs){
		$out .= "\t|$key" . $vgs{$key}; 
	}
	if ($out ne ""){
		&Out("!VgLVM:$out");
	}
}

# ===
# Puts a line to the output
# @param line
# Fills: @s_output
sub Out{
    my $line = shift;
    if($line =~ m!/dev/!){
        $line .= "";
    }
    push(@s_output, $line);
}
# Writes the progress file.
#@param task	name of the current task
sub Progress{
	my $task = shift;
	if ($task =~ /^-/){
		$task = substr($task, 1);
	} else {
		$task = "collecting " . $task;
	}
	$task .= " ...";
	if ($s_currTask == $s_maxTasks){
		$s_maxTasks += 5;
	}
	my $temp = $s_fnProgress . ".tmp";
	open my $PROGRESS, ">", $temp || die "$temp: $!";
	my $percent = int(100 * $s_currTask / $s_maxTasks);
	print $PROGRESS <<EOS;
PERC=$percent
CURRENT=<b>$task</b>
COMPLETE=completed $s_currTask of $s_maxTasks
EOS
	close $PROGRESS;
	unlink $s_fnProgress if -f $s_fnProgress;
	rename $temp, $s_fnProgress;
}

sub TestGetDiskDev{
	&GetDiskDev;
	my @a = ("abc", "def");
	my @b = ("abc", "123");
	die "!" unless test::Equal("s_gapPart",  
		";sdb!4-100000-1023999;sdb!8-2457600-4194303;sdb!9-10897408-31248350;sdc!2-2048-79999;sdc!3-100000-300000;sdc!8-300049-1023999;sdc!9-2457600-4194303;sdc!10-10897408-31248383;sdx!1-2048-31248383",
		$s_gapPart);
	die unless test::EqualHash("s_devs", test::ToHash("
/dev/sdb1=>size:48976\tptype:8E00\tpinfo:Linux LVM
/dev/sdb5=>size:716800\tptype:8300\tpinfo:Linux filesystem
/dev/sdb6=>size:3145728\tptype:8300\tpinfo:Linux filesystem
/dev/sdb7=>size:204800\tptype:8300\tpinfo:Linux filesystem
/dev/sdc1=>size:48976 ptype:8e pinfo:Linux LVM
/dev/sdc5=>size:716800 ptype:83 pinfo:Linux
/dev/sdc6=>size:3145728\tptype:83\tpinfo:Linux
/dev/sdc7=>size:204800\tptype:83\tpinfo:Linux
"),
		\%s_devs, "\n");
	die unless test::EqualHash("s_disks", test::ToHash(";sdb=>15624158;sdc=>15999;sdx=>15999"),
		\%s_disks, ";");
	die unless test::EqualHash("s_extParts", test::ToHash(";sdc=>300001-31248383"),
		\%s_extParts, "\n");
	die unless test::Equal("s_hints", "",
		$s_hints);
}