#! /bin/bash

if [ ! -d "$1" ] ; then
	echo "usage MkSort.sh DIR"
	echo "example MkSort.sh before"
	exit 1
fi
for f in *.conf ; do
	sort $f >$1/$f.srt
done
echo result in $1

