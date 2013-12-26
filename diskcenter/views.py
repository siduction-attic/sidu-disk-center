# Create your views here.
from django.http import HttpResponse, HttpResponsePermanentRedirect
from dsource.session import Session
from dsource.homepage import HomePage
from dsource.globalpage import GlobalPage
from dsource.lvmpage import LVMPage
from dsource.overview import OverviewPage
from dsource.physicalview import PhysicalViewPage
from dsource.logicalview import LogicalViewPage
from dsource.snapshots import SnapshotsPage
from webbasic.waitpage import WaitPage
from util.util import Util

def getSession(request):
    homeDir = request.documentRoot if hasattr(request, "documentRoot") else None
        
    session = Session(request, homeDir)
    return session

def getFields(request):
    fields = request.GET
    if len(fields) < len(request.POST):
        fields = request.POST
    return fields
    

def handlePage(page, request, session):
    page._globalPage = GlobalPage(session, request.COOKIES)
    
    fields = getFields(request)
    
    pageResult = page.handle('', fields, request.COOKIES)
    if pageResult._body != None:
        body = page.replaceInPageFrame(pageResult._body)
        rc = HttpResponse(body)
    else:
        url = pageResult._url
        session.trace(u'redirect to {:s} [{:s}]'.format(
            Util.toUnicode(url), pageResult._caller))
        absUrl = session.buildAbsUrl(url)
        rc = HttpResponsePermanentRedirect(absUrl) 
    cookies =  request.COOKIES
    for cookie in cookies:
        rc.set_cookie(cookie, cookies[cookie])
    return rc
    
def index(request):
    session = getSession(request)
    absUrl = session.buildAbsUrl('/home')
    rc = HttpResponsePermanentRedirect(absUrl) 
    return rc

def home(request):
    session = getSession(request)
    rc = handlePage(HomePage(session), request, session)
    return rc

def overview(request):
    session = getSession(request)
    rc = handlePage(OverviewPage(session), request, session)
    return rc

def lvm(request):
    session = getSession(request)
    rc = handlePage(LVMPage(session), request, session)
    return rc

def physicalView(request):
    session = getSession(request)
    rc = handlePage(PhysicalViewPage(session), request, session)
    return rc

def logicalView(request):
    session = getSession(request)
    rc = handlePage(LogicalViewPage(session), request, session)
    return rc

def snapshots(request):
    session = getSession(request)
    rc = handlePage(SnapshotsPage(session), request, session)
    return rc

def wait(request):
    session = getSession(request)
    rc = handlePage(WaitPage(session), request, session)
    return rc

