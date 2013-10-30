#! /bin/bash
./import_config.sh
PROJ=sidu-disk-center
sudo chown -R hm /usr/share/$PROJ /usr/share/sidu-base
cp data/config.db /usr/share/$PROJ/data
cp -v templates/* /usr/share/$PROJ/templates
cp -v source/* /usr/share/$PROJ/source
cp -v config/* /usr/share/$PROJ/config
cd ../sidu-base
rsync -va util/ webbasic/ /usr/share/sidu-base

