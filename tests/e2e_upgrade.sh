#!/bin/bash
# Sandbox orchestration for the upgrade + feature end-to-end test (edit the paths/credentials for your machine).
# PHASE1_ZIP: the Phase 1 release zip; NEW: the current project folder.
set -u
PHASE1_ZIP=${PHASE1_ZIP:-/mnt/user-data/outputs/custom-cms-phase1.zip}
NEW=${NEW:-/home/claude/cms}
ROOT=/tmp/e2e2
pkill -x php 2>/dev/null; sleep 0.5
mysql -uroot -e "DROP DATABASE IF EXISTS cmse2e; CREATE DATABASE cmse2e CHARACTER SET utf8mb4; GRANT ALL ON cmse2e.* TO 'cms'@'localhost'; GRANT ALL ON cmse2e.* TO 'cms'@'127.0.0.1';"
rm -rf $ROOT /tmp/e2e2_unzip && mkdir /tmp/e2e2_unzip && unzip -q $PHASE1_ZIP -d /tmp/e2e2_unzip && mv /tmp/e2e2_unzip/cms $ROOT && rm -rf $ROOT/tests
cd $ROOT && php -d opcache.enable=0 -d opcache.enable_cli=0 -S 127.0.0.1:8099 router.php >/tmp/php-server2.log 2>&1 &
SP=$!
sleep 1.5
python3 -u $NEW/tests/e2e_upgrade.py partA || { kill $SP; exit 1; }
# Overlay the new release the way a user would: upload everything except the user/ folder.
cd $NEW && tar cf - --exclude=./user --exclude=./tests . | (cd $ROOT && tar xf -)
python3 -u $NEW/tests/e2e_upgrade.py partB
RC=$?
python3 -u $NEW/tests/e2e_upgrade.py partC
RC2=$?
[ $RC -eq 0 ] && RC=$RC2
kill $SP 2>/dev/null
exit $RC
