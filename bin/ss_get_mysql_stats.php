<?php

# ============================================================================
# This is a script to retrieve information from a MySQL server for input to a
# Cacti graphing process.  It is hosted at
# http://code.google.com/p/mysql-cacti-templates/.
#
# This program is copyright (c) 2007 Baron Schwartz. Feedback and improvements
# are welcome.
#
# THIS PROGRAM IS PROVIDED "AS IS" AND WITHOUT ANY EXPRESS OR IMPLIED
# WARRANTIES, INCLUDING, WITHOUT LIMITATION, THE IMPLIED WARRANTIES OF
# MERCHANTIBILITY AND FITNESS FOR A PARTICULAR PURPOSE.
#
# This program is free software; you can redistribute it and/or modify it under
# the terms of the GNU General Public License as published by the Free Software
# Foundation, version 2.
#
# You should have received a copy of the GNU General Public License along with
# this program; if not, write to the Free Software Foundation, Inc., 59 Temple
# Place, Suite 330, Boston, MA  02111-1307  USA.
# ============================================================================

# ============================================================================
# To make this code testable, we need to prevent code from running when it is
# included from the test script.  The test script and this file have different
# filenames, so we can compare them.  In some cases $_SERVER['SCRIPT_FILENAME']
# seems not to be defined, so we skip the check -- this check should certainly
# pass in the test environment.
# ============================================================================
if ( !array_key_exists('SCRIPT_FILENAME', $_SERVER)
   || basename(__FILE__) == basename($_SERVER['SCRIPT_FILENAME']) ) {

# ============================================================================
# CONFIGURATION
# ============================================================================
# Define MySQL connection constants in config.php.  Arguments explicitly passed
# in from Cacti will override these.  However, if you leave them blank in Cacti
# and set them here, you can make life easier.  Instead of defining parameters
# here, you can define them in another file named the same as this file, with a
# .cnf extension.
# ============================================================================
$mysql_user = 'zabbix';
$mysql_pass = 'ha7jqnlacwefrs';
$mysql_port = 3306;
$mysql_ssl  = FALSE;   # Whether to use SSL to connect to MySQL.

$heartbeat  = '';      # db.tbl in case you use mk-heartbeat from Maatkit.
$cache_dir  = '/tmp';  # If set, this uses caching to avoid multiple calls.
$poll_time  = 300;     # Adjust to match your polling interval.
$chk_options = array (
   'innodb'  => true,    # Do you want to check InnoDB statistics?
   'master'  => true,    # Do you want to check binary logging?
   'slave'   => true,    # Do you want to check slave status?
   'procs'   => true,    # Do you want to check SHOW PROCESSLIST?
   'get_qrt' => true,   # Get query response times from Percona Server?
);

$use_ss    = FALSE; # Whether to use the script server or not
$debug     = FALSE; # Define whether you want debugging behavior.
$debug_log = FALSE; # If $debug_log is a filename, it'll be used.

# ============================================================================
# You should not need to change anything below this line.
# ============================================================================
$version = "1.1.8";

# ============================================================================
# Include settings from an external config file (issue 39).
# ============================================================================
if ( file_exists(__FILE__ . '.cnf' ) ) {
   require(__FILE__ . '.cnf');
}

# Make this a happy little script even when there are errors.
$no_http_headers = true;
ini_set('implicit_flush', false); # No output, ever.
if ( $debug ) {
   ini_set('display_errors', true);
   ini_set('display_startup_errors', true);
   ini_set('error_reporting', 2147483647);
}
else {
   ini_set('error_reporting', E_ERROR);
}
ob_start(); # Catch all output such as notices of undefined array indexes.
function error_handler($errno, $errstr, $errfile, $errline) {
   print("$errstr at $errfile line $errline\n");
   debug("$errstr at $errfile line $errline");
}
# ============================================================================
# Set up the stuff we need to be called by the script server.
# ============================================================================
if ( $use_ss ) {
   if ( file_exists( dirname(__FILE__) . "/../include/global.php") ) {
      # See issue 5 for the reasoning behind this.
      debug("including " . dirname(__FILE__) . "/../include/global.php");
      include_once(dirname(__FILE__) . "/../include/global.php");
   }
   elseif ( file_exists( dirname(__FILE__) . "/../include/config.php" ) ) {
      # Some Cacti installations don't have global.php.
      debug("including " . dirname(__FILE__) . "/../include/config.php");
      include_once(dirname(__FILE__) . "/../include/config.php");
   }
}

# ============================================================================
# Make sure we can also be called as a script.
# ============================================================================
if (!isset($called_by_script_server)) {
   debug($_SERVER["argv"]);
   array_shift($_SERVER["argv"]); # Strip off this script's filename
   $options = parse_cmdline($_SERVER["argv"]);
   validate_options($options);
   $result = ss_get_mysql_stats($options);
   debug($result);
   if ( !$debug ) {
      # Throw away the buffer, which ought to contain only errors.
      ob_end_clean();
   }
   else {
      ob_end_flush(); # In debugging mode, print out the errors.
   }

   # Split the result up and extract only the desired parts of it.
   $wanted = explode(',', $options['items']);
   $output = array();
   foreach ( explode(' ', $result) as $item ) {
      if ( in_array(substr($item, 0, 2), $wanted) ) {
         $output[] = $item;
      }
   }
   debug(array("Final result", $output));
   print(implode(' ', $output));
}

# ============================================================================
# End "if file was not included" section.
# ============================================================================
}

# ============================================================================
# Work around the lack of array_change_key_case in older PHP.
# ============================================================================
if ( !function_exists('array_change_key_case') ) {
   function array_change_key_case($arr) {
      $res = array();
      foreach ( $arr as $key => $val ) {
         $res[strtolower($key)] = $val;
      }
      return $res;
   }
}

# ============================================================================
# Validate that the command-line options are here and correct
# ============================================================================
function validate_options($options) {
   debug($options);
   $opts = array('host', 'items', 'user', 'pass', 'heartbeat', 'nocache', 'port');
   # Required command-line options
   foreach ( array('host', 'items') as $option ) {
      if ( !isset($options[$option]) || !$options[$option] ) {
         usage("Required option --$option is missing");
      }
   }
   foreach ( $options as $key => $val ) {
      if ( !in_array($key, $opts) ) {
         usage("Unknown option --$key");
      }
   }
}

# ============================================================================
# Print out a brief usage summary
# ============================================================================
function usage($message) {
   global $mysql_user, $mysql_pass, $mysql_port, $heartbeat;

   $usage = <<<EOF
$message
Usage: php ss_get_mysql_stats.php --host <host> --items <item,...> [OPTION]

   --host      Hostname to connect to; use host:port syntax to specify a port
               Use :/path/to/socket if you want to connect via a UNIX socket
   --items     Comma-separated list of the items whose data you want
   --user      MySQL username; defaults to $mysql_user if not given
   --pass      MySQL password; defaults to $mysql_pass if not given
   --heartbeat MySQL heartbeat table; defaults to '$heartbeat' (see mk-heartbeat)
   --nocache   Do not cache results in a file
   --port      MySQL port; defaults to $mysql_port if not given
   --mysql_ssl Add the MYSQL_CLIENT_SSL flag to mysql_connect() call

EOF;
   die($usage);
}

# ============================================================================
# Parse command-line arguments, in the format --arg value --arg value, and
# return them as an array ( arg => value )
# ============================================================================
function parse_cmdline( $args ) {
   $result = array();
   $cur_arg = '';
   foreach ($args as $val) {
      if ( strpos($val, '--') === 0 ) {
         if ( strpos($val, '--no') === 0 ) {
            # It's an option without an argument, but it's a --nosomething so
            # it's OK.
            $result[substr($val, 2)] = 1;
            $cur_arg = '';
         }
         elseif ( $cur_arg ) { # Maybe the last --arg was an option with no arg
            if ( $cur_arg == '--user' || $cur_arg == '--pass' || $cur_arg == '--port' ) {
               # Special case because Cacti will pass these without an arg
               $cur_arg = '';
            }
            else {
               die("No arg: $cur_arg\n");
            }
         }
         else {
            $cur_arg = $val;
         }
      }
      else {
         $result[substr($cur_arg, 2)] = $val;
         $cur_arg = '';
      }
   }
   if ( $cur_arg && ($cur_arg != '--user' && $cur_arg != '--pass' && $cur_arg != '--port') ) {
      die("No arg: $cur_arg\n");
   }
   debug($result);
   return $result;
}

# ============================================================================
# This is the main function.  Some parameters are filled in from defaults at the
# top of this file.
# ============================================================================
function ss_get_mysql_stats( $options ) {
   # Process connection options and connect to MySQL.
   global $debug, $mysql_user, $mysql_pass, $heartbeat, $cache_dir, $poll_time,
          $chk_options, $mysql_port, $mysql_ssl;

   # Connect to MySQL.
   $user = isset($options['user']) ? $options['user'] : $mysql_user;
   $pass = isset($options['pass']) ? $options['pass'] : $mysql_pass;
   $port = isset($options['port']) ? $options['port'] : $mysql_port;
   $heartbeat = isset($options['heartbeat']) ? $options['heartbeat'] : $heartbeat;
   # If there is a port, or if it's a non-standard port, we add ":$port" to the
   # hostname.
   $host_str  = $options['host']
              . (isset($options['port']) || $port != 3306 ? ":$port" : '');
   debug(array('connecting to', $host_str, $user, $pass));
   if ( !extension_loaded('mysql') ) {
      debug("The MySQL extension is not loaded");
      die("The MySQL extension is not loaded");
   }
   if ( $mysql_ssl || (isset($options['mysql_ssl']) && $options['mysql_ssl']) ) {
      $conn = mysql_connect($host_str, $user, $pass, true, MYSQL_CLIENT_SSL);
   }
   else {
      $conn = mysql_connect($host_str, $user, $pass);
   }
   if ( !$conn ) {
      die("MySQL: " . mysql_error());
   }

   $sanitized_host
       = str_replace(array(":", "/"), array("", "_"), $options['host']);
   $cache_file = "$cache_dir/$sanitized_host-mysql_cacti_stats.txt"
               . (isset($options['port']) || $port != 3306 ? ":$port" : '');
   debug("Cache file is $cache_file");

   # First, check the cache.
   $fp = null;
   if ( !isset($options['nocache']) ) {
      if ( $fp = fopen($cache_file, 'a+') ) {
         $locked = flock($fp, 1); # LOCK_SH
         if ( $locked ) {
            if ( filesize($cache_file) > 0
               && filectime($cache_file) + ($poll_time/2) > time()
               && ($arr = file($cache_file))
            ) {# The cache file is good to use.
               debug("Using the cache file");
               fclose($fp);
               return $arr[0];
            }
            else {
               debug("The cache file seems too small or stale");
               # Escalate the lock to exclusive, so we can write to it.
               if ( flock($fp, 2) ) { # LOCK_EX
                  # We might have blocked while waiting for that LOCK_EX, and
                  # another process ran and updated it.  Let's see if we can just
                  # return the data now:
                  if ( filesize($cache_file) > 0
                     && filectime($cache_file) + ($poll_time/2) > time()
                     && ($arr = file($cache_file))
                  ) {# The cache file is good to use.
                     debug("Using the cache file");
                     fclose($fp);
                     return $arr[0];
                  }
                  ftruncate($fp, 0); # Now it's ready for writing later.
               }
            }
         }
         else {
            debug("Couldn't lock the cache file, ignoring it.");
            $fp = null;
         }
      }
   }
   else {
      $fp = null;
      debug("Couldn't open the cache file");
   }

   # Set up variables.
   $status = array( # Holds the result of SHOW STATUS, SHOW INNODB STATUS, etc
      # Define some indexes so they don't cause errors with += operations.
      'relay_log_space'          => null,
      'binary_log_space'         => null,
      'current_transactions'     => 0,
      'locked_transactions'      => 0,
      'active_transactions'      => 0,
      'innodb_locked_tables'     => 0,
      'innodb_tables_in_use'     => 0,
      'innodb_lock_structs'      => 0,
      'innodb_lock_wait_secs'    => 0,
      'innodb_sem_waits'         => 0,
      'innodb_sem_wait_time_ms'  => 0,
      # Values for the 'state' column from SHOW PROCESSLIST (converted to
      # lowercase, with spaces replaced by underscores)
      'State_closing_tables'       => null,
      'State_copying_to_tmp_table' => null,
      'State_end'                  => null,
      'State_freeing_items'        => null,
      'State_init'                 => null,
      'State_locked'               => null,
      'State_login'                => null,
      'State_preparing'            => null,
      'State_reading_from_net'     => null,
      'State_sending_data'         => null,
      'State_sorting_result'       => null,
      'State_statistics'           => null,
      'State_updating'             => null,
      'State_writing_to_net'       => null,
      'State_none'                 => null,
      'State_other'                => null, # Everything not listed above
   );

   # Get SHOW STATUS and convert the name-value array into a simple
   # associative array.
   $result = run_query("SHOW /*!50002 GLOBAL */ STATUS", $conn);
   foreach ( $result as $row ) {
      $status[$row[0]] = $row[1];
   }

   # Get SHOW VARIABLES and do the same thing, adding it to the $status array.
   $result = run_query("SHOW VARIABLES", $conn);
   foreach ( $result as $row ) {
      $status[$row[0]] = $row[1];
   }

   # Get SHOW SLAVE STATUS, and add it to the $status array.
   if ( $chk_options['slave'] ) {
      $result = run_query("SHOW SLAVE STATUS", $conn);
      $slave_status_rows_gotten = 0;
      foreach ( $result as $row ) {
         $slave_status_rows_gotten++;
         # Must lowercase keys because different MySQL versions have different
         # lettercase.
         $row = array_change_key_case($row, CASE_LOWER);
         $status['relay_log_space']  = $row['relay_log_space'];
         $status['slave_lag']        = $row['seconds_behind_master'];

         # Check replication heartbeat, if present.
         if ( $heartbeat ) {
            $result2 = run_query(
               "SELECT GREATEST(0, UNIX_TIMESTAMP() - UNIX_TIMESTAMP(ts) - 1)"
               . " AS delay FROM $heartbeat WHERE id = 1", $conn);
            $slave_delay_rows_gotten = 0;
            foreach ( $result2 as $row2 ) {
               $slave_delay_rows_gotten++;
               if ( $row2 && is_array($row2)
                  && array_key_exists('delay', $row2) )
               {
                  $status['slave_lag'] = $row2['delay'];
               }
               else {
                  debug("Couldn't get slave lag from $heartbeat");
               }
            }
            if ( $slave_delay_rows_gotten == 0 ) {
               debug("Got nothing from heartbeat query");
            }
         }

         # Scale slave_running and slave_stopped relative to the slave lag.
         $status['slave_running'] = ($row['slave_sql_running'] == 'Yes')
            ? $status['slave_lag'] : 0;
         $status['slave_stopped'] = ($row['slave_sql_running'] == 'Yes')
            ? 0 : $status['slave_lag'];
      }
      if ( $slave_status_rows_gotten == 0 ) {
         debug("Got nothing from SHOW SLAVE STATUS");
      }
   }

   # Get SHOW MASTER STATUS, and add it to the $status array.
   if ( $chk_options['master']
         && array_key_exists('log_bin', $status)
         && $status['log_bin'] == 'ON'
   ) { # See issue #8
      $binlogs = array(0);
      $result = run_query("SHOW MASTER LOGS", $conn);
      foreach ( $result as $row ) {
         $row = array_change_key_case($row, CASE_LOWER);
         # Older versions of MySQL may not have the File_size column in the
         # results of the command.  Zero-size files indicate the user is
         # deleting binlogs manually from disk (bad user! bad!).
         if ( array_key_exists('file_size', $row) && $row['file_size'] > 0 ) {
            $binlogs[] = $row['file_size'];
         }
      }
      if (count($binlogs)) {
         $status['binary_log_space'] = to_int(array_sum($binlogs));
      }
   }

   # Get SHOW PROCESSLIST and aggregate it by state, then add it to the array
   # too.
   if ( $chk_options['procs'] ) {
      $result = run_query('SHOW PROCESSLIST', $conn);
      foreach ( $result as $row ) {
         $state = $row['State'];
         if ( is_null($state) ) {
            $state = 'NULL';
         }
         if ( $state == '' ) {
            $state = 'none';
         }
         $state = str_replace(' ', '_', strtolower($state));
         if ( array_key_exists("State_$state", $status) ) {
            increment($status, "State_$state", 1);
         }
         else {
            increment($status, "State_other", 1);
         }
      }
   }

   # Get SHOW INNODB STATUS and extract the desired metrics from it, then add
   # those to the array too.
   if ( $chk_options['innodb']
         && array_key_exists('have_innodb', $status)
         && $status['have_innodb'] == 'YES'
   ) {
      $result        = run_query("SHOW /*!50000 ENGINE*/ INNODB STATUS", $conn);
      $istatus_text = $result[0]['Status'];
      $istatus_vals = get_innodb_array($istatus_text);

      # Get response time histogram from Percona Server if enabled.
      if ( $chk_options['get_qrt']
           && isset($status['have_response_time_distribution']) 
           &&      ($status['have_response_time_distribution'] == 'YES'))
      {
         debug('Getting query time histogram');
         $i = 0;
         $result = run_query(
            "SELECT `count`, total * 1000000 AS total "
               . "FROM INFORMATION_SCHEMA.QUERY_RESPONSE_TIME "
               . "WHERE `time` <> 'TOO LONG'",
            $conn);
         foreach ( $result as $row ) {
            $count_key = sprintf("Query_time_count_%02d", $i);
            $total_key = sprintf("Query_time_total_%02d", $i);
            $status[$count_key] = $row['count'];
            $status[$total_key] = $row['total'];
            $i++;
         }
      }
      else {
         debug('Not getting time histogram because it is not enabled');
      }

      # Override values from InnoDB parsing with values from SHOW STATUS,
      # because InnoDB status might not have everything and the SHOW STATUS is
      # to be preferred where possible.
      $overrides = array(
         'Innodb_buffer_pool_pages_data'  => 'database_pages',
         'Innodb_buffer_pool_pages_dirty' => 'modified_pages',
         'Innodb_buffer_pool_pages_free'  => 'free_pages',
         'Innodb_buffer_pool_pages_total' => 'pool_size',
         'Innodb_data_fsyncs'             => 'file_fsyncs',
         'Innodb_data_pending_reads'      => 'pending_normal_aio_reads',
         'Innodb_data_pending_writes'     => 'pending_normal_aio_writes',
         'Innodb_os_log_pending_fsyncs'   => 'pending_log_flushes',
         'Innodb_pages_created'           => 'pages_created',
         'Innodb_pages_read'              => 'pages_read',
         'Innodb_pages_written'           => 'pages_written',
         'Innodb_rows_deleted'            => 'rows_deleted',
         'Innodb_rows_inserted'           => 'rows_inserted',
         'Innodb_rows_read'               => 'rows_read',
         'Innodb_rows_updated'            => 'rows_updated',
      );

      # If the SHOW STATUS value exists, override...
      foreach ( $overrides as $key => $val ) {
         if ( array_key_exists($key, $status) ) {
            debug("Override $key");
            $istatus_vals[$val] = $status[$key];
         }
      }

      # Now copy the values into $status.
      foreach ( $istatus_vals as $key => $val ) {
         $status[$key] = $istatus_vals[$key];
      }
   }

   # Make table_open_cache backwards-compatible (issue 63).
   if ( array_key_exists('table_open_cache', $status) ) {
      $status['table_cache'] = $status['table_open_cache'];
   }

   # Compute how much of the key buffer is used and unflushed (issue 127).
   $status['Key_buf_bytes_used']
      = big_sub($status['key_buffer_size'],
         big_multiply($status['Key_blocks_unused'],
         $status['key_cache_block_size']));
   $status['Key_buf_bytes_unflushed']
      = big_multiply($status['Key_blocks_not_flushed'],
         $status['key_cache_block_size']);

   if ( array_key_exists('unflushed_log', $status)
         && $status['unflushed_log']
   ) {
      # TODO: I'm not sure what the deal is here; need to debug this.  But the
      # unflushed log bytes spikes a lot sometimes and it's impossible for it to
      # be more than the log buffer.
      debug("Unflushed log: $status[unflushed_log]");
      $status['unflushed_log']
         = max($status['unflushed_log'], $status['innodb_log_buffer_size']);
   }

   # Define the variables to output.  I use shortened variable names so maybe
   # it'll all fit in 1024 bytes for Cactid and Spine's benefit.  This list must
   # come right after the word MAGIC_VARS_DEFINITIONS.  The Perl script parses
   # it and uses it as a Perl variable.
   $keys = array(
       'Key_read_requests'          => 'a0',
       'Key_reads'                  => 'a1',
       'Key_write_requests'         => 'a2',
       'Key_writes'                 => 'a3',
       'history_list'               => 'a4',
       'innodb_transactions'        => 'a5',
       'read_views'                 => 'a6',
       'current_transactions'       => 'a7',
       'locked_transactions'        => 'a8',
       'active_transactions'        => 'a9',
       'pool_size'                  => 'aa',
       'free_pages'                 => 'ab',
       'database_pages'             => 'ac',
       'modified_pages'             => 'ad',
       'pages_read'                 => 'ae',
       'pages_created'              => 'af',
       'pages_written'              => 'ag',
       'file_fsyncs'                => 'ah',
       'file_reads'                 => 'ai',
       'file_writes'                => 'aj',
       'log_writes'                 => 'ak',
       'pending_aio_log_ios'        => 'al',
       'pending_aio_sync_ios'       => 'am',
       'pending_buf_pool_flushes'   => 'an',
       'pending_chkp_writes'        => 'ao',
       'pending_ibuf_aio_reads'     => 'ap',
       'pending_log_flushes'        => 'aq',
       'pending_log_writes'         => 'ar',
       'pending_normal_aio_reads'   => 'as',
       'pending_normal_aio_writes'  => 'at',
       'ibuf_inserts'               => 'au',
       'ibuf_merged'                => 'av',
       'ibuf_merges'                => 'aw',
       'spin_waits'                 => 'ax',
       'spin_rounds'                => 'ay',
       'os_waits'                   => 'az',
       'rows_inserted'              => 'b0',
       'rows_updated'               => 'b1',
       'rows_deleted'               => 'b2',
       'rows_read'                  => 'b3',
       'Table_locks_waited'         => 'b4',
       'Table_locks_immediate'      => 'b5',
       'Slow_queries'               => 'b6',
       'Open_files'                 => 'b7',
       'Open_tables'                => 'b8',
       'Opened_tables'              => 'b9',
       'innodb_open_files'          => 'ba',
       'open_files_limit'           => 'bb',
       'table_cache'                => 'bc',
       'Aborted_clients'            => 'bd',
       'Aborted_connects'           => 'be',
       'Max_used_connections'       => 'bf',
       'Slow_launch_threads'        => 'bg',
       'Threads_cached'             => 'bh',
       'Threads_connected'          => 'bi',
       'Threads_created'            => 'bj',
       'Threads_running'            => 'bk',
       'max_connections'            => 'bl',
       'thread_cache_size'          => 'bm',
       'Connections'                => 'bn',
       'slave_running'              => 'bo',
       'slave_stopped'              => 'bp',
       'Slave_retried_transactions' => 'bq',
       'slave_lag'                  => 'br',
       'Slave_open_temp_tables'     => 'bs',
       'Qcache_free_blocks'         => 'bt',
       'Qcache_free_memory'         => 'bu',
       'Qcache_hits'                => 'bv',
       'Qcache_inserts'             => 'bw',
       'Qcache_lowmem_prunes'       => 'bx',
       'Qcache_not_cached'          => 'by',
       'Qcache_queries_in_cache'    => 'bz',
       'Qcache_total_blocks'        => 'c0',
       'query_cache_size'           => 'c1',
       'Questions'                  => 'c2',
       'Com_update'                 => 'c3',
       'Com_insert'                 => 'c4',
       'Com_select'                 => 'c5',
       'Com_delete'                 => 'c6',
       'Com_replace'                => 'c7',
       'Com_load'                   => 'c8',
       'Com_update_multi'           => 'c9',
       'Com_insert_select'          => 'ca',
       'Com_delete_multi'           => 'cb',
       'Com_replace_select'         => 'cc',
       'Select_full_join'           => 'cd',
       'Select_full_range_join'     => 'ce',
       'Select_range'               => 'cf',
       'Select_range_check'         => 'cg',
       'Select_scan'                => 'ch',
       'Sort_merge_passes'          => 'ci',
       'Sort_range'                 => 'cj',
       'Sort_rows'                  => 'ck',
       'Sort_scan'                  => 'cl',
       'Created_tmp_tables'         => 'cm',
       'Created_tmp_disk_tables'    => 'cn',
       'Created_tmp_files'          => 'co',
       'Bytes_sent'                 => 'cp',
       'Bytes_received'             => 'cq',
       'innodb_log_buffer_size'     => 'cr',
       'unflushed_log'              => 'cs',
       'log_bytes_flushed'          => 'ct',
       'log_bytes_written'          => 'cu',
       'relay_log_space'            => 'cv',
       'binlog_cache_size'          => 'cw',
       'Binlog_cache_disk_use'      => 'cx',
       'Binlog_cache_use'           => 'cy',
       'binary_log_space'           => 'cz',
       'innodb_locked_tables'       => 'd0',
       'innodb_lock_structs'        => 'd1',
       'State_closing_tables'       => 'd2',
       'State_copying_to_tmp_table' => 'd3',
       'State_end'                  => 'd4',
       'State_freeing_items'        => 'd5',
       'State_init'                 => 'd6',
       'State_locked'               => 'd7',
       'State_login'                => 'd8',
       'State_preparing'            => 'd9',
       'State_reading_from_net'     => 'da',
       'State_sending_data'         => 'db',
       'State_sorting_result'       => 'dc',
       'State_statistics'           => 'dd',
       'State_updating'             => 'de',
       'State_writing_to_net'       => 'df',
       'State_none'                 => 'dg',
       'State_other'                => 'dh',
       'Handler_commit'             => 'di',
       'Handler_delete'             => 'dj',
       'Handler_discover'           => 'dk',
       'Handler_prepare'            => 'dl',
       'Handler_read_first'         => 'dm',
       'Handler_read_key'           => 'dn',
       'Handler_read_next'          => 'do',
       'Handler_read_prev'          => 'dp',
       'Handler_read_rnd'           => 'dq',
       'Handler_read_rnd_next'      => 'dr',
       'Handler_rollback'           => 'ds',
       'Handler_savepoint'          => 'dt',
       'Handler_savepoint_rollback' => 'du',
       'Handler_update'             => 'dv',
       'Handler_write'              => 'dw',
       # Some InnoDB stats added later...
       'innodb_tables_in_use'    => 'dx',
       'innodb_lock_wait_secs'   => 'dy',
       'hash_index_cells_total'  => 'dz',
       'hash_index_cells_used'   => 'e0',
       'total_mem_alloc'         => 'e1',
       'additional_pool_alloc'   => 'e2',
       'uncheckpointed_bytes'    => 'e3',
       'ibuf_used_cells'         => 'e4',
       'ibuf_free_cells'         => 'e5',
       'ibuf_cell_count'         => 'e6',
      'adaptive_hash_memory'    => 'e7',
      'page_hash_memory'        => 'e8',
      'dictionary_cache_memory' => 'e9',
      'file_system_memory'      => 'ea',
      'lock_system_memory'      => 'eb',
      'recovery_system_memory'  => 'ec',
      'thread_hash_memory'      => 'ed',
      'innodb_sem_waits'        => 'ee',
      'innodb_sem_wait_time_ms' => 'ef',
      'Key_buf_bytes_unflushed' => 'eg',
      'Key_buf_bytes_used'      => 'eh',
      'key_buffer_size'         => 'ei',
      'Innodb_row_lock_time'    => 'ej',
      'Innodb_row_lock_waits'   => 'ek',
      'Query_time_count_00'     => 'el',
      'Query_time_count_01'     => 'em',
      'Query_time_count_02'     => 'en',
      'Query_time_count_03'     => 'eo',
      'Query_time_count_04'     => 'ep',
      'Query_time_count_05'     => 'eq',
      'Query_time_count_06'     => 'er',
      'Query_time_count_07'     => 'es',
      'Query_time_count_08'     => 'et',
      'Query_time_count_09'     => 'eu',
      'Query_time_count_10'     => 'ev',
      'Query_time_count_11'     => 'ew',
      'Query_time_count_12'     => 'ex',
      'Query_time_count_13'     => 'ey',
      'Query_time_total_00'     => 'ez',
      'Query_time_total_01'     => 'fa',
      'Query_time_total_02'     => 'fb',
      'Query_time_total_03'     => 'fc',
      'Query_time_total_04'     => 'fd',
      'Query_time_total_05'     => 'fe',
      'Query_time_total_06'     => 'ff',
      'Query_time_total_07'     => 'fg',
      'Query_time_total_08'     => 'fh',
      'Query_time_total_09'     => 'fi',
      'Query_time_total_10'     => 'fj',
      'Query_time_total_11'     => 'fk',
      'Query_time_total_12'     => 'fl',
      'Query_time_total_13'     => 'fm',
   );

   # Return the output.
   $output = array();
   foreach ($keys as $key => $short ) {
      # If the value isn't defined, return -1 which is lower than (most graphs')
      # minimum value of 0, so it'll be regarded as a missing value.
      $val      = isset($status[$key]) ? $status[$key] : -1;
      $output[] = "$short:$val";
   }
   $result = implode(' ', $output);
   if ( $fp ) {
      if ( fwrite($fp, $result) === FALSE ) {
         die("Can't write '$cache_file'");
      }
      fclose($fp);
   }
   return $result;
}

# ============================================================================
# Given INNODB STATUS text, returns a key-value array of the parsed text.  Each
# line shows a sample of the input for both standard InnoDB as you would find in
# MySQL 5.0, and XtraDB or enhanced InnoDB from Percona if applicable.  Note
# that extra leading spaces are ignored due to trim().
# ============================================================================
function get_innodb_array($text) {
   $results  = array(
      'spin_waits'  => array(),
      'spin_rounds' => array(),
      'os_waits'    => array(),
      'pending_normal_aio_reads'  => null,
      'pending_normal_aio_writes' => null,
      'pending_ibuf_aio_reads'    => null,
      'pending_aio_log_ios'       => null,
      'pending_aio_sync_ios'      => null,
      'pending_log_flushes'       => null,
      'pending_buf_pool_flushes'  => null,
      'file_reads'                => null,
      'file_writes'               => null,
      'file_fsyncs'               => null,
      'ibuf_inserts'              => null,
      'ibuf_merged'               => null,
      'ibuf_merges'               => null,
      'log_bytes_written'         => null,
      'unflushed_log'             => null,
      'log_bytes_flushed'         => null,
      'pending_log_writes'        => null,
      'pending_chkp_writes'       => null,
      'log_writes'                => null,
      'pool_size'                 => null,
      'free_pages'                => null,
      'database_pages'            => null,
      'modified_pages'            => null,
      'pages_read'                => null,
      'pages_created'             => null,
      'pages_written'             => null,
      'queries_inside'            => null,
      'queries_queued'            => null,
      'read_views'                => null,
      'rows_inserted'             => null,
      'rows_updated'              => null,
      'rows_deleted'              => null,
      'rows_read'                 => null,
      'innodb_transactions'       => null,
      'unpurged_txns'             => null,
      'history_list'              => null,
      'current_transactions'      => null,
      'hash_index_cells_total'    => null,
      'hash_index_cells_used'     => null,
      'total_mem_alloc'           => null,
      'additional_pool_alloc'     => null,
      'last_checkpoint'           => null,
      'uncheckpointed_bytes'      => null,
      'ibuf_used_cells'           => null,
      'ibuf_free_cells'           => null,
      'ibuf_cell_count'           => null,
      'adaptive_hash_memory'      => null,
      'page_hash_memory'          => null,
      'dictionary_cache_memory'   => null,
      'file_system_memory'        => null,
      'lock_system_memory'        => null,
      'recovery_system_memory'    => null,
      'thread_hash_memory'        => null,
      'innodb_sem_waits'          => null,
      'innodb_sem_wait_time_ms'   => null,
   );
   $txn_seen = FALSE;
   foreach ( explode("\n", $text) as $line ) {
      $line = trim($line);
      $row = preg_split('/ +/', $line);

      # SEMAPHORES
      if (strpos($line, 'Mutex spin waits') === 0 ) {
         # Mutex spin waits 79626940, rounds 157459864, OS waits 698719
         # Mutex spin waits 0, rounds 247280272495, OS waits 316513438
         $results['spin_waits'][]  = to_int($row[3]);
         $results['spin_rounds'][] = to_int($row[5]);
         $results['os_waits'][]    = to_int($row[8]);
      }
      elseif (strpos($line, 'RW-shared spins') === 0 ) {
         # RW-shared spins 3859028, OS waits 2100750; RW-excl spins 4641946, OS waits 1530310
         $results['spin_waits'][] = to_int($row[2]);
         $results['spin_waits'][] = to_int($row[8]);
         $results['os_waits'][]   = to_int($row[5]);
         $results['os_waits'][]   = to_int($row[11]);
      }
      elseif (strpos($line, 'seconds the semaphore:') > 0) {
         # --Thread 907205 has waited at handler/ha_innodb.cc line 7156 for 1.00 seconds the semaphore:
         increment($results, 'innodb_sem_waits', 1);
         increment($results,
            'innodb_sem_wait_time_ms', to_int($row[9]) * 1000);
      }

      # TRANSACTIONS
      elseif ( strpos($line, 'Trx id counter') === 0 ) {
         # The beginning of the TRANSACTIONS section: start counting
         # transactions
         # Trx id counter 0 1170664159
         # Trx id counter 861B144C
         $results['innodb_transactions'] = make_bigint($row[3], $row[4]);
         $txn_seen = TRUE;
      }
      elseif ( strpos($line, 'Purge done for trx') === 0 ) {
         # Purge done for trx's n:o < 0 1170663853 undo n:o < 0 0
         # Purge done for trx's n:o < 861B135D undo n:o < 0
         $purged_to = make_bigint($row[6], $row[7] == 'undo' ? null : $row[7]);
         $results['unpurged_txns']
            = big_sub($results['innodb_transactions'], $purged_to);
      }
      elseif (strpos($line, 'History list length') === 0 ) {
         # History list length 132
         $results['history_list'] = to_int($row[3]);
      }
      elseif ( $txn_seen && strpos($line, '---TRANSACTION') === 0 ) {
         # ---TRANSACTION 0, not started, process no 13510, OS thread id 1170446656
         increment($results, 'current_transactions', 1);
         if ( strpos($line, 'ACTIVE') > 0 ) {
            increment($results, 'active_transactions', 1);
         }
      }
      elseif ( $txn_seen && strpos($line, '------- TRX HAS BEEN') === 0 ) {
         # ------- TRX HAS BEEN WAITING 32 SEC FOR THIS LOCK TO BE GRANTED:
         increment($results, 'innodb_lock_wait_secs', to_int($row[5]));
      }
      elseif ( strpos($line, 'read views open inside InnoDB') > 0 ) {
         # 1 read views open inside InnoDB
         $results['read_views'] = to_int($row[0]);
      }
      elseif ( strpos($line, 'mysql tables in use') === 0 ) {
         # mysql tables in use 2, locked 2
         increment($results, 'innodb_tables_in_use', to_int($row[4]));
         increment($results, 'innodb_locked_tables', to_int($row[6]));
      }
      elseif ( $txn_seen && strpos($line, 'lock struct(s)') > 0 ) {
         # 23 lock struct(s), heap size 3024, undo log entries 27
         # LOCK WAIT 12 lock struct(s), heap size 3024, undo log entries 5
         # LOCK WAIT 2 lock struct(s), heap size 368
         if ( strpos($line, 'LOCK WAIT') === 0 ) {
            increment($results, 'innodb_lock_structs', to_int($row[2]));
            increment($results, 'locked_transactions', 1);
         }
         else {
            increment($results, 'innodb_lock_structs', to_int($row[0]));
         }
      }

      # FILE I/O
      elseif (strpos($line, ' OS file reads, ') > 0 ) {
         # 8782182 OS file reads, 15635445 OS file writes, 947800 OS fsyncs
         $results['file_reads']  = to_int($row[0]);
         $results['file_writes'] = to_int($row[4]);
         $results['file_fsyncs'] = to_int($row[8]);
      }
      elseif (strpos($line, 'Pending normal aio reads:') === 0 ) {
         # Pending normal aio reads: 0, aio writes: 0,
         $results['pending_normal_aio_reads']  = to_int($row[4]);
         $results['pending_normal_aio_writes'] = to_int($row[7]);
      }
      elseif (strpos($line, 'ibuf aio reads') === 0 ) {
         #  ibuf aio reads: 0, log i/o's: 0, sync i/o's: 0
         $results['pending_ibuf_aio_reads'] = to_int($row[3]);
         $results['pending_aio_log_ios']    = to_int($row[6]);
         $results['pending_aio_sync_ios']   = to_int($row[9]);
      }
      elseif ( strpos($line, 'Pending flushes (fsync)') === 0 ) {
         # Pending flushes (fsync) log: 0; buffer pool: 0
         $results['pending_log_flushes']      = to_int($row[4]);
         $results['pending_buf_pool_flushes'] = to_int($row[7]);
      }

      # INSERT BUFFER AND ADAPTIVE HASH INDEX
      elseif (strpos($line, 'Ibuf for space 0: size ') === 0 ) {
         # Older InnoDB code seemed to be ready for an ibuf per tablespace.  It
         # had two lines in the output.  Newer has just one line, see below.
         # Ibuf for space 0: size 1, free list len 887, seg size 889, is not empty
         # Ibuf for space 0: size 1, free list len 887, seg size 889,
         $results['ibuf_used_cells']  = to_int($row[5]);
         $results['ibuf_free_cells']  = to_int($row[9]);
         $results['ibuf_cell_count']  = to_int($row[12]);
      }
      elseif (strpos($line, 'Ibuf: size ') === 0 ) {
         # Ibuf: size 1, free list len 4634, seg size 4636,
         $results['ibuf_used_cells']  = to_int($row[2]);
         $results['ibuf_free_cells']  = to_int($row[6]);
         $results['ibuf_cell_count']  = to_int($row[9]);
      }
      elseif (strpos($line, ' merged recs, ') > 0 ) {
         # 19817685 inserts, 19817684 merged recs, 3552620 merges
         $results['ibuf_inserts'] = to_int($row[0]);
         $results['ibuf_merged']  = to_int($row[2]);
         $results['ibuf_merges']  = to_int($row[5]);
      }
      elseif (strpos($line, 'Hash table size ') === 0 ) {
         # In some versions of InnoDB, the used cells is omitted.
         # Hash table size 4425293, used cells 4229064, ....
         # Hash table size 57374437, node heap has 72964 buffer(s) <-- no used cells
         $results['hash_index_cells_total'] = to_int($row[3]);
         $results['hash_index_cells_used']
            = strpos($line, 'used cells') > 0 ? to_int($row[6]) : '0';
      }

      # LOG
      elseif (strpos($line, " log i/o's done, ") > 0 ) {
         # 3430041 log i/o's done, 17.44 log i/o's/second
         # 520835887 log i/o's done, 17.28 log i/o's/second, 518724686 syncs, 2980893 checkpoints
         # TODO: graph syncs and checkpoints
         $results['log_writes'] = to_int($row[0]);
      }
      elseif (strpos($line, " pending log writes, ") > 0 ) {
         # 0 pending log writes, 0 pending chkp writes
         $results['pending_log_writes']  = to_int($row[0]);
         $results['pending_chkp_writes'] = to_int($row[4]);
      }
      elseif (strpos($line, "Log sequence number") === 0 ) {
         # This number is NOT printed in hex in InnoDB plugin.
         # Log sequence number 13093949495856 //plugin
         # Log sequence number 125 3934414864 //normal
         $results['log_bytes_written']
            = isset($row[4])
            ? make_bigint($row[3], $row[4])
            : to_int($row[3]);
      }
      elseif (strpos($line, "Log flushed up to") === 0 ) {
         # This number is NOT printed in hex in InnoDB plugin.
         # Log flushed up to   13093948219327
         # Log flushed up to   125 3934414864
         $results['log_bytes_flushed']
            = isset($row[5])
            ? make_bigint($row[4], $row[5])
            : to_int($row[4]);
      }
      elseif (strpos($line, "Last checkpoint at") === 0 ) {
         # Last checkpoint at  125 3934293461
         $results['last_checkpoint']
            = isset($row[4])
            ? make_bigint($row[3], $row[4])
            : to_int($row[3]);
      }

      # BUFFER POOL AND MEMORY
      elseif (strpos($line, "Total memory allocated") === 0 ) {
         # Total memory allocated 29642194944; in additional pool allocated 0
         $results['total_mem_alloc']       = to_int($row[3]);
         $results['additional_pool_alloc'] = to_int($row[8]);
      }
      elseif(strpos($line, 'Adaptive hash index ') === 0 ) {
         #   Adaptive hash index 1538240664 	(186998824 + 1351241840)
         $results['adaptive_hash_memory'] = to_int($row[3]);
      }
      elseif(strpos($line, 'Page hash           ') === 0 ) {
         #   Page hash           11688584
         $results['page_hash_memory'] = to_int($row[2]);
      }
      elseif(strpos($line, 'Dictionary cache    ') === 0 ) {
         #   Dictionary cache    145525560 	(140250984 + 5274576)
         $results['dictionary_cache_memory'] = to_int($row[2]);
      }
      elseif(strpos($line, 'File system         ') === 0 ) {
         #   File system         313848 	(82672 + 231176)
         $results['file_system_memory'] = to_int($row[2]);
      }
      elseif(strpos($line, 'Lock system         ') === 0 ) {
         #   Lock system         29232616 	(29219368 + 13248)
         $results['lock_system_memory'] = to_int($row[2]);
      }
      elseif(strpos($line, 'Recovery system     ') === 0 ) {
         #   Recovery system     0 	(0 + 0)
         $results['recovery_system_memory'] = to_int($row[2]);
      }
      elseif(strpos($line, 'Threads             ') === 0 ) {
         #   Threads             409336 	(406936 + 2400)
         $results['thread_hash_memory'] = to_int($row[1]);
      }
      elseif(strpos($line, 'innodb_io_pattern   ') === 0 ) {
         #   innodb_io_pattern   0 	(0 + 0)
         $results['innodb_io_pattern_memory'] = to_int($row[1]);
      }
      elseif (strpos($line, "Buffer pool size ") === 0 ) {
         # The " " after size is necessary to avoid matching the wrong line:
         # Buffer pool size        1769471
         # Buffer pool size, bytes 28991012864
         $results['pool_size'] = to_int($row[3]);
      }
      elseif (strpos($line, "Free buffers") === 0 ) {
         # Free buffers            0
         $results['free_pages'] = to_int($row[2]);
      }
      elseif (strpos($line, "Database pages") === 0 ) {
         # Database pages          1696503
         $results['database_pages'] = to_int($row[2]);
      }
      elseif (strpos($line, "Modified db pages") === 0 ) {
         # Modified db pages       160602
         $results['modified_pages'] = to_int($row[3]);
      }
      elseif (strpos($line, "Pages read ahead") === 0 ) {
         # Must do this BEFORE the next test, otherwise it'll get fooled by this
         # line from the new plugin (see samples/innodb-015.txt):
         # Pages read ahead 0.00/s, evicted without access 0.06/s
         # TODO: No-op for now, see issue 134.
      }
      elseif (strpos($line, "Pages read") === 0 ) {
         # Pages read 15240822, created 1770238, written 21705836
         $results['pages_read']    = to_int($row[2]);
         $results['pages_created'] = to_int($row[4]);
         $results['pages_written'] = to_int($row[6]);
      }

      # ROW OPERATIONS
      elseif (strpos($line, 'Number of rows inserted') === 0 ) {
         # Number of rows inserted 50678311, updated 66425915, deleted 20605903, read 454561562
         $results['rows_inserted'] = to_int($row[4]);
         $results['rows_updated']  = to_int($row[6]);
         $results['rows_deleted']  = to_int($row[8]);
         $results['rows_read']     = to_int($row[10]);
      }
      elseif (strpos($line, " queries inside InnoDB, ") > 0 ) {
         # 0 queries inside InnoDB, 0 queries in queue
         $results['queries_inside'] = to_int($row[0]);
         $results['queries_queued'] = to_int($row[4]);
      }
   }

   foreach ( array('spin_waits', 'spin_rounds', 'os_waits') as $key ) {
      $results[$key] = to_int(array_sum($results[$key]));
   }
   $results['unflushed_log']
      = big_sub($results['log_bytes_written'], $results['log_bytes_flushed']);
   $results['uncheckpointed_bytes']
      = big_sub($results['log_bytes_written'], $results['last_checkpoint']);

   return $results;
}


# ============================================================================
# Returns a bigint from two ulint or a single hex number.  This is tested in
# t/mysql_stats.php and copied, without tests, to ss_get_by_ssh.php.
# ============================================================================
function make_bigint ($hi, $lo = null) {
   debug(array($hi, $lo));
   if ( is_null($lo) ) {
      # Assume it is a hex string representation.
      return base_convert($hi, 16, 10);
   }
   else {
      $hi = $hi ? $hi : '0'; # Handle empty-string or whatnot
      $lo = $lo ? $lo : '0';
      return big_add(big_multiply($hi, 4294967296), $lo);
   }
}

# ============================================================================
# Extracts the numbers from a string.  You can't reliably do this by casting to
# an int, because numbers that are bigger than PHP's int (varies by platform)
# will be truncated.  And you can't use sprintf(%u) either, because the maximum
# value that will return on some platforms is 4022289582.  So this just handles
# them as a string instead.  It extracts digits until it finds a non-digit and
# quits.  This is tested in t/mysql_stats.php and copied, without tests, to
# ss_get_by_ssh.php.
# ============================================================================
function to_int ( $str ) {
   debug($str);
   global $debug;
   preg_match('{(\d+)}', $str, $m);
   if ( isset($m[1]) ) {
      return $m[1];
   }
   elseif ( $debug ) {
      print_r(debug_backtrace());
   }
   else {
      return 0;
   }
}

# ============================================================================
# Wrap mysql_query in error-handling, and instead of returning the result,
# return an array of arrays in the result.
# ============================================================================
function run_query($sql, $conn) {
   global $debug;
   debug($sql);
   $result = @mysql_query($sql, $conn);
   if ( $debug ) {
      $error = @mysql_error($conn);
      if ( $error ) {
         debug(array($sql, $error));
         die("SQLERR $error in $sql");
      }
   }
   $array = array();
   while ( $row = @mysql_fetch_array($result) ) {
      $array[] = $row;
   }
   debug(array($sql, $array));
   return $array;
}

# ============================================================================
# Safely increments a value that might be null.
# ============================================================================
function increment(&$arr, $key, $howmuch) {
   debug(array($key, $howmuch));
   if ( array_key_exists($key, $arr) && isset($arr[$key]) ) {
      $arr[$key] = big_add($arr[$key], $howmuch);
   }
   else {
      $arr[$key] = $howmuch;
   }
}

# ============================================================================
# Multiply two big integers together as accurately as possible with reasonable
# effort.  This is tested in t/mysql_stats.php and copied, without tests, to
# ss_get_by_ssh.php.  $force is for testability.
# ============================================================================
function big_multiply ($left, $right, $force = null) {
   if ( function_exists("gmp_mul") && (is_null($force) || $force == 'gmp') ) {
      debug(array('gmp_mul', $left, $right));
      return gmp_strval( gmp_mul( $left, $right ));
   }
   elseif ( function_exists("bcmul") && (is_null($force) || $force == 'bc') ) {
      debug(array('bcmul', $left, $right));
      return bcmul( $left, $right );
   }
   else { # Or $force == 'something else'
      debug(array('sprintf', $left, $right));
      return sprintf("%.0f", $left * $right);
   }
}

# ============================================================================
# Subtract two big integers as accurately as possible with reasonable effort.
# This is tested in t/mysql_stats.php and copied, without tests, to
# ss_get_by_ssh.php.  $force is for testability.
# ============================================================================
function big_sub ($left, $right, $force = null) {
   debug(array($left, $right));
   if ( is_null($left)  ) { $left = 0; }
   if ( is_null($right) ) { $right = 0; }
   if ( function_exists("gmp_sub") && (is_null($force) || $force == 'gmp')) {
      debug(array('gmp_sub', $left, $right));
      return gmp_strval( gmp_sub( $left, $right ));
   }
   elseif ( function_exists("bcsub") && (is_null($force) || $force == 'bc')) {
      debug(array('bcsub', $left, $right));
      return bcsub( $left, $right );
   }
   else { # Or $force == 'something else'
      debug(array('to_int', $left, $right));
      return to_int($left - $right);
   }
}

# ============================================================================
# Add two big integers together as accurately as possible with reasonable
# effort.  This is tested in t/mysql_stats.php and copied, without tests, to
# ss_get_by_ssh.php.  $force is for testability.
# ============================================================================
function big_add ($left, $right, $force = null) {
   if ( is_null($left)  ) { $left = 0; }
   if ( is_null($right) ) { $right = 0; }
   if ( function_exists("gmp_add") && (is_null($force) || $force == 'gmp')) {
      debug(array('gmp_add', $left, $right));
      return gmp_strval( gmp_add( $left, $right ));
   }
   elseif ( function_exists("bcadd") && (is_null($force) || $force == 'bc')) {
      debug(array('bcadd', $left, $right));
      return bcadd( $left, $right );
   }
   else { # Or $force == 'something else'
      debug(array('to_int', $left, $right));
      return to_int($left + $right);
   }
}

# ============================================================================
# Writes to a debugging log.
# ============================================================================
function debug($val) {
   global $debug_log;
   if ( !$debug_log ) {
      return;
   }
   if ( $fp = fopen($debug_log, 'a+') ) {
      $trace = debug_backtrace();
      $calls = array();
      $i    = 0;
      $line = 0;
      $file = '';
      foreach ( debug_backtrace() as $arr ) {
         if ( $i++ ) {
            $calls[] = "$arr[function]() at $file:$line";
         }
         $line = array_key_exists('line', $arr) ? $arr['line'] : '?';
         $file = array_key_exists('file', $arr) ? $arr['file'] : '?';
      }
      if ( !count($calls) ) {
         $calls[] = "at $file:$line";
      }
      fwrite($fp, date('Y-m-d h:i:s') . ' ' . implode(' <- ', $calls));
      fwrite($fp, "\n" . var_export($val, TRUE) . "\n");
      fclose($fp);
   }
   else { # Disable logging
      print("Warning: disabling debug logging to $debug_log\n");
      $debug_log = FALSE;
   }
}

?>
