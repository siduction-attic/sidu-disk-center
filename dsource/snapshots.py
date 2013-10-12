'''
Created on 10.03.2013

@author: hm
'''

import os.path
from webbasic.page import Page
from dsource.diskinfopage import DiskInfoPage
from basic.shellclient import SVOPT_BACKGROUND

class SnapshotsPage(Page):
    '''
    Handles the search page
    '''


    def __init__(self, session):
        '''
        Constructor.
        @param session: the session info
        '''
        Page.__init__(self, "snapshots", session)
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
        self.addField("create_snap_lv")
        self.addField("create_snap_base_lv")
        self.addField("create_snap_access")
        self.addField("create_snap_size")
        self.addField("create_snap_unit")
        # hidden fields:
        self.addField("answer")
        

    def changeContent(self, body):
        '''Changes the template in a customized way.
        @param body: the HTML code of the page
        @return: the modified body
        '''
        body = self.fillStaticSelected("action", body)
        action = self.getField("action")
        content = ""
        texts = self._diskInfo.getVolumeGroups()
        body = self.fillDynamicSelected("volume_group", texts, None, body)
        if action == "create_sn":
            content = self._snippets.get("CREATE_SNAP")
            content = self.fillStaticSelected("create_snap_access", content)
            content = self.fillStaticSelected("create_snap_unit", content)
        elif action == "delete_sn":
            content = self._snippets.get("DEL_SNAP")
            pass
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
        return body
    
    def handleButton(self, button):
        '''Do the actions after a button has been pushed.
        @param button: the name of the pushed button
        @return: None: OK<br>
                otherwise: a redirect info (PageResult)
        '''
        pageResult = None
        if button == "button_activate":
            pass
        elif button == "button_create_snap":
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
    