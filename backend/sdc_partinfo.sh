#! /bin/bash
ANSWER=$1
CMD=$2
TEMP1=$ANSWER.tmp
TEMP2=/tmp/$$.data
perl sdc_partinfo.pl >>$TEMP1
mv $TEMP1 $ANSWER
