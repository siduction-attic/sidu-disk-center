'''
Created on 10.03.2013

@author: hm
'''

from webbasic.page import Page
from basic.shellclient import SVOPT_BACKGROUND

class LVMPage(Page):
    '''
    Handles the search page
    '''


    def __init__(self, session):
        '''
        Constructor.
        @param session: the session info
        '''
        Page.__init__(self, "lvm", session)

    def defineFields(self):
        '''Defines the fields of the page.
        This allows a generic handling of the fields.
        '''
        pass

    def changeContent(self, body):
        '''Changes the template in a customized way.
        @param body: the HTML code of the page
        @return: the modified body
        '''
        body = body.replace("###URL_DYNAMIC###", "")
        return body
    
    def handleButton(self, button):
        '''Do the actions after a button has been pushed.
        @param button: the name of the pushed button
        @return: None: OK<br>
                otherwise: a redirect info (PageResult)
        '''
        pageResult = None
        if button == "button_next":
            pageResult = self._session.redirect(
                self.neighbourOf(self._name, False), 
                "lvm.handleButton")
        elif button == "button_prev":
            pageResult = self._session.redirect(
                self.neighbourOf(self._name, True), 
                "lvm.handleButton")
        else:
            self.buttonError(button)
            
        return pageResult
    