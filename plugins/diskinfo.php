<?php
define ('SEPARATOR_PARTITION', '|');
define ('SEPARATOR_INFO', "\t");
define ('PAGE_OVERVIEW', 0);
define ('PAGE_PHYSICAL_VIEW', 1);
define ('PAGE_LOGICAL_VIEW', 2);
/**
 * Administrates the disk and partition infos.
 *
 */
class DiskInfo {
	/// the current plugin
	var $page;
	/// the session info
	var $session;
	// the disk array: name => size in kbyte
	var $disks;
	/// an array of PartitionInfo.
	var $partitions;
	/// the file created by the shellserver
	var $filePartInfo;
	/// True: the partition info is avaliable.
	var $hasInfo;
	/// One of PAGE_OVERVIEW .. PAGE_LOGICAL_VIEW
	var $pageIndex;
	/// example (with additional blanks):
	/// "\t \f usb8 \f | /dev/sdc2|8,00 GiB   \t \f vertex \f |/dev/sda3|2,92 GiB  \f |/dev/sdc1|2,92 GiB"
	/// ALL ::= \t VG1 \t VG2 ... ; VGx ::= \f <vg_name> \f <PV1> \f <PV2> ... ; PVx ::= | <PV_name> | <PV_attr_1> ...
	var $physicalViewLVM;
	/// example (with additional blanks):
	/// "\t \f usb8 \f | /dev/usb8/backup-home|8,00 GiB|read/write   \t \f vertex \f |/dev/vertex/onestep|2,92 GiB|read/write  \f |/dev/vertex/ubuntu|2,92 GiB|readwrite"
	/// ALL ::= \t VG1 \t VG2 ... ; VGx ::= \f <vg_name> \f <PV1> \f <PV2> ... ; PVx ::= | <PV_name> | <PV_attr_1> ...
	var $logicalViewLVM;
	/// example (with additional blanks):
	/// "\t \f usb8 \f | /dev/usb8/backup-home|8,00 GiB|read only|home   \t \f vertex \f |/dev/vertex/backup_monday|2,92 GiB|read only|home
	/// ALL ::= \t VG1 \t VG2 ... ; VGx ::= \f <vg_name> \f <PV1> \f <PV2> ... ; PVx ::= | <PV_name> | <PV_attr_1> ...
	var $snapshotLVM;
	/// example (with additional blanks):
	/// "\t |/dev/sda3|1.87 GiB \t |/dev/sdb5|9.95 GiB
	var $freePV;
	/// example (with additional blanks):
	/// "\t |/dev/sda3|1.87 GiB \t |/dev/sdb5|9.95 GiB
	var $uninitializedPV;
	/// array: key: name value: [size, used, free, status, access]
	var $vgInfo;
	/** Constructor.
	 *
	 * @param $session		the session info
	 * @param $page			the current plugin. Type: Derivation of Page
	 * @param $forceRebuild	Deletes the partition info file to force rebuilding
	 */
	function __construct(&$session, $page, $forceRebuild){
		$this->session = $session;
		$this->hasInfo = false;
		$this->page = $page;
		$this->name = $page->name;
		if (strcmp($this->name, "overview") == 0)
			$this->pageIndex = PAGE_OVERVIEW;
		elseif (strcmp($this->name, "physicalview") == 0)
			$this->pageIndex = PAGE_PHYSICAL_VIEW;
		elseif (strcmp($this->name, "logicalview") == 0)
			$this->pageIndex = PAGE_LOGICAL_VIEW;

		$this->partitions = NULL;

		$this->disks = array();
		$this->filePartInfo = $session->configuration->getValue(
			'diskinfo.file.demo.partinfo');
		if (! file_exists($this->filePartInfo))
			$this->filePartInfo = $session->configuration->getValue(
				'diskinfo.file.partinfo');

		$this->session->userData->setValue('physicalview', 'opt_assign_pv_pv', '');
		$this->session->userData->setValue('physicalview', 'opt_assign_pv_vg', '');
		$this->session->userData->setValue('physicalview', 'opt_create_pv_pv', '');
		$this->session->userData->setValue('physicalview', 'opt_create_vg_pv', '');
		$this->session->userData->setValue('logicalview', 'opt_create_lv_lv', '');
		$this->session->userData->setValue('logicalview', 'opt_del_snap_snap', '');
		$this->session->userData->setValue('logicalview', 'opt_volume_group', '');

		if ($forceRebuild && file_exists($this->filePartInfo)){
			$this->session->userData->setValue('', 'partinfo', '');
			unlink($this->filePartInfo);
		}
		$session->trace(TRACE_FINE, 'diskinfo: ' . $this->filePartInfo);
		$wait = (int) $session->configuration->getValue('diskinfo.wait.partinfo');
		$maxWait = (int) $session->configuration->getValue('diskinfo.wait.partinfo.creation');
		if ($session->testFile($this->filePartInfo,
				'partinfo.created', $wait, $maxWait))
			$session->exec($this->filePartInfo, SVOPT_DEFAULT,
				'sdc_partinfo', NULL, 0);
		$this->hasInfo = file_exists($this->filePartInfo);
		$txt = $this->hasInfo ? "mit Info" : "ohne Info";
		$this->session->trace(TRACE_RARE, "diskinfo: $txt");
		if ($this->hasInfo)
			$this->readPartitionInfo();
		else
			$this->clearPartitionInfo();
	}
	/** Forces the reload of the partition info.
	 */
	function forceReload(){
		if (file_exists($this->filePartInfo))
			unlink($this->filePartInfo);
	}
	/** Sets all gui info related with partition info to undefined.
	 */
	function clearPartitionInfo(){
		$this->session->userData->setValue('overview', 'opt_disk', '-');
		$this->session->userData->setValue('overview', 'opt_disk2', '-');

		$this->page->setRowCount('partinfo', 0);
	}
	/**
	 * Handles the info of a LVM (uninitialized PV)
	 *
	 * @param $info a string describing the uninitialized PV.
	 */
	function handleMarkedLVM($info){
		$this->uninitializedPV = $info;
		if ($this->pageIndex == PAGE_PHYSICAL_VIEW){
			$list = '';
			if (! empty($info)){
				$array = explode(substr($info, 0, 1), $info);
				for ($ii = 1; $ii < count($array); $ii++){
					$value = $array[$ii];
					$array2 = explode(substr($value, 0, 1), $value);
					$list .= ';' . $array2[1];
				}
				if (! empty($list))
					$list = substr($list, 1);
			}
			$this->session->userData->setValue('physicalview', 'opt_create_pv_pv', $list);
		}
	}
	/**
	 * Handles the info of a LVM (unassigned PV)
	 *
	 * @param $info a string describing the unassigned PV.
	 */
	function handleFreeLVM($info){
		$this->freePV = $info;
		if ($this->pageIndex == PAGE_PHYSICAL_VIEW){
			$list = '';
			if (! empty($info)){
				$array = explode(substr($info, 0, 1), $info);
				for ($ii = 1; $ii < count($array); $ii++){
					$value = $array[$ii];
					$array2 = explode(substr($value, 0, 1), $value);
					$list .= ';' . $array2[1];
				}
				if (! empty($unassigned))
					$list = substr($list, 1);
			}
			$this->session->userData->setValue('physicalview', 'opt_assign_pv_pv', $list);
			$this->session->userData->setValue('physicalview', 'opt_create_vg_pv', $list);
		}
	}
	/**
	 * Handles the info of a LVM (physical view)
	 *
	 * @param $info a string describing the relation PV to VG
	 */
	function handlePhysicalLVM($info){
		$this->physicalViewLVM = $info;

		$groups = '';
		if (! empty($info)){
			$volumeGroups = explode(substr($info, 0, 1), $info);
			for ($gg = 1; $gg < count($volumeGroups); $gg++){
				$vol = $volumeGroups[$gg];
				$vols = explode(substr($vol, 0, 1), $vol);
				$groups .= ';' . $vols[1];
			}
			if (! empty($groups))
				$groups = substr($groups, 1);
		}
		$this->session->userData->setValue('physicalview', 'opt_assign_pv_vg', $groups);
		$this->session->userData->setValue('logicalview', 'opt_volume_group', $groups);
		$this->session->userData->setValue('snapshot', 'opt_volume_group', $groups);
	}
	/**
	 * Handles the info of a LVM (logical view)
	 *
	 * @param $info a string describing the relation LV to VG
	 */
	function handleLogicalLVM($info){
		$this->logicalViewLVM = $info;
		$volumeGroups = explode(substr($info, 0, 1), $info);
		for ($gg = 1; $gg < count($volumeGroups); $gg++){
			$vol = $volumeGroups[$gg];
			$arrayLV = array();

			$logVols = explode(substr($vol, 0, 1), $vol);
			$vgName = $logVols[1];
			for ($vv = 2; $vv < count($logVols); $vv++){
				$logVol = $logVols[$vv];
				$attrs = explode(substr($logVol, 0, 1), $logVol);
				array_push($arrayLV, $attrs[1]);
			}
		}
	}
	/**
	 * Handles the info of a LVM (logical view)
	 *
	 * @param $info a string describing the relation LV to VG
	 */
	function handleSnapshotsLVM($info){
		$this->snapshotLVM = $info;
	}
	/**
	 * Handles the info of the volume groups.
	 *
	 * @param $info a string describing the volume groups
	 */
	function handleVgLVM($info){
		$volumeGroups = explode(substr($info, 0, 1), $info);
		$this->vgInfo = array();
		for ($gg = 1; $gg < count($volumeGroups); $gg++){
			$vol = $volumeGroups[$gg];
			$array = explode(substr($vol, 0, 1), $vol);
			array_shift($array);
			$vgName = array_shift($array);
			$this->vgInfo[$vgName] = $array;
		}
	}
	/** Gets the data of the partition info and put it into into the user data.
	 */
	function importPartitionInfo(){
		$this->session->trace(TRACE_RARE, 'DiskInfo.importPartitionInfo()');
		$file = File($this->filePartInfo);
		$partitions = '';
		$excludes = $this->session->configuration->getValue('diskinfo.excluded.dev');
		if (strlen($excludes) > 0)
			$excludes = '/' . str_replace('/', '\/', $excludes) . '/';

		while( (list($no, $line) = each($file))){
			$line = chop($line);
			if (strncmp($line, 'PhLVM:', 6) == 0)
				$this->handlePhysicalLVM(substr($line, 6));
			elseif (strncmp($line, 'LogLVM:', 7) == 0)
				$this->handleLogicalLVM(substr($line, 7));
			elseif (strncmp($line, 'VgLVM:', 6) == 0)
				$this->handleVgLVM(substr($line, 6));
			elseif (strncmp($line, 'FreeLVM:', 8) == 0)
				$this->handleFreeLVM(substr($line, 8));
			elseif (strncmp($line, 'MarkedLVM:', 10) == 0)
				$this->handleMarkedLVM(substr($line, 10));
			elseif (strncmp($line, 'SnapLVM:', 8) == 0)
				$this->handleSnapshotsLVM(substr($line, 8));
			else {
				$cols = explode("\t", $line);
				$dev = str_replace('/dev/', '', $cols[0]);
				if (strlen($excludes) != 0 && preg_match($excludes, $dev) > 0)
					continue;
				if (count($cols) == 2){
					// Disks
					$this->disks[$dev] = $cols[1];
					continue;
				}
				$infos = array();
				foreach($cols as $key => $value){
					$vals = explode(':', $value);
					if (count($vals) > 1){
						$infos[$vals[0]] = $vals[1];
					}
				}
				$size = isset($infos['size']) ? (int)$infos['size'] : 0;
				$label = isset($infos['label']) ? $infos['label'] : '';
				$ptype = isset($infos['ptype']) ? $infos['ptype'] : '';
				$fs = isset($infos['fs']) ? $infos['fs'] : '';
				$pinfo = isset($infos['pinfo']) ? $infos['pinfo'] : '';
				$debian = isset($infos['debian']) ? $infos['debian'] : '';
				$os = isset($infos['os']) ? $infos['os'] : '';
				$distro = isset($infos['distro']) ? $infos['distro'] : '';
				$subdistro = isset($infos['subdistro']) ? $infos['subdistro'] : '';
				$date = '';
				if (isset($infos['created']))
					$date = ' ' . $this->session->i18n('rootfs', 'CREATED', 'created') . ': ' . $infos['created'];
				if (isset($infos['modified']))
					$date .= ' ' . $this->session->i18n('rootfs', 'MODIFIED', 'modified') . ': ' . $infos['modified'];

				$info = empty($subdistro) ? $distro : $subdistro;
				if (empty($info))
					$info = $os;
				if (! empty($date))
					$info .= $date;
				$partitions .= "|$dev\t$label\t$size\t$ptype\t$fs\t$info";
			}
		}
		// strip the first separator:
		$partitions = substr($partitions, 1);
		$this->session->userData->setValue('', 'partinfo', $partitions);
	}
	/** Reads the partition infos from the user data.
	 */
	function readPartitionInfo(){
		$this->session->trace(TRACE_RARE, 'DiskInfo.readPartitionInfo()');
		if ($this->hasInfo)
			$this->importPartitionInfo();
		switch($this->pageIndex)
		{
			case PAGE_OVERVIEW:
			case PAGE_PHYSICAL_VIEW:
			case PAGE_LOGICAL_VIEW:
			default:
				$excludedPartition = "";
				break;
		}
		$value = $this->session->userData->getValue('', 'partinfo');
		$disks = array();
		$devs = '-';
		$labels = '-';
		$info = $this->session->userData->getValue('', 'partinfo');
		$minSize = (int) $this->session->configuration->getValue('diskinfo.root.minsize.mb');

		$this->partitions = array();
		$disklist = '';
		$diskOnlyList = '';
		if (empty($info)){
			foreach ($this->disks as $key => $val)
				$diskOnlyList .= ';' . $key;
		}else{
			$parts = explode(SEPARATOR_PARTITION, $info);
			foreach($parts as $key => $info){
				$item = new PartitionInfo($info);
				$type = strtolower($item->partType);
				$isProtected = strcmp($type, '8200') == 0
					|| strcmp($item->filesystem, 'swap') == 0
					|| strcmp($type, '8e00') == 0 // LVM
					|| strcmp($type, 'fd00') == 0 // Linux-RAID
					|| strcmp($type, 'a906') == 0 // Netbsd-RAID
					|| strcmp($type, 'ab00') == 0 // Apple boot
					|| strncmp($type, 'af', 2) == 0 // Apple (HFS, RAID ...
					;
				$hasFileSys = ! empty($item->filesystem)
					&& strcmp($item->filesystem, "LVM2_member") != 0;
				$ignored = strcmp($item->device, $excludedPartition) == 0
					|| $isProtected
//					|| $this->pageIndex == PAGE_ROOTFS && $item->megabytes < $minSize && $item->megabytes > 0
//					|| $this->pageIndex == PAGE_MOUNTPOINT && ! $hasFileSys
					;
				$disk = preg_replace('/[0-9]/', '', $item->device);
				if (empty($disk))
					continue;
				if (! isset($disks[$disk])){
					$disks[$disk] = 1;
				}
				$this->partitions[$item->device] = $item;
				// Ignore too small partitions and swap:
				if (! $ignored)
					$devs .= ';' . $item->device;
				if (! empty($item->label)){
					$labels .= ';' . $item->label;
				}
			}
			foreach ($disks as $key => $val)
				$disklist .= ';' . $key;
			foreach ($this->disks as $key => $val)
				if (! isset($disks[$key]))
					$disklist .= ';' . $key;
		}
		if (! empty($disklist) || ! empty($diskOnlyList))
		{
			switch($this->pageIndex)
			{
				case PAGE_OVERVIEW:
					$this->session->userData->setValue('overview', 'opt_disk', $this->page->getConfiguration('txt_all') . $disklist . $diskOnlyList);
					$this->session->userData->setValue('overview', 'opt_disk2', $disklist);
					break;
				case PAGE_PHYSICAL_VIEW:
					break;
				case PAGE_LOGICAL_VIEW:
				default:
					break;
			}
		}
	}
	/** Gets the label of a device.
	 *
	 * @param $device	the name of the device, e.g. sda1
	 * @return '': no label available. Otherwise: the label of the device
	 */
	function getPartitionLabel($device){
		$rc = '';
		if (isset($this->partitions[$device]))
			$rc = $this->partitions[$device]->label;
		return $rc;
	}
	/** Gets the filesystem of a device.
	 *
	 * @param $device	the name of the device, e.g. sda1
	 * @return '': no filesystem available. Otherwise: the filesystem of the device
	 */
	function getPartitionFs($device){
		$rc = '';
		if (isset($this->partitions[$device]))
			$rc = $this->partitions[$device]->filesystem;
		return $rc;
	}
	/** Gets the device name of a device given by its label.
	 *
	 * @param $label	the label of the wanted device
	 * @return '': no label available. Otherwise: the label of the device
	 */
	function getPartitionName($label){
		$rc = '';
		foreach($this->partitions as $key => $item){
			if (strcmp($label, $item->label) == 0){
				$rc = $key;
				break;
			}
		}
		return $rc;
	}
	/** Returns the partitions of a given disk.
	 *
	 * @param $disk	the name of the disk, e.g. sda
	 * @return an array with the partitions (type PartitionInfo)
	 */
	function getPartitionsOfDisk($disk){
		$rc = array();
		$len = strlen($disk);
		foreach($this->partitions as $dev => $item){
			if (strncmp($disk, $dev, $len) == 0)
				$rc[$dev] = $item;
		}
		return $rc;
	}
	/** Returns the information about the physical volumes of LVM volume groups.
	 *
	 * @return a string describing the the physical volumes of LVM volume groups
	 */
	function getPVInfo(){
		return $this->physicalViewLVM;
	}
	/** Returns the information about the physical volumes of LVM volume groups.
	 *
	 * @return a string describing the the physical volumes of LVM volume groups
	 */
	function getLVInfo(){
		return $this->logicalViewLVM;
	}
	/** Returns the information about the not assigned physical volumes.
	 *
	 * @return a string describing the the unassigned physical volumes of LVM
	 */
	function getFreePVInfo(){
		return $this->freePV;
	}
	/** Returns the information about the not assigned physical volumes.
	 *
	 * @return a string describing the the unassigned physical volumes of LVM
	 */
	function getUninitializedPVInfo(){
		return $this->uninitializedPV;
	}

