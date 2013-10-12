'''
Created on 31.03.2013

@author: hm
'''
import os.path, re
from webbasic.page import Page, PageException
from basic.shellclient import SVOPT_BACKGROUND

class PartitionInfo:
    '''the info of one partition
    '''
    def __init__(self, parent, dev, label, size, size2, ptype, pinfo, fs, info):
        '''Constructor.
        @param parent: an instance of DiskInfo 
        '''
        self._parent = parent
        # e.g. sda1
        self._device = dev
        # the name for sorting:
        matcher = re.search(r'(\d+)', dev)
        if matcher != None:
            no = matcher.group(1)
            dev.replace(no, "{:3s}".format(no))
        self._sortName = dev
        # volume label
        self._label = label
        # partition type, e.g. 8e
        self._partType = ptype
        # partition info, e.g. "Microsoft basic data"
        self._partInfo = pinfo
        # e.g. ext4
        self._filesystem = fs
        # additional info
        self._info = info
        # size in MByte
        self._megabytes = size / 1000
        # size and unit, e.g. 11GB
        self._size = size2
        
    def canBeRoot(self, minSize):
        '''Tests whether the partition can be used as root partition
        @param minSize: minimum size of the partition in MByte
        @return: True: can be used as root partition<br>
                False: otherwise
        '''
        rc = self._megabytes >= minSize
        if rc:
            if self._partInfo == "Microsoft basic data":
                rc = False
            elif self._partInfo == "Linux LVM":
                rc = False    
        return rc

class DiskInfo:
    '''Stores the info about a disk (a container of partitions)
    '''
    def __init__(self, dev, size, info = None):
        '''Constructor.
        @param dev: the device name
        @param size: the size in kiByte
        '''
        self._device = dev
        self._size = size
        self._info = info
        
class DiskInfoPage(Page):
    '''
    Displays or prepares some parts of other pages belonging to disk information.
    '''


    def __init__(self, parentPage):
        '''
        Constructor.
        @param parentPage:      the current "real" page
        @param forceRebuild:    deletes the partition info file to force rebuilding
        '''
        Page.__init__(self, 'diskinfo', parentPage._session)
        
        session = self._session = parentPage._session
        self._hasInfo = False
        self._parentPage = parentPage
        self._parentName = parentPage._name
        self._gptDisks = ''
        self._partitions = {}
        self._partitionList = []
        self._disks = {}
        self._markedLVM = []
        self._physicalVolumes = []
        self._freePV = []
        self._volumeGroups = []
        self._filePartInfo = session.getConfigWithoutLanguage(
                'diskinfo.file.demo.partinfo')
        if self._filePartInfo == "" or not os.path.exists(self._filePartInfo):
            self._filePartInfo = session.getConfigWithoutLanguage(
                    'diskinfo.file.partinfo')
        self._hasInfo = os.path.exists(self._filePartInfo)
        if self._hasInfo:
            self.importPartitionInfo()
        else:
            answer = self._filePartInfo
            options = SVOPT_BACKGROUND
            command = "sdc_partinfo"
            params = None
            session._shellClient.execute(answer, options, command, params, 0)
    
    def reload(self):
        '''The partition info will be requested again.
        '''
        self._session.deleteFile(self._filePartInfo)

        
    def defineFields(self):
        '''Defines the fields of the page.
        This allows a generic handling of the fields.
        '''
        # Nameo of the file containing the disk/partition info:
        self.addField('partinfo')

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
        if button == 'button_next':
            pass
        else:
            self.buttonError(button)
            
        return pageResult

    def forceReload(self):
        '''Forces the reload of the partition info.
        '''
        self._session.deleteFile(self._filePartInfo)

    def importPartitionInfo(self):
        '''Gets the data of the partition info and put it into into the user data.
        '''
        self._session.trace('DiskInfo.importPartitionInfo()')
        excludes = self._session.getConfigWithoutLanguage('diskinfo.excluded.dev')
        rexprExcludes = re.compile(excludes)
        diskList = ''
        with open(self._filePartInfo, "r") as fp:
            no = 0
            for line in fp:
                no += 1
                line = line.strip()
                if line.startswith("!GPT="):
                    self._gptDisks = line[5:]
                elif line.startswith("!VG="):
                    self._lvmVGs = line[4:]
                elif line.startswith("PhLVM:"):
                    self._physicalVolumes = self.autoSplit(line[6:], True)
                elif line.startswith("FreeLVM:"):
                    self._freePV = self.autoSplit(line[8:], True)
                elif line.startswith("MarkedLVM:"):
                    self._markedLVM = self.autoSplit(line[10:], True)
                elif line.startswith("LogLVM:"):
                    pass
                elif line.startswith("VgLVM:"):
                    self._volumeGroups = self.autoSplit(line[6:], True)
                elif line.startswith("!LV="):
                    self._lvmLVs = line[4:]
                    for lvm in self._lvmVGs.split(";"):
                        lvm += "/"
                        self._disks[lvm] = DiskInfo(lvm, -1, "LVM-VG")
                else:
                    cols = line.split('\t')
                    dev = cols[0].replace('/dev/', '')
                    if line == "" or rexprExcludes.search(dev):
                        continue
                    if len(cols) == 2:
                        # Disks
                        kByte = cols[1]
                        self._disks[dev] = DiskInfo(dev, int(kByte))
                        diskList += '/dev/' + dev + " (MBR)"
                        continue
                    infos = {}
                    for ix in xrange(len(cols)):
                        vals = cols[ix].split(':')
                        if len(vals) > 1:
                            infos[vals[0]] = vals[1]
                    size = 0 if 'size' not in infos else infos['size']
                    label = '' if 'label' not in infos else infos['label']
                    ptype = '' if 'ptype' not in infos else infos['ptype']
                    fs = '' if 'fs' not in infos else infos['fs']
                    pinfo = '' if 'pinfo' not in infos else infos['pinfo']
                    debian = '' if 'debian' not in infos else infos['debian']
                    os = '' if 'os' not in infos else infos['os']
                    distro = '' if 'distro' not in infos else infos['distro']
                    subdistro = '' if 'subdistro' not in infos else infos['subdistro']
                    
                    date = ''
                    if 'created' in infos:
                        date = (self._session.getConfig('diskinfo.created') 
                            + ': ' + infos['created'])
                    if 'modified' in infos:
                        date += (' ' + self._session.getConfig('diskinfo.modified') 
                            + ': ' + infos['modified'])
                        
                    info = subdistro if subdistro == '' else distro
                    if info == '':
                        info = debian    
                    if info == '':
                        info = os
                    if date != '':
                        info += date
                    size2 = self.humanReadableSize(int(size))
                    self._partitions[dev] = PartitionInfo(self._partitions, 
                        dev, label, int(size), size2, ptype, pinfo, fs, info)
                    self._partitionList.append(self._partitions[dev])
            fp.close()
            # strip the first separator:
            self._bootOptTarget = diskList[1:]
            
    def getPartition(self, device):
        '''Gets the partition info.
         @param device:    the name of the device, e.g. sda1
         @return: None: device not found. Otherwise: the the device info
        '''
        rc = None 
        if device in self._partitions:
            rc = self._partitions[device].label
        return rc

    def getPartitionsOfDisk(self, disk):
        '''Returns the partitions of a given disk.
        @param disk:    the name of the disk, e.g. sda
        @return:      a list of partitions (type PartitionInfo)
        '''
        rc = []
        if disk.endswith("/"):
            disk = "mapper/" + disk[0:-1] + "-"
        for partition in self._partitionList:
            if partition._device.startswith(disk):
                rc.append(partition)
        rc = sorted(rc, key=lambda partition: partition._sortName)
        return rc

    def getPartitionNamesOfDisk(self, disk):
        '''Returns the partition names of a given disk.
        @param disk:  the name of the disk, e.g. sda
        @return:      a list of partition names (type string)
        '''
        rc = self.getPartitionsOfDisk(disk)
        for ix in xrange(len(rc)):
            rc[ix] = rc[ix]._device
        return rc
            
    def hasGPT(self, disk):
        '''Returns whether a given disk has a GPT (instead of a MBR).
         @param dev: disk to test, e.g. sda
         @return True: the disk has a GPT<br>
             False: otherwise
        '''
        rc = self._gptDisks.find(disk) >= 0
        return rc

    def buildPartOfTable(self, disk, what, ixRow = None):
        '''
        @param info:  not used
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
            rc = self._currentRows[ixRow]
        elif what == "rows":
            rc = len(self._currentRows)
        elif what == "Table":
            rc = self._currentTableTemplate
        return rc
    
    def buildPartitionInfoTable(self, disk):
        '''Builds a HTML table with the info about the partitions of a given disk.
        @param disk: the name of the disk, e.g. sda1
        @return: "": error occurred<br>
                    otherwise: the HTML code of the table
        '''
        table = ""
        self._currentTableTemplate = self._snippets.get("TABLE_PARTINFO")
        # Prepare the rows: 
        # Get the partitions of the list:
        partitions = self.getPartitionsOfDisk(disk)
        # Get the info as a List
        self._currentRows = []
        for partition in partitions:
            self._currentRows.append((partition._device, partition._label,
                partition._size, partition._partType, partition._filesystem,
                partition._info))
        try:
            table = self.buildTable(self, disk)
        except PageException as exc:
            self._session.error("buildPartitionInfoTable({:s}: {:s}", 
                disk, exc.message)
            table  = ""
        return table
    
    def buildDiskInfoTable(self):
        '''Builds a HTML table with the info about the disks.
        @return: "": error occurred<br>
                    otherwise: the HTML code of the table
        '''
        table = ""
        self._currentTableTemplate = self._snippets.get("TABLE_DISKINFO")
        # Prepare the rows: 
        # Get the partitions of the list:
        # Get the info as a List
        self._currentRows = []
        disks = self._disks.keys()
        disks.sort()
        for name in disks:
            disk = self._disks[name]
            self._currentRows.append((disk._device, 
                    self.humanReadableSize(disk._size) if disk._size > 0 else "",
                    disk._info if disk._info != None else ""))
        try:
            table = self.buildTable(self, disk)
        except PageException as exc:
            self._session.error("buildDiskInfoTable: {:s}", 
                exc.message)
            table  = ""
        return table

    def buildInfoSwitch(self, state):
        '''Builds a switched area with 3 states.
        @param state: "NO", "PART" or "DISK"
        '''
        if not self._hasInfo:
            content = self._snippets.get("WAIT_FOR_INFO")
            self._parentPage.setRefresh(3)
        else:
            if state == None or state not in ["NO", "PART", "DISK"]:
                state = "NO"
            content = self._snippets.get("INFO_" + state)
            content = content.replace("{{STATE_SWITCH}}", 
                self._snippets.get("STATE_SWITCH"))
            content = self.fillStaticSelected("infostate", content, self._parentPage)
            if state == "PART":
                disk = self._parentPage.getField("disk2")
                disks = self.getDisks()
                if disk == None and disks != None:
                    disk = disks[0]
                content = self._parentPage.fillDynamicSelected("disk2", disks, None, content)
                content = content.replace("{{TABLE}}",
                    self.buildPartitionInfoTable(disk)) 
            elif state == "DISK":
                content = content.replace("{{TABLE}}",
                    self.buildDiskInfoTable())
        return content

    def getRootFsDevices(self):
        '''Returns the list of devices usable for rootfs
        @return: a list of device names starting with '-'
        '''
        rc = ["-"]
        minSize = int(self._session.getConfigWithoutLanguage(
            "diskinfo.root.minsize.mb"))
        for partition in self._partitionList:
            if partition.canBeRoot(minSize):
                dev = partition._device
                if dev.startswith("mapper/"):
                    dev = dev[7:]
                rc.append(dev)
        return rc
 
    def getDisks(self):
        '''Return the names of all disks:
        @return: a list of all disk names
        '''
        rc = []
        for disk in self._disks:
            rc.append(disk)
        rc.sort()
        return rc
          
    def getRealDisks(self):
        '''Returns the "real" disks: disks which are objects for partition programs
        @return: list of disk names
        '''
        rc = []
        for disk in self._disks:
            if not disk.endswith("/"):
                rc.append(disk)
        rc.sort()
        return rc
    
    def isYetMounted(self, dev, yetMounted):
        '''Tests whether a given device is in a list of yet mounted devices.
        @param dev:           name of the device to test
        @param yetMounted:    a list of strings with "<device>:<mountpoint>"
        @return true: dev is in yetMounted<br>
                false: otherwise
        '''
        rc = False
        for item in yetMounted:
            if item.startswith(dev):
                rc = True
                break
        return rc

    def getMountPartitions(self, yetMounted):
        '''Returns a tuple of devices and labels.
        @param yetMounted: a list of strings with <device>:<mountpoint>
        @return: a tuple (devs, labels). devs is a list of device names
                 which are valid for mounting, labels is a list of "" or the
                 assoziated label
        '''
        devs = []
        labels = []
        for partition in self._partitionList:
            if not self.isYetMounted(partition._device, yetMounted):
                devs.append(partition._device)
                labels.append(partition._label)
        return (devs, labels)
    
    def getWaitMessage(self):
        '''Returns a snippet for a "wait for info" message.
        @return: "": partition info has been found.
                 otherwise: a html text with the wait message
        '''
        rc = ""
        if not self._hasInfo:
            rc = self._snippets.get("WAIT_FOR_INFO")
            self._parentPage.setRefresh(3)
        return rc
      
    def listOfFirst(self, source):
        '''Returns a list built from a list of autosplit strings.
        The first item of each source element will be taken.
        @param source: the source list
        @return: a list with the first items of source
        '''
        rc = []
        for dev in source:
            names = self.autoSplit(dev)
            rc.append(names[0].replace("/dev/", ""))
        return rc
    
    def listOfFull(self, source):
        '''Returns a list built from a list of autosplit strings.
        The first item of each source element will be taken.
        @param source: the source list
        @return: a list with the first items of source
        '''
        rc = []
        for dev in source:
            cols = self.autoSplit(dev)
            if len(cols) > 0:
                cols[0] = cols[0].replace("/dev/", "")
            rc.append(cols)
        return rc
    
    def getMarkedPV(self, fullInfo = False):
        '''Returns the names of the partitions with partition type 0x8e.
        @param fullInfo:    False: only the name will be returned
                            True: all columns will be returned
        @return: a list of names, e.g. [sdc1, sdc2]
        '''
        if not fullInfo:
            rc = self.listOfFirst(self._markedLVM)
        else:
            rc = self.listOfFull(self._markedLVM)
        return rc

    def getFreePV(self, fullInfo = False):
        '''Returns the names of the unasigned physical volumes.
        @param fullInfo:    False: only the name will be returned
                            True: all columns will be returned
        @return: a list of names, e.g. [sdc1, sdc2]
                 or a list of tuples: [("sdc1", "4G"), ("sdc3", "2G")]
        '''
        if not fullInfo:
            rc = self.listOfFirst(self._freePV)
        else:
            rc = self.listOfFull(self._freePV)
        return rc

    def getVolumeGroups(self):
        '''Returns a list of the names of the volume groups.
        @return: a list of the names of the volume groups
        '''
        rc = self.listOfFirst(self._volumeGroups)
        return rc
    
    def listOfFirstOfVG(self, vg, source):
        rc = []
        for dev in self._freePV:
            names = self.autoSplit(dev)
            rc.append(names[0].replace("/dev/", ""))
        return rc
      
    def getPhysLVM(self):
        '''Returns the list of infos about the VGs.
        @return a list of the VGs
        '''
        return self._physicalVolumes
            
            