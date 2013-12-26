'''
Created on 10.03.2013

@author: hm
'''

from webbasic.page import Page
from basic.shellclient import SVOPT_BACKGROUND

class HomePage(Page):
    '''
    Handles the search page
    '''


    def __init__(self, session):
        '''
        Constructor.
        @param session: the session info
        '''
        Page.__init__(self, "home", session)
        self._searchResults = None

    def afterInit(self):
        '''Will be called when the object is fully initialized.
        Does some preloads: time consuming tasks will be done now,
        while the user reads the introductions.
        '''
        preloaded = self.getField("preloaded")
        if preloaded != "Y":
            value = self._session.getConfigOrNoneWithoutLanguage("preload.count")
            count = 0 if value == None else int(value)
            for ix in xrange(count):
                value = self._session.getConfigWithoutLanguage("preload." + str(ix))
                cols = self.autoSplit(value)
                if len(cols) < 2:
                    self._session.error("wrong preload [ix]:" + value)
                    cols = [ "echo", "error", value ]
                answer = cols[0]
                command = cols[1]
                param = "" if len(cols) <= 2 else cols[2]
                if param.find("|") >= 0:
                    param = param.split(r'\|')
                opt = ''    
                if command.startswith("&"):
                    opt = SVOPT_BACKGROUND
                    command = command[1:]
                self.execute(answer, opt, command, param, 0)
            self.putField("preloaded", "y")
        
    def defineFields(self):
        '''Defines the fields of the page.
        This allows a generic handling of the fields.
        '''
        self.addField("preloaded", "No")

    def changeContent(self, body):
        '''Changes the template in a customized way.
        @param body: the HTML code of the page
        @return: the modified body
        '''
        return body
    
    def handleButton(self, button):
        '''Do the actions after a button has been pushed.
        @param button: the name of the pushed button
        @return: None: OK<br>
                otherwise: a redirect info (PageResult)
        '''
        pageResult = None
        if button == "button_clear_config":
            pass
        elif button == "button_next":
            pageResult = self._session.redirect(
                self.neighbourOf(self._name, False), 
                "homepage.handleButton")
        else:
            self.buttonError(button)
            
        return pageResult
    