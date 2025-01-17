#!/bin/bash
# Script for HDD discovery
#
# Created by:
# Michał Macioszek, Taras Baran, Michal Gębora, Marcin Wilk, Maks Bednarek, Anna Fałek, Mikołaj Szczuraszek


disks=`ls -l /dev/sd* | awk '{print $NF}' | sed 's/[0-9]//g' | uniq`
echo -n "{\"data\":["

comma=1
for disk in $disks
do
    if [ $comma -eq 0 ]; then
        echo -n ","
    fi
    comma=0
    echo -n "{\"{#DISKNAME}\":\"$disk\",\"{#SHORTDISKNAME}\":\"${disk:5}\"}"
done

echo -n "]}"