#! /bin/bash
python ../sidu-base/util/configurationbuilder.py -v --summary --drop-tables --prefix=sidu-base data/config.db ../sidu-base/config
python ../sidu-base/util/configurationbuilder.py -v --summary --prefix=sidu-disk-center data/config.db config

