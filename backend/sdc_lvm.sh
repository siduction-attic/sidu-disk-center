#! /bin/bash
set -x
ANSWER=$1
CMD=$2
PARAM=$3
PARAM2=$4
PARAM3=$5
PARAM4=$6
PARAM5=$7
TEMP1=$ANSWER.tmp
TEMP2=/tmp/$$.data
echo Param: $PARAM $PARAM2 .
#X=echo 
ARGS="$PARAM $PARAM2 $PARAM3 $PARAM4 $PARAM5"
ARGS=$(echo $ARGS | sed -e s/%/%%/g)
date "+%Y.%m.%d/%H:%M: $ARGS" >$TEMP1
case "$CMD" in
	pvcreate)
		$X $CMD $PARAM >>$TEMP1 2>&1
		;;
	vgcreate|vgextend)
		$X $CMD "$PARAM" "$PARAM2" >>$TEMP1 2>&1
		;;
	lvcreate)
		$X $CMD $PARAM $PARAM2 $PARAM3 $PARAM4 $PARAM5 >>$TEMP1 2>&1
		;;
	*)
		echo >$TEMP1 "unknown command: $CMD"
		;;
esac
mv $TEMP1 $ANSWER