	/** Returns the information about snapshots.
	 *
	 * @return a string describing the the snapshots
	 */
	function getSnapshotInfo(){
		return $this->snapshotLVM;
	}
	/** Builds dynamic part of the partition info table.
	 */
	function buildInfoTable(){
		$disk = $this->session->getField('disk2');
		$disk = trim($disk);
		if (! ($this->hasInfo && ! empty($disk)))
			$this->page->setRowCount('partinfo', 0);
		else {
			$partitions = $this->getPartitionsOfDisk($disk);
			$this->page->setRowCount('partinfo', 0);
			foreach ($partitions as $dev => $item){
				$label = $item->label;
				$fs = $item->filesystem;
				$size = $item->size;
				$type = $item->partType;
				$info = $item->info;
				$dev = str_replace('/dev/', '', $dev);
				$row = "$dev|$label|$size|$type|$fs|$info";
				$this->page->setRow('partinfo', $row);
			}
		}
	}
	/** Returns a message that we must wait for the partition info.
	 *
	 * @return '': Partition info is available. Otherwise: the info message
	 */
	function getWaitForPartitionMessage(){
		if ($this->hasInfo)
			$rc = '';
		else{
			$rc = $this->session->configuration->getValue('diskinfo.txt_wait_for_partinfo');
		}
		return $rc;
	}
	/**
	 * Returns the size of a physical volume in KiByte.
	 * @param $pv Name of the PV
	 * @return 	-1: PV not found<br>
	 * 			otherwise: the size of the PV in KiByte
	 */
	function getPVSize($pv){
		$rc = -1;
		if (strncmp($pv, '/dev/', 4) == 0)
			$pv = substr($pv, 5);
		if (array_key_exists($pv, $this->partitions)){
			$size = $this->partitions[$pv]->size;
			$value = floatval($size);
			if (stripos($size, "M") > 0)
				$value *= 1024;
			elseif (stripos($size, "G") > 0)
				$value *= 1024*1024*1024;
			elseif (stripos($size, "T") > 0)
				$value *= 1024*1024*1024*1024*1024;
			$rc = intval($value);
		}
		return $rc;
	}
	/**
	 * Returns a description of a VG.
	 *
	 * @param $vg volume group
	 * @return 	null: Not found<br>
	 * 			otherwise: the description of the volumegroup
	 */
	function getVGInfo($vg){
		$rc = '';
		if (array_key_exists($vg, $this->vgInfo)){
			$array = $this->vgInfo[$vg];
			$rc = $this->session->i18n('diskinfo', 'txt_desc_vg_info')
				. ' ' . $array[0] . ' / ' . $array[1] . ' / ' . $array[2]
				. ' / ' . $array[3] . ' ' . $array[4]  . ' ' . $array[5];
		}
		return $rc;
	}
	/**
	 * Returns the list of logical volumes of a given volume group.
	 *
	 * @param $vg		Name of the volume group
	 * @return 	"": $vg not found
	 * 			otherwise: the list of LV separated by ';', e.g. 'home;opt;data'
	 */
	function getLVsOfVG($vg){
		return $this->getItemNamesOfVG($vg,  $this->logicalViewLVM);
	}
	/**
	 * Returns the list of logical volumes of a given volume group.
	 *
	 * @param $vg		Name of the volume group
	 * @return 	"": $vg not found
	 * 			otherwise: the list of LV separated by ';', e.g. 'home;opt;data'
	 */
	function getSnapOfVG($vg){
		return $this->getItemNamesOfVG($vg,  $this->snapshotLVM);
	}

