'''
Created on 03.02.2013

@author: hm
'''
from webbasic.sessionbase import SessionBase

from util.util import Util

class Session(SessionBase):
    '''
    classdocs
    '''

    def __init__(self, request, homeDir = None):
        '''
        Constructor.
        @param request: the HTTP request info
        '''
        super(Session, self).__init__(request,
            ['de', 'en', 'ro'], 
            'sidu-disk-center', homeDir)
    
    def getTemplate(self, node):
        '''Gets a template file into a string.
        @param: node: the file's name without path
        @return: the content of the template file
        '''
        fn = self._homeDir + 'templates/' + node
        rc = Util.readFileAsString(fn)
        return rc
   

