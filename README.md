# electrum-wallet-to-mysql

<a href="https://electrum.org/">Electrum</a> is a feature-rich open source popular light-weight Bitcoin wallet written in Python.  It works well on all platforms, with a front-end or on command line.  If you want to do something with Bitcoin, for example accept payments on a web site, it is a great choice.

For those building web sites on LAMP or WAMP, this script helps convert Electrum's wallet address and transaction data to a MySQL database (database structure is included).  Why would you need this?  Wallet data contains addresses and transactions which you need to check against to give users Bitcoin addresses for payment as well as to give them credit or information that their payment was received.

If you are running this script on your web server (considered less secure), make sure your wallet file is seedless.  Seedless wallets are "watch-only" so if a hacker steals it, they won't be able to spend any money in it.
