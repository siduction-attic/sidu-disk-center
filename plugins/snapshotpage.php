<?php
include "plugins/diskinfo.php";

define('LAST_ANSWER', 'last.answer');

/**
 * Offers the possability to change the partition scheme.
 * Implements a plugin.
 *
 * @author hm
 */
class SnapshotPage extends Page{
	/// an instance of DiskInfo
	var $diskInfo;
	/// name of the file containing the partition info
	var $filePartInfo;
	/** Constructor.
	 *
	 * @param $session
	 */
	function __construct(&$session){
		parent::__construct($session, 'snapshot');

		$value = $this->getUserData('reload.partinfo');
		$forceRebuild = ! empty($value);
		if ($forceRebuild)
			$this->setUserData('reload.partinfo', '');
		$this->diskInfo = new DiskInfo($session, $this, $forceRebuild);
		$this->setDefaultOption('action', 0, true);
	}
	/**
	 * Builds the part of the page which allow the user to do the selected action.
	 */
	function buildActionPart(){
		$ix = $this->indexOfSelectionField('snapshot', 'action', null, 'opt_action');
		switch($ix){
		case 0:
			$this->replacePartWithTemplate('ACTION', 'CREATE_SNAP');
			$currentVG = $this->session->getField('volume_group');
			if (empty($currentVG))
				$currentVG = $this->getUserData('volume_group');
			$list = $this->diskInfo->getLVsOfVG($currentVG);
			$this->session->trace(TRACE_RARE, "buildActionPart: VG: $currentVG List: $list");
			$this->setUserData('opt_create_snap_base_lv', $list);
			break;
		case 1:
			$this->replacePartWithTemplate('ACTION', 'MOVE_LV');
			break;
		default:
			break;
		}
	}
	/** Builds the core content of the page.
	 *
	 * Overwrites the method in the baseclass.
	 */
	function build(){
		$this->session->trace(TRACE_RARE, 'snapshot.build()');
		$this->readContentTemplate();
		$this->readHtmlTemplates();
		$this->fillOptions('action');

		$this->diskInfo->buildInfoTable();
		$text = $this->diskInfo->getWaitForPartitionMessage();
		$this->content = str_replace('###WAIT_FOR_PARTINFO###', $text,
			$this->content);
		$text = $this->diskInfo->getWaitForPartitionMessage();
		$this->content = str_replace('###WAIT_FOR_PARTINFO###', $text,
			$this->content);

		$headers = $this->i18n('txt_headers', '|Name:|Size:');

		$tables = '';
		$rows = $this->diskInfo->getSnapshotInfo();
		if (empty($rows)){
			$tables = $this->i18n('txt_no_volume_groups', null);
		} else {
			$vgArray = explode(substr($rows, 0, 1), $rows);
			for ($gg = 1; $gg < count($vgArray); $gg++){
				$vgInfo = $vgArray[$gg];
				$vg = explode(substr($vgInfo, 0, 1), $vgInfo, 3);
				$table = $this->buildTable($headers, $vgInfo, 'SNAP', 2);
				$title = $this->i18n('txt_title_volume_group', null);
				$title = str_replace('###VG_NAME###', $vg[1], $title);
				$table = str_replace('###txt_title_volume_group###', $title, $table);
				$info = $this->diskInfo->getVGInfo($vg[1]);
				$table = str_replace('###descr_vg###', $info, $table);
				$tables .= $table;
			}
		}
				if (! empty($tables))
			$this->content = str_replace('###PART_TABLES###', $tables, $this->content);
		$this->buildActionPart();

		$this->setFieldsFromUserData();
		$answer = $this->getUserData(LAST_ANSWER);
		$log = '';
		if (! empty($answer) && file_exists($answer)){
			$body = $this->parts['LAST_LOG'];
			$log = $this->session->readFile($answer);
			$log =  htmlentities($log, ENT_NOQUOTES, $this->session->charset);
			$log = str_replace('###TEXT###', $log, $body);
		}
		$this->replaceMarker('LAST_LOG', $log);

		# The conditional html text must be put before into the $this->content
		$this->fillOptions('create_snap_unit', false);
		$this->fillOptions('create_snap_vg', true);
		$this->fillOptions('volume_group', true);
		$this->fillOptions('create_snap_base_lv', true);
		$this->fillOptions('create_snap_access', false);
	}
	/** Returns an array containing the input field names.
	 *
	 * @return an array with the field names
	 */
	function getInputFields(){
		$rc = array('activation', 'volume_group', 'create_snap_lv',
				'create_snap_size', 'create_snap_unit', 'create_snap_access',
				'create_snap_base_lv');
		return $rc;
	}
	/**
	 * Executes the external command.
	 *
	 * @param $params		parameter of the sdc_lvm command
	 */
	function work($params){
		$answer = $this->getUserData('last.answer');
		if (empty($answer)){
			$answer = $this->session->getAnswerFileName('pv', '.ready');
			$this->setUserData(LAST_ANSWER, $answer);
		}
		$program = 'sdc_lvm';
		$progress = null;
		$this->session->exec($answer, SVOPT_DEFAULT, $program, $params, 0);
		$description = $this->i18n('desc_wait_for_command');
		$cmd = join(' ', $params);
		$description = str_replace('###COMMAND###', $cmd, $description);
		$this->diskInfo->forceReload();
		$this->setUserData('reload.partinfo', 'T');
		$redraw = $this->startWait($answer, $program, $description, $progress);

	}
	/**
	 * Handles the button "create logical volume".
	 */
	function createSnapshot(){
		$name = $this->getUserData('create_snap_lv');
		$vg = $this->session->getField('volume_group');
		$baseLV = $this->session->getField('create_snap_base_lv');
		if (empty($vg))
			$vg = $this->getUserData('volume_group');
		if (empty($name))
			$this->setErrorMessage($this->i18n('txt_choose_snap'));
		elseif (empty($vg))
			$this->setErrorMessage($this->i18n('txt_choose_vg'));
		elseif (empty($baseLV))
			$this->setErrorMessage($this->i18n('txt_choose_base_lv'));
		else
		{
			$access = $this->session->getField('create_snap_access');
			$ix = $this->indexOfSelectionField('snapshot', 'create_snap_access', null, 'opt_create_snap_access');
			$access = $ix == 0 ? 'rw' : 'r';
			$unit = $this->indexOfList('snapshot', 'create_snap_unit', null, 'opt_create_snap_unit');
			$size = $this->getUserData('create_snap_size');
			if ($this->isValidContent('create_snap_lv', '-a-zA-Z1-9_.$@%&!=#', '-a-zA-Z1-9_.$@%&!=#', true, true)
				&& ($access == 'r' || $this->isValidContent('create_snap_size', '1-9', '0-9', true, true))){
				if ($size < 1)
					$this->setErrorMessage($this->i18n('txt_not_null'));
				elseif ($unit == 0 && $size > 100)
					$this->setErrorMessage($this->i18n('txt_100_percent'));
				else {
					$params = array();
					array_push($params, 'sncreate');
					array_push($params, '-s');
					array_push($params, '-p' . $access);
					if ($access == 'r' && empty($size)){
						$size = '2';
							// MiByte:
						$unit = 2;
					}

					switch($unit){
					case 0: // % of rest
						array_push($params, '-l');
						array_push($params, $size . '%ORIGIN');
						break;
					case 1: // % of rest
						array_push($params, '-l');
						array_push($params, $size . '%FREE');
						break;
					case 2: # MiByte
						array_push($params, '-L');
						array_push($params, $size . 'M');
						break;
					case 3: # GiByte
						array_push($params, '-L');
						array_push($params, $size . 'G');
						break;
					default:
						break;
					}
					array_push($params, '-n' . $name);
					# array_push($params, $name);
					array_push($params, "/dev/$vg/$baseLV");
					$this->work($params);
				}
			}
		}
	}
	/** Will be called on a button click.
	 *
	 * @param $button	the name of the button
	 * @return false: a redirection will be done. true: the current page will be redrawn
	 */
	function onButtonClick($button){
		$redraw = true;
		$this->session->trace(TRACE_RARE, "onButton($button)");
		$params = array();
		if (strcmp($button, 'button_activate') == 0){
			$this->setUserData('error_msg', '');
		} elseif (strcmp($button, 'button_reload') == 0){
			$this->diskInfo->forceReload();
			$this->setUserData('reload.partinfo', 'T');
			$this->session->gotoPage('snapshot', 'snapshot.onButtonClick');
		} elseif (strcmp($button, 'button_create_snap') == 0){
			$this->createSnapshot();
		} elseif (strcmp($button, 'button_next') == 0){
			$redraw = $this->navigation(false);
		} elseif (strcmp($button, 'button_prev') == 0){
			$redraw = $this->navigation(true);
		} else {
			$this->session->log("unknown button: $button");
		}
		return $redraw;
	}
}
?>