#!/usr/bin/php
<?php

/**
 *
 * @name    watch_electrum_history.php
 * @author  Nik Stankovic 2015
 * @see     https://github.com/nikslab
 *
 * This script watches history for an Electrum 2.0 Bitcoin wallet file passed on
 * as argument and inserts address and transaction data into a MySQL table.
 *
 * Run it as cron every minute or so.
 *
 * @param   wallet_file     Wallet filename
 */

//
// Command line argument handling
//
if (count($argv) > 0) {
    $wallet_file = $argv[1];
    $wallet_name = basename($wallet_file); // file name only without path for reference
    if (isset($argv[2])) { $sleep = $argv[2]; } // optional
}

if( !$wallet_file ) {
    print "

Missing wallet file, cannot proceed.

Usage: watch_electrum_history.php <wallet_file> [<sleep>]

<wallet_file>   full path to wallet file
<sleep>         how far back to compare transactions
    
";
    exit(1);
}

//
// Config
//
# History window
$watch = 86400000; // in seconds, will check / compare transactions this far back
$electrum = "/usr/local/bin/electrum"; // which electrum

//
// Connect to database
//
require_once("db.php"); // Database connection info
$mysqli = new mysqli($DB_host, $DB_user, $DB_pass, $DB_db);
if ($mysqli->connect_errno) {
    echo "Failed to connect to MySQL: (" . $mysqli->connect_errno . ") " . $mysqli->connect_error;
    exit(1);
}

//
// Load wallet addresses from DATABASE
//
$select = "
            select  address,
                    balance_confirmed,
                    balance_unconfirmed
            from    addresses
            where   wallet=\"$wallet_name\"
";
$result = $mysqli->query($select);

$DB_ADDRESSES = array();    
while ($queryData = $result->fetch_array(MYSQL_ASSOC)) {
    $address = $queryData["address"];
    $balance_confirmed = $queryData["balance_confirmed"];
    $balance_unconfirmed = $queryData["balance_unconfirmed"];
    $DB_ADDRESSES[$address]["exists"] = true;
    $DB_ADDRESSES[$address]["confirmed"] = $balance_confirmed;
    $DB_ADDRESSES[$address]["unconfirmed"] = $balance_unconfirmed; 
}
    
//
// Load wallet addresses from ELECTRUM
//
$cmd = "$electrum -w \"$wallet_file\" listaddresses";
exec($cmd, $addresses_json);
$addresses = json_decode( implode( $addresses_json ), true );
$CHECK_BALANCE_QUEUE = array();
foreach ($addresses as $address) {
    if (!isset($DB_ADDRESSES[$address])) { // this address does not exist in database yet!
        $insert = "
            insert into addresses (
                wallet,
                address
            )
            values (
                \"$wallet_name\",
                \"$address\"
            );
        ";
        $result = $mysqli->query($insert);
        $DB_ADDRESSES[$address]["exists"] = true;        
        $CHECK_BALANCE_QUEUE[] = $address; // we'll be checking balances later
    }
}

//
// Load recent transaction history from DATABASE
//
$now = time();
$double_the_watch = date("Y-m-d H:i:s", $now - ($watch * 2));
$select = "
    select  txid
      from  tx_history
     where  inserted < \"$double_the_watch\";
";
$result = $mysqli->query($select);
$RECENT_TRANSACTIONS = array();    
while ($queryData = $result->fetch_array(MYSQL_ASSOC)) {
    $txid = $queryData["txid"];
    $RECENT_TRANSACTIONS[$txid] = true;
}

//
// Load transaction history from ELECTRUM
//
$cmd = "$electrum -w \"$wallet_file\" history";
exec($cmd, $transactions_json);
$transactions = json_decode(implode($transactions_json), true);
/*
 *$c = count( $transactions);
print "$cmd\nCount = $c\n";
var_dump( implode($transactions_json));
*/

// For each transaction in electrum history
foreach ($transactions as $transaction ) {
    
    $confirmations = $transaction["confirmations"];
    if( $confirmations < 0 ) { $confirmations = 0; } // why does Electrum do that?
    if ( preg_match( '#[0-9]#', $transaction["date"] ) ) {
        $tx_date = $transaction["date"] . ":00";
    }
    else { $tx_date = "NULL"; }
    
    // ( Is it in our window AND not in the database already ) OR has not date yet
    $d = strtotime($transaction["date"]);
    $now = strtotime("now");
    $diff = $now - $d;
    $txid = $transaction["txid"];

    if ( (($diff < $watch ) && (!isset($RECENT_TRANSACTIONS[$txid])))
         || ($tx_date == "NULL")) {

        // Then we should analyze it and add it
                        
        // Get raw transaction for decoding
        $cmd = "$electrum gettransaction -w $wallet_file \"" . $transaction["txid"] . "\"";
        print "$cmd = $cmd\n";
        $raw_json = array();
        exec($cmd, $raw_json, $exec_result);
        $raw = json_decode(implode($raw_json), true );
            
        // Is it complete?  That's what we want... we won't deal with incomplete transactions
        $complete = $raw["complete"];
        if ($complete) {
                
            // Insert into tx_history
            $insert = "
                insert into tx_history (
                    wallet,
                    confirmations,
                    date,
                    label,
                    txid,
                    complete,
                    raw
                )
                values (
                    \"$wallet_name\",
                    " . $transaction['confirmations'] . ",
                    $tx_date
                    \"" . $transaction['label'] . "\",
                    \"" . $transaction['id'] . "\",
                    \"" . $raw['complete'] . "\",
                    \"" . $raw['hex'] . "\",
                )
                
            ";
            $result = $mysqli->query($insert);
            
            // Now get the ID of this record in TX_HISTORY
            $select = "select id from tx_history where txid=\"" . $transaction["txid"] . "\"";
            $result = $mysqli->query($select);
            $queryData = $result->fetch_array( MYSQL_ASSOC );
            $tx_history_id = $queryData["id"];
            
            // Decode to get outputs
            $cmd = "$electrum -w \"$wallet_file\" deserialize \"" . $raw["hex"] . "\"";
            $decoded_json = array();
            exec($cmd, $decoded_json);
            $decoded = json_decode(implode($decoded_json), true);
          
            // Insert into tx_outputs
            foreach( $decoded["outputs"] as $o ) {
                if( $o["type"] == "address" ) {      
                    // Insert into tx_outputs
                    $insert = "
                        insert into tx_outputs (
                            tx_history_id,
                            address,
                            amount
                        )
                        values (
                            $tx_history_id,
                            \"" . $o["address"] . "\",
                            " . $o["value"]  . "  
                        )
                    ";
                    $result = $mysqli->query($insert);
                    // Queue up for balance update
                    $CHECK_BALANCE_QUEUE[] = $o["address"];
                }
            }
        }

        // Update confirmations and date in the DATABASE
        $update = "
            update  tx_history
               set  confirmations=$confirmations,
                    date=\"$tx_date\"
             where  txid=\"" . $transaction["txid"] . "\";
        ";
        $result = $mysqli->query($update);
        
    }
    
    //
    // Update balances in DATABASE for affected addresses
    //
    foreach ($CHECK_BALANCE_QUEUE as $address) {
        $cmd = "$electrum -w \"$wallet_file\" getaddressbalance \"$address\"";
        $decoded_json = array();
        exec($cmd, $decoded_json);
        $decoded = json_decode(implode($decoded_json), true);
        
        $balance_confirmed = $decoded["confirmed"];
        $balance_unconfirmed = $decoded["unconfirmed"];
        
        $update = "
            update  addresses
               set  balance_confirmed = $balance_confirmed,
                    balance_unconfirmed = $balance_unconfirmed
             where  address = \"$address\"
        ";
        $result = $mysqli->query($update);
    }
    
}

// Bye bye
$mysqli->close();

?>