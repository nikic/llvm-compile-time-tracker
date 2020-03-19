killall php
killall ninja
php ./runner.php >log 2>&1 &
tail -F log
