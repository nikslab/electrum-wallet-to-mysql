# electrum-wallet-to-mysql

<a href="https://electrum.org/">Electrum</a> is a feature-rich open source popular light-weight Bitcoin wallet written in Python.  It works well on all platforms, with a front-end or on command line.  If you want to do something with Bitcoin, for example accept payments on a web site, it is a great choice.

For those building web sites on LAMP or WAMP, this script will convert Electrum's wallet address and transaction data to a MySQL database (database structure is included in CREATE-TABLE.sql).  Why would you need this?  Wallet data contains addresses which you need to give your users for payment, and transaction history which you need to check against to give users credit or information that their payment was received.  You'd want this data handy in MySQL tables where you can join them with other user data rather than querying electrum every time.

If you are running this script on your web server (which will be the primary target for hackers), make sure your wallet file is seedless.  Seedless wallets are "watch-only" so if a hacker steals it, they won't be able to spend any money in it.

Run this script as a cron job every minute or on demand.
