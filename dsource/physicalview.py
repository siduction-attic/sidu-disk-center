'''
Created on 10.03.2013

@author: hm
'''
import os.path
from webbasic.page import Page
from dsource.diskinfopage import DiskInfoPage
from basic.shellclient import SVOPT_BACKGROUND

class PhysicalViewPage(Page):
    '''
    Handles the search page
    '''


    def __init__(self, session):
        '''
        Constructor.
        @param session: the session info
        '''
        Page.__init__(self, "physicalview", session)
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
        self.addField("create_pv_pv")
        self.addField("assign_pv_pv")
        self.addField("assign_pv_vg")
        self.addField("create_vg_vg")
        self.addField("create_vg_pv")
        self.addField("create_vg_ext_size")
        self.addField("create_vg_ext_unit")
        self.addField("del_lv_lv")
        # hidden fields:
        self.addField("answer", None, 0)
        
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
            if ixRow > 0:
                rc = self._tableRows[ixRow-1]
                # rc[0] = rc[0].replace("/dev/", "")
            else:
                key = ("physicalview.txt_headers" if mode == "2cols" 
                       else "physicalview.txt_vg_headers")
                header = self._session.getConfig(key)
                rc = self.autoSplit(header)
        elif what == "rows":
            rc = 1 + len(self._tableRows)
        return rc

    def buildPVInfo(self):
        '''Builds the info table(s) for logical volumes.
        @return: the HTML text with the info
        '''
        body = self._snippets.get("PV_INFO")
        
        gvs = self._diskInfo._lvmVGs
        if len(gvs) == 0:
            content = self._snippets.get("PV_NO_GV")
        else:
            content = ""
            template = self._snippets.get("PV_TABLE")
            #title = self._session.getConfig("physicalview.txt_title_volume_group")
            self._tableRows = []
            for gv in gvs:
                (name, size) = gv.split(":")
                row = [name, self.humanReadableSize(1024 * 1024 * int(size))]
                self._tableRows.append(row)
            content2 = self.buildTable(self, "2cols")
            content = template.replace("{{TABLE}}", content2)
            
        body = body.replace("{{INFO_VG}}", content)
        
        self._tableRows = self._diskInfo.getFreePV(True)
        content = self.buildTable(self, "2cols")
        part = self._snippets.get("INFO_FREE")
        part = part.replace("{{TABLE_OR_MSG}}", content)
        body = body.replace("{{INFO_FREE}}", part)

        part = self._snippets.get("INFO_NOT_INIT")
        self._tableRows = self._diskInfo.getMarkedPV(True)
        content = self.buildTable(self, "2cols")
        part = part.replace("{{TABLE_OR_MSG}}", content)
        body = body.replace("{{INFO_NOT_INIT}}", part)
       
        return body
    
   
    def changeContent(self, body):
        '''Changes the template in a customized way.
        @param body: the HTML code of the page
        @return: the modified body
        '''
        body = self.fillStaticSelected("action", body)
        action = self.getField("action")
        content = ""
        if action == "create_pv":
            content = self._snippets.get("CREATE_PV")
            texts = self._diskInfo.getMarkedPV()
            content = self.fillDynamicSelected("create_pv_pv", texts, None, content)
        elif action == "assign":
            content = self._snippets.get("ASSIGN")
            texts = self._diskInfo.getFreePV()
            content = self.fillDynamicSelected("assign_pv_pv", texts, None, content)
            texts = self._diskInfo.getVolumeGroups()
            content = self.fillDynamicSelected("assign_pv_vg", texts, None, content)
            pass
        elif action == "create_vg":
            content = self._snippets.get("CREATE_VG")
            texts = self._diskInfo.getVolumeGroups()
            content = self.fillDynamicSelected("create_vg_vg", texts, None, content)
            texts = self._diskInfo.getFreePV()
            content = self.fillDynamicSelected("create_vg_pv", texts, None, content)
            content = self.fillStaticSelected("create_vg_ext_unit", content)
            pass
        else:
            self._session.error("unknown action")
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
        if not self._diskInfo._hasInfo:
            content = self._snippets.get("WAIT_FOR_PARTINFO")
        else:
            content = self.buildPVInfo()
        body = body.replace("{{PV_INFO}}", content)
        return body

    def roundDownToPowerOf2(self, value):
        '''Returns the highest number which is a power of 2.
        @param value: the value to convert
        @return: the highest number below value which is a power of 2
        val = "{:%x}".format(value)
        '''
        if value == None or value == 0:
            rc = 0
        else:
            value = "{:x}".format(value)
            first = value[0]
            if first > "8":
                first = "8"
            elif first > "4":
                first = "4"
            if first == "3":
                first = "2"
            value = first
            for ii in xrange(len(value) - 1):
                value += "0"
            rc = int(value, 16)
        return rc
    
    def work(self, params, doReload = True):
        '''Executes the sdc_lvm command.
        @param params:      parameter of the sdc_lvm command
        @param doReload:    True: the reload of the partition info will be done
        '''
        answer = self.getField('answer');
        if answer == None or answer == "":
            answer = self._session._shellClient.buildFileName("pv", ".ready")
            self.putField("answer", answer)
        program = "sdc_lvm"
        #params.insert(0, answer)
        options = SVOPT_BACKGROUND
        self.execute(answer, options, program, params, 0)
        prog = " ".join(params)
        rc = self.gotoWait("physicalview", answer, None, None, [prog])
        if doReload:
            self._diskInfo.reload()
        return rc

    def createVG(self):
        '''Build the dialog part for creating a volume group.
        '''
        pv = self.getField("create_vg_pv")
        if pv == None or pv == "":
            self._setErrorMessage(self._i18n("txt_choose_pv"))
        elif (self.isValidContent("create_vg_vg", "a-zA-Z_.", ".a-zA-Z0-9_.", 
                    True, True)
                and self.isValidContent("create_vg_ext_size", "1-8", "0-9", 
                    False, True)):
            params = ["vgcreate"]
            unit = self.getField("create_vg_ext_unit")
            unit = "M" if unit == None or unit == "" else unit[0]
            value = self.getField("create_vg_ext_size")
            if value == None or value == "":
                value = 0
            else:
                value = int(value)
            if value == 0:
                # intval(self._diskInfo.getPVSize(pv) / 1024)
                value = 32768
                unit = "K"
            value = str(self.roundDownToPowerOf2(value))
            self.putField("create_vg_ext_size", value)
            params.append("--physicalextentsize")
            params.append(value + unit)
            params.append(self.getField("create_vg_vg"))
            params.append("/dev/" + pv)
            self.work(params)

    def handleButton(self, button):
        '''Do the actions after a button has been pushed.
        @param button: the name of the pushed button
        @return: None: OK<br>
                otherwise: a redirect info (PageResult)
        '''
        pageResult = None
        if button == "button_action":
            pass
        elif button == "button_reload":
            self._diskInfo.reload()
        elif button == "button_create_pv":
            dev = self.getField("create_pv_pv")
            if dev != "":
                params = ["pvcreate", "/dev/" + dev]
                pageResult = self.work(params)
        elif button == "button_create_vg":
            self.createVG()
        elif button == "button_next":
            pageResult = self._session.redirect(
                self.neighbourOf(self._name, False), 
                "physicalview.handleButton")
        elif button == "button_prev":
            pageResult = self._session.redirect(
                self.neighbourOf(self._name, True), 
                "physicalview.handleButton")
        else:
            self.buttonError(button)
            
        return pageResult
    