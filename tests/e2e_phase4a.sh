#!/bin/bash
# Phase 4A end-to-end orchestration: Phase 3 release -> current code. Edit paths for your machine.
set -u
BASELINE_ZIP=${BASELINE_ZIP:-/mnt/user-data/outputs/custom-cms-phase3.zip}
NEW=${NEW:-/home/claude/cms}
export CMS_E2E_ROOT=/tmp/e2e4 CMS_E2E_NEW=$NEW
ROOT=$CMS_E2E_ROOT
pkill -x php 2>/dev/null; sleep 0.5
mysql -uroot -e "DROP DATABASE IF EXISTS cmse2e; CREATE DATABASE cmse2e CHARACTER SET utf8mb4; GRANT ALL ON cmse2e.* TO 'cms'@'localhost'; GRANT ALL ON cmse2e.* TO 'cms'@'127.0.0.1';"
rm -rf $ROOT /tmp/e2e4_unzip && mkdir /tmp/e2e4_unzip && unzip -q $BASELINE_ZIP -d /tmp/e2e4_unzip && mv /tmp/e2e4_unzip/cms $ROOT && rm -rf $ROOT/tests
cd $ROOT && php -d opcache.enable=0 -d opcache.enable_cli=0 -d upload_max_filesize=2M -d post_max_size=8M -S 127.0.0.1:8099 router.php >/tmp/php-server4.log 2>&1 &
SP=$!
sleep 1.5
python3 -u $NEW/tests/e2e_phase4a.py partA || { kill $SP; exit 1; }
cd $NEW && tar cf - --exclude=./user --exclude=./tests . | (cd $ROOT && tar xf -)
python3 -u $NEW/tests/e2e_phase4a.py partB
RC=$?
kill $SP 2>/dev/null
exit $RC
