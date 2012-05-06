<?php
include "plugins/diskinfo.php";

define('LAST_ANSWER', 'last.answer');

/**
 * Offers the possability to change the partition scheme.
 * Implements a plugin.
 *
 * @author hm
 */
class PhysicalviewPage extends Page{
	/// an instance of DiskInfo
	var $diskInfo;
	/// name of the file containing the partition info
	var $filePartInfo;
	/** Constructor.
	 *
	 * @param $session
	 */
	function __construct(&$session){
		parent::__construct($session, 'physicalview');

		//$this->setDefaultOption('disk', 0, true);

		$value = $this->getUserData('reload.partinfo');
		$forceRebuild = ! empty($value);
		if ($forceRebuild)
			$this->setUserData('reload.partinfo', '');
		$this->diskInfo = new DiskInfo($session, $this, $forceRebuild);
		$this->setDefaultOption('action', 0, true);
		$this->setDefaultOption('create_vg_ext_unit', 1, true);
	}
	/**
	 * Builds the part of the page which allow the user to do the selected action.
	 */
	function buildActionPart(){
		$ix = $this->indexOfSelectionField('physicalview', 'action', null, 'opt_action');
		switch($ix){
		case 0: // create PV
			$this->replacePartWithTemplate('ACTION', 'CREATE_PV');
			break;
		case 1: // assign PV
			$this->replacePartWithTemplate('ACTION', 'ASSIGN');
			break;
		case 2: // create VG
		default:
			$this->replacePartWithTemplate('ACTION', 'CREATE_VG');
			break;
		}
	}
	/**
	 * Builds the info about the PVs
	 */
	function buildPhysicalViewTable(){
		$this->diskInfo->buildInfoTable();

		$headers = $this->i18n('txt_headers', '|Name:|Size:');

		$tables = '';
		$rows = $this->diskInfo->getPVInfo();
		if (empty($rows)){
			$tables = $this->i18n('txt_no_volume_groups', null);
		} else {
			$vgArray = explode(substr($rows, 0, 1), $rows);
			for ($gg = 1; $gg < count($vgArray); $gg++){
				$vgInfo = $vgArray[$gg];
				$vg = explode(substr($vgInfo, 0, 1), $vgInfo, 3);
				$table = $this->buildTable($headers, $vgInfo, 'PV', 2);
				$title = $this->i18n('txt_title_volume_group', null);
				$title = str_replace('###VG_NAME###', $vg[1], $title);
				$table = str_replace('###txt_title_volume_group###', $title, $table);
				$info = $this->diskInfo->getVGInfo($vg[1]);
				$table = str_replace('###descr_vg###', $info, $table);
				$tables .= $table;
			}
		}
		$rows = $this->diskInfo->getFreePVInfo();
		if (! empty($rows)){
			$title = $this->i18n('txt_title_free_pv', null);
			$table = $this->buildTable($headers, $rows, 'PV', 1);
			$table = str_replace('###descr_vg###', '', $table);
			$table = str_replace('###txt_title_volume_group###', $title, $table);
			$tables .= $table;
		}
		$rows = $this->diskInfo->getUninitializedPVInfo();
		if (! empty($rows)){
			$title = $this->i18n('txt_title_unititialized_pv', null);
			$table = $this->buildTable($headers, $rows, 'PV', 1);
			$table = str_replace('###descr_vg###', '', $table);
			$table = str_replace('###txt_title_volume_group###', $title, $table);
			$tables .= $table;
		}
		if (! empty($tables))
			$this->content = str_replace('###PART_TABLES###', $tables, $this->content);

	}
	/** Builds the core content of the page.
	 *
	 * Overwrites the method in the baseclass.
	 */
	function build(){
		$this->session->trace(TRACE_RARE, 'physicalview.build()');
		$this->readContentTemplate();
		$this->readHtmlTemplates();
		$this->fillOptions('action');

		$text = $this->diskInfo->getWaitForPartitionMessage();
		if (empty($text)){
			$this->replacePartWithTemplate('PV_INFO');
			$this->buildPhysicalViewTable();

		} else {
			$this->replacePartWithTemplate('PV_INFO', 'WAIT_FOR_PARTINFO');
			$this->content = str_replace('###txt_no_info###', $text, $this->content);
		}

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
		$this->fillOptions('action', false);
		$this->fillOptions('assign_pv_pv', true);
		$this->fillOptions('assign_pv_vg', true);
		$this->fillOptions('create_pv_pv', true);
		$this->fillOptions('create_vg_pv', true);
		$this->fillOptions('create_vg_ext_unit', false);

	}
	/** Returns an array containing the input field names.
	 *
	 * @return an array with the field names
	 */
	function getInputFields(){
		$rc = array('action', 'assign_pv_pv', 'assign_pv_vg', 'create_pv_pv',
			'create_vg_vg', 'create_vg_pv', 'create_vg_ext_size', 'create_vg_ext_unit');
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
	/** Will be called on a button click.
	 *
	 * @param $button	the name of the button
	 * @return false: a redirection will be done. true: the current page will be redrawn
	 */
	function onButtonClick($button){
		$redraw = true;
		$this->session->trace(TRACE_RARE, "onButton($button)");
		$params = array();
		if (strcmp($button, 'button_action') == 0){
			$this->setUserData('error_msg', '');
		} elseif (strcmp($button, 'button_reload') == 0){
			$this->diskInfo->forceReload();
			$this->setUserData('reload.partinfo', 'T');
			$this->session->gotoPage('physicalview', 'pyhsicalview.onButtonClick');
		} elseif (strcmp($button, 'button_create_pv') == 0){
			$pv = $this->getUserData('create_pv_pv');
			if (empty($pv))
				$this->setErrorMessage($this->i18n('txt_choose_pv'));
			else
			{
				array_push($params, 'pvcreate');
				array_push($params, $this->getUserData('create_pv_pv'));
				$this->work($params);
			}
		} elseif (strcmp($button, 'button_assign_pv') == 0){
			array_push($params, 'vgextend');
			array_push($params, $this->getUserData('assign_pv_vg'));
			array_push($params, $this->getUserData('assign_pv_pv'));
			$this->work($params);
		} elseif (strcmp($button, 'button_create_vg') == 0){
			$pv = $this->getUserData('create_vg_pv');
			if (empty($pv))
				$this->setErrorMessage($this->i18n('txt_choose_pv'));
			else if ($this->isValidContent('create_vg_vg', 'a-zA-Z_.', '.a-zA-Z0-9_.$', true, true)
					&& $this->isValidContent('create_vg_ext_size', '1-8', '0-9', false, true)){
				array_push($params, 'vgcreate');
				$unit = substr($this->getUserData('create_vg_ext_unit'), 0, 1);
				$value = $this->getUserData('create_vg_ext_size');
				if (empty($value))
					$value = 0;
				else
					$value = intval($value);
				if ($value == 0){
					$value = intval($this->diskInfo->getPVSize($pv) / 1024);
					$unit = 'K';
				}
				$value = $this->session->roundDownToPowerOf2($value);
				array_push($params, '--physicalextentsize');
				array_push($params, "$value$unit");
				array_push($params, $this->getUserData('create_vg_vg'));
				array_push($params, $pv);
				$this->work($params);
			}
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