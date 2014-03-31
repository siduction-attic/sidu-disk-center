'''
Created on 10.03.2013

@author: hm
'''

import os.path
from webbasic.page import Page
from dsource.diskinfopage import DiskInfoPage
from basic.shellclient import SVOPT_BACKGROUND

class LogicalViewPage(Page):
    '''
    Handles the search page
    '''


    def __init__(self, session):
        '''
        Constructor.
        @param session: the session info
        '''
        Page.__init__(self, "logicalview", session)
        self._diskInfo = DiskInfoPage(self)

    def afterInit(self):
        '''Will be called when the object is fully initialized.
        Does some preloads: time consuming tasks will be done now,
        while the user reads the introductions.
        '''
        pass
    
    def defineFields(self):
        '''Defines the fields of the page.
        This allows a generic handling of the fields.
        '''
        self.addField("action", None, 0)
        self.addField("volume_group")
        self.addField("create_lv_lv")
        self.addField("create_lv_size")
        self.addField("create_lv_unit")
        self.addField("create_lv_fs")
        self.addField("create_lv_label")
        self.addField("del_lv_lv")
        # hidden fields:
        self.addField("answer")

    def buildPartOfTable(self, mode, what, ixRow = None):
        '''
        @param mode:  distincts the different tables to build
        @param what:  names a part of the table which will be returned
                      "Table": None or html template of table with "{{ROWS}}"
                      "Row:" None or a template with "{{COLS}}"
                      "Col": None or a template with "{{COL}}"
                      "rows": number of rows. Data type: int
                      "cols": list of column values (data type: Object)
        @param ixRow: index of the row (only relevant if what == "cols") 
        @return:      the wanted part of the table
        '''
        rc = None
        if what == "cols":
            rc = self._tableRows[ixRow]
        elif what == "rows":
            rc = len(self._tableRows)
        return rc

    def buildLVInfo(self):
        '''Builds the info table(s) for logical volumes.
        @return: the HTML text with the info
        '''
        body = self._snippets.get("LV_INFO")
        self._tableRows = []
        for item in self._diskInfo._partitionList:
            if item._device.find("/") > 0:
                cols = (item._device, item._size, item._label, 
                    "<xml>" + item._info)
                self._tableRows.append(cols)
        content = self.buildTable(self, None)
        body = body.replace("{{TABLE}}", content)
        return body
    
    def changeContent(self, body):
        '''Changes the template in a customized way.
        @param body: the HTML code of the page
        @return: the modified body
        '''
        body = self.fillStaticSelected("action", body)
        action = self.getField("action")
        texts = self._diskInfo.getVolumeGroups()
        body = self.fillDynamicSelected("volume_group", texts, None, body)
        content = ""
        if action == "create_lv":
            content = self._snippets.get("CREATE_LV")
            content = self.fillStaticSelected("create_lv_fs", content)
            content = self.fillStaticSelected("create_lv_unit", content)
        elif action == "delete_lv":
            delCommand = self._session.getUserData(self._name, "del_command")
            if delCommand == None or delCommand == "":
                content = self._snippets.get("DEL_LV")
                vg = self.getField("volume_group")
                lvs = self._diskInfo.getPartitionNamesOfDisk(vg)
                content = self.fillDynamicSelected("del_lv_lv", lvs, lvs, content)
            else:
                content = self._snippets.get("DEL_OUTPUT")
                self._session.setLocalVar("del_command", delCommand)
                self._session.putUserData(self._name, "del_command", "")
        else:
            self._session.error("unknown action: " + action)
        body = body.replace("{{ACTION}}", content)
        answer = self.getField("answer")
        content = ""
        if answer != None and answer != "" and os.path.exists(answer):
            content = self._snippets.get("LAST_LOG")
            fileContent = ""
            with open(answer, "r") as fp:
                for line in fp:
                    fileContent += line
            fp.close()
            content = content.replace("{{FILE}}", fileContent)
        body = body.replace("{{LAST_LOG}}", content)
        content = ""
        if not self._diskInfo._hasInfo:
            content = self._snippets.get("WAIT_FOR_PARTINFO")
        else:
            content = self.buildLVInfo()
        body = body.replace("{{LV_INFO}}", content)
        return body
    
    def work(self, params, doReload = True):
        '''Executes the sdc_lvm command.
        @param params:      parameter of the sdc_lvm command
        @param doReload:    True: the reload of the partition info will be done
        '''
        answer = self.getField('answer');
        if answer == None or answer == "":
            answer = self._session._shellClient.buildFileName("lv", ".ready")
            self.putField("answer", answer)
        program = "sdc_lvm"
        #params.insert(0, answer)
        options = SVOPT_BACKGROUND
        self.execute(answer, options, program, params, 0)
        prog = " ".join(params)
        rc = self.gotoWait("logicalview", answer, None, None, [prog])
        if doReload:
            self._diskInfo.reload()
        return rc

    def createLV(self):
        '''Creates a logical volume.
        '''
        params = ["lvcreate"]
        unit = self.getField("create_lv_unit")
        value = self.getField("create_lv_size")
        if unit.find("%") >= 0:
            params.append("--extents")
            params.append(str(value) + "%FREE")
        else:
            value = self.sizeAndUnitToByte(str(value) + unit) / 1024
            params.append("--size")
            params.append(str(value) + "K")
        params.append("--name")
        params.append(self.getField("create_lv_lv"))    
        params.append(self.getField("volume_group"))
        params.append(self.getField("create_lv_fs"))
        params.append(self.getField("create_lv_label"))
        self.putField("create_lv_lv", None)
        self.putField("create_lv_size", None)
        self.putField("create_lv_label", None)
        self.work(params, True)
        
    def deleteLV(self):
        '''Delete a logical volume.
        '''
        name = self.getField('del_lv_lv');
        if name == "":
            self.putError(None, "logicalview.err_no_lv_selected")
        #elsif self.hasAnySnapshot(name):
        #    self.error
        else:
            command = "lvremove -f " + name
            self._session.putUserData("logicalview", "del_command", command)

    def handleButton(self, button):
        '''Do the actions after a button has been pushed.
        @param button: the name of the pushed button
        @return: None: OK<br>
                otherwise: a redirect info (PageResult)
        '''
        pageResult = None
        if button == "button_create_lv":
            self.createLV()
        elif button == "button_action":
            pass
        elif button == "button_reload":
            pass
        elif button == "button_del_lv":
            self.deleteLV()
        elif button == "button_next":
            pageResult = self._session.redirect(
                self.neighbourOf(self._name, False), 
                "logicalview.handleButton")
        elif button == "button_prev":
            pageResult = self._session.redirect(
                self.neighbourOf(self._name, True), 
                "logicalview.handleButton")
        else:
            self.buttonError(button)
            
        return pageResult
    