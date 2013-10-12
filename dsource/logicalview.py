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

    def buildLVInfo(self):
        '''Builds the info table(s) for logical volumes.
        @return: the HTML text with the info
        '''
        body = ""
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
            content = self._snippets.get("DEL_LV")
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
    
    def handleButton(self, button):
        '''Do the actions after a button has been pushed.
        @param button: the name of the pushed button
        @return: None: OK<br>
                otherwise: a redirect info (PageResult)
        '''
        pageResult = None
        if button == "button_create_lv":
            pass
        elif button == "button_action":
            pass
        elif button == "button_reload":
            pass
        elif button == "button_del_lv":
            pass
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
    