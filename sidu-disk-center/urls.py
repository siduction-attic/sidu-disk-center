from djinn.django.conf.urls import patterns, url

from diskcenter import views

def getPatterns():
    rc = patterns('',
        url(r'^$', views.home, name='root'),
        url(r'^home', views.home, name='home'),
        url(r'^lvm', views.lvm, name='lvm'),
        url(r'^overview', views.overview, name='overview'),
        url(r'^physicalview', views.physicalView, name='physicalview'),
        url(r'^logicalview', views.logicalView, name='logicalview'),
        url(r'^snapshots', views.snapshots, name='snapshots'),
        url(r'^wait', views.wait, name='wait')
        )
    return rc
                  

urlpatterns = getPatterns()
