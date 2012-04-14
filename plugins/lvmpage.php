<?php
include "plugins/diskinfo.php";
/**
 * Offers the possability to change the partition scheme.
 * Implements a plugin.
 * 
 * @author hm
 */
class LvmPage extends Page{
	/// an instance of DiskInfo
	var $diskInfo;
	/// name of the file containing the partition info
	var $filePartInfo;
	/** Constructor.
	 * 
	 * @param $session
	 */
	function __construct(&$session){
		parent::__construct($session, 'lvm');

		//$this->setDefaultOption('disk', 0, true);
		
		$value = $this->getUserData('reload.partinfo');
		$forceRebuild = ! empty($value);
		if ($forceRebuild)
			$this->setUserData('reload.partinfo', '');
		$this->diskInfo = new DiskInfo($session, $this, $forceRebuild);
			
	}
	/** Builds the core content of the page.
	 * 
	 * Overwrites the method in the baseclass.
	 */
	function build(){
		$this->session->trace(TRACE_RARE, 'lvm.build()');
		$this->readContentTemplate();
	}
	/** Returns an array containing the input field names.
	 * 
	 * @return an array with the field names
	 */
	function getInputFields(){
		//$rc = array('disk', 'partman', 'disk2');
		$rc = null;
		return $rc;
	}
	/** Will be called on a button click.
	 * 
	 * @param $button	the name of the button
	 * @return false: a redirection will be done. true: the current page will be redrawn
	 */
	function onButtonClick($button){
		$redraw = true;
		$this->session->trace(TRACE_RARE, "onButton($button)");
		if (strcmp($button, 'button_refresh') == 0){
			$this->diskInfo->buildInfoTable();
		} elseif (strcmp($button, 'button_reload') == 0){
			$this->diskInfo->forceReload();
			$this->setUserData('reload.partinfo', 'T');
		} elseif (strcmp($button, 'button_exec') == 0){
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