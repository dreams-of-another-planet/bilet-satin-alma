#!/bin/sh

rm data/db.sqlite
touch data/db.sqlite
chmod 777 data/db.sqlite
sqlite3 data/db.sqlite < initdb.sql
