#! /bin/bash
test -n "$VERBOSE" && set -x
ANSWER=$1
CMD=$2
PARAM=$3
PARAM2=$4
PARAM3=$5
PARAM4=$6
PARAM5=$7
PARAM6=$8
PARAM7=$9
TEMP1=$ANSWER.tmp
TEMP2=/tmp/$$.data
CMD2=$CMD
if [ "$CMD" = "sncreate" ] ; then
	CMD2=lvcreate
fi
#X=echo 
ARGS="$PARAM $PARAM2 $PARAM3 $PARAM4 $PARAM5 $PARAM6"
ARGS=$(echo $ARGS | sed -e s/%/%%/g)
date "+%Y.%m.%d/%H:%M:%S: $CMD2 $ARGS" >$TEMP1
case "$CMD" in
	pvcreate)
		$X $CMD $PARAM >>$TEMP1 2>&1
		;;
	vgcreate)
		$X $CMD "$PARAM" "$PARAM2" "$PARAM3" "$PARAM4" >>$TEMP1 2>&1
		;;
	vgextend)
		$X $CMD "$PARAM" "$PARAM2" >>$TEMP1 2>&1
		;;
	lvcreate)
		LV=$PARAM4
		VG=$PARAM5
		$X $CMD $PARAM $PARAM2 $PARAM3 $LV $VG >>$TEMP1 2>&1
		if [ "$PARAM6" == 'swap' ] ; then
			if [ "$PARAM7" != "" ] ; then
				PARAM7="-L $PARAM7"
			fi
			date "+%Y.%m.%d/%H:%M: mkswap -f $PARAM7 /dev/$VG/$LV " >>$TEMP1
			$X mkswap -f $PARAM7 /dev/$VG/$LV >>$TEMP1 2>&1
		elif [ "$PARAM6" != "-" ] ; then
			if [ "$PARAM7" != "" ] ; then
				PARAM7="-L $PARAM7"
			fi
			date "+%Y.%m.%d/%H:%M: mkfs.$PARAM6 $PARAM7 /dev/$VG/$LV " >>$TEMP1
			$X mkfs.$PARAM6 $PARAM7 /dev/$VG/$LV >>$TEMP1 2>&1
		fi
		;;
	sncreate)
		$X lvcreate  $PARAM $PARAM2 $PARAM3 $PARAM4 $PARAM5 $PARAM6 $PARAM7 >>$TEMP1 2>&1
		;;
	*)
		echo >$TEMP1 "unknown command: $CMD"
		;;
esac
mv $TEMP1 $ANSWER