	/**
	 * Returns the list of logical volumes of a given volume group with a given type.
	 *
	 * @param $vg		Name of the volume group
	 * @param $line		List of the VGs with the list of items.
	 * @return 	"": $vg not found
	 * 			otherwise: the list of items separated by ';', e.g. 'home;opt;data'
	 */
	function getItemNamesOfVG($vg, $line){
		$rc = null;
		if (! empty($line)){
			$vgs = explode(substr($line, 0, 1), $line);
			for ($gg = 1; $gg < count($vgs); $gg++){
				$devlist = $vgs[$gg];
				$devs = explode(substr($devlist, 0, 1), $devlist);
				if (strcmp($devs[1], $vg) == 0){
					$list = "";
					for ($dd = 2; $dd < count($devs); $dd++){
						$info = $devs[$dd];
						$infos = explode(substr($info, 0, 1), $info);
						// e.g. /dev/group1/home
						$nodes = explode('/', $infos[1]);
						if (count($nodes) == 4)
							$list .= ';' . $nodes[3];
						else
							$list .= ';' . $nodes;
					}
					if (! empty($list))
						$list = substr($list, 1);
					$rc = $list;

				}
			}
		}
		return $rc;
	}
	/**
	 * Tests whether at least one snapshot of a given LV exists.
	 *
	 * @param $vg 	volume group to test
	 * @param $lv	logical volume to test
	 * @return 	true: at least one snapshot exists.<br/>
	 * 			false: otherwise
	 */
	function anySnapshot($vg, $lv){
		$rc = false;
		$snaps = $this->snapshotLVM;

		if (! empty($snaps)){
			$vgs = explode(substr($snaps, 0, 1), $snaps);
			for ($gg = 1; ! $rc && $gg < count($vgs); $gg++){
				$devlist = $vgs[$gg];
				$devs = explode(substr($devlist, 0, 1), $devlist);
				if (strcmp($devs[1], $vg) == 0){
					for ($dd = 2; ! $rc && $dd < count($devs); $dd++){
						$info = $devs[$dd];
						$array = explode(substr($info, 0, 1), $info);
						if (count($array) > 4 && strcmp($array[4], $lv) == 0)
							$rc = true;
					}
				}
			}
		}
		return $rc;
	}
}
/**
 * Implements a storage for a partition info.
 * @author hm
 */
class PartitionInfo{
	/// e.g. sda1
	var $device;
	/// volume label
	var $label;
	/// size and unit, e.g. 11GB
	var $size;
	/// partition type
	var $partType;
	/// e.g. ext4
	var $filesystem;
	/// additional info
	var $info;
	/// size in MByte
	var $megabytes;
	/** Constructor.
	 *
	 * @param $info		the partition info, separated by "\t"
	 */
	function __construct($info){
		list($this->device,
				$this->label,
				$size,
				$this->partType,
				$this->filesystem,
				$this->info)
			= explode(SEPARATOR_INFO, $info);
		$size = (int) $size;
		$this->megabytes = $size / 1000;
		if ($size < 10*1000)
			$size = sprintf('%dMB', $size);
		elseif ($size < 10*1000*1000)
			$size = sprintf('%dMB', $size / 1000);
		elseif ($size < 10*1000*1000*1000)
			$size = sprintf('%dGB', $size / 1000 / 1000);
		else
			$size = sprintf('%dTB', $size / 1000 / 1000 / 1000);
		$this->size = $size;
	}
}

?>