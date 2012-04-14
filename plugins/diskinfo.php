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
	/// "\t |/dev/sda3|1.87 GiB \t |/dev/sdb5|9.95 GiB
	var $freePV;
	/// example (with additional blanks):
	/// "\t |/dev/sda3|1.87 GiB \t |/dev/sdb5|9.95 GiB
	var $uninitializedPV;
	/// the VG array: name => array of LV names
	var $volumeGroups;
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
		$this->volumeGroups = array();
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
		$this->session->userData->setValue('logicalview', 'volume_group', '');

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
			$this->volumeGroups[$vgName] = $arrayLV;
			for ($vv = 2; $vv < count($logVols); $vv++){
				$logVol = $logVols[$vv];
				$attrs = explode(substr($logVol, 0, 1), $logVol);
				array_push($arrayLV, $attrs[1]);
			}
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
			elseif (strncmp($line, 'FreeLVM:', 8) == 0)
				$this->handleFreeLVM(substr($line, 8));
			elseif (strncmp($line, 'MarkedLVM:', 10) == 0)
				$this->handleMarkedLVM(substr($line, 10));
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
			$rc = $this->session->readFileFromPlugin('waitforpartinfo.txt', false);
			$text = $this->session->configuration->getValue('diskinfo.txt_wait_for_partinfo');
			$rc = str_replace('###txt_wait_for_partinfo###', $text, $rc);
		}
		return $rc;
	}
	/** Adapts the partition/label lists respecting the mountpoints.
	 *
	 * The partitions belonging yet to a mountpoint will not appear in the selection lists.
	 */
	function respectMountPoints(){
		$page = $this->page;
		$count = $page->getRowCount('mounts');
		$value = $this->session->userData->getValue('mountpoint', 'opt_add_label');
		$labels = explode(';', $value);
		$value = $this->session->userData->getValue('mountpoint', 'opt_add_dev');
		$devices = explode(';', $value);
		for ($ix = 0; $ix < $count; $ix++){
			$line = $page->getRow('mounts', $ix);
			$cols = explode('|', $line);
			$dev = $cols[0];
			$label = $this->getPartitionLabel($dev);
			$ix2 = $this->session->findIndex($labels, $label);
			if ($ix2 >= 0)
				unset($labels[$ix2]);
			$ix2 = $this->session->findIndex($devices, $dev);
			if ($ix2 >= 0)
				unset($devices[$ix2]);
		}
		$value = implode(';', $labels);
		$this->session->userData->setValue('mountpoint', 'opt_add_label', $value);
		$value = implode(';', $devices);
		$this->session->userData->setValue('mountpoint', 'opt_add_dev', $value);
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