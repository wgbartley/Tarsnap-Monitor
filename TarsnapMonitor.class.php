<?
/*
* TarsnapMonitor.class.php
*
* Copyright (C) 2010 Garrett Bartley
*
* This program is free software; you can redistribute it and/or
* modify it under the terms of the GNU General Public License
* as published by the Free Software Foundation; either version
* 2 of the License, or (at your option) any later version.
*
* This program is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
* GNU General Public License for more details. You should have
* received a copy of the GNU General Public License along with
* this program; if not, write to the Free Software Foundation,
* Inc., 59 Temple Place, Suite 330, Boston, MA 02111-1307 USA
*/

class TarsnapMonitor {
	private $username, $password, $curl, $csv, $array, $db, $version;

	function __construct($username, $password) {
		$this->version = '1.0'; 

		$this->username = trim($username);
		$this->password = trim($password);

		$this->init();

		$this->load_db();
	}


	function __destruct() {
		if(isset($this->db))
			$this->db->close();
	}


	/* Not much to init except for the cURL stuff */
	private function init() {
		$this->curl = curl_init();

		curl_setopt_array($this->curl, array(
			CURLOPT_RETURNTRANSFER => TRUE,
			CURLOPT_TIMEOUT => 10,
			CURLOPT_FRESH_CONNECT => TRUE,
			CURLOPT_MAXCONNECTS => 1,
			CURLOPT_HEADER => FALSE,
			CURLOPT_ENCODING => 'gzip',
			CURLOPT_USERAGENT => 'TarsnapMonitor.class.php v'.$this->version
		));
	}


	/* Create a SQLite3 database in memory */
	private function load_db() {
		/* If we haven't loaded the CSV data from Tarsnap ... */
		if(strlen($this->csv)==0)
			$this->get_csv();

		/* If we haven't converted the CSV data from Tarsnap into a usable array ... */
		if(count($this->array)==0)
			$this->get_array();

		/* Create the database in memory */
		$this->db = new SQLite3(':memory:');

		/* Create a table that mimics the CSV */
		$query = <<<EOQ
CREATE TABLE tarsnap(
	RECTYPE VARCHAR(20),
	DATE DATE,
	MACHINE VARCHAR(100),
	TYPE VARCHAR(100),
	QUANTITY INTEGER,
	AMOUNT REAL,
	BALANCE REAL
);
EOQ;
		$this->db->query($query);

		/* Loop through the arrayed CSV and insert it into the table */
		foreach($this->array as $a) {
			$query = "INSERT INTO tarsnap (".implode(',',array_keys($a)).") VALUES ('".implode("','",$a)."')";
			$this->db->query($query);
		}
	}


	/* Make a cURL call to Tarsnap to get the CSV usage data */
	public function get_csv() {
		$url = 'https://www.tarsnap.com/manage.cgi';

		$post_str = 'address='.$this->username.'&password='.$this->password.'&action=verboseactivity&format=csv';

		curl_setopt($this->curl, CURLOPT_POST, TRUE);
		curl_setopt($this->curl, CURLOPT_POSTFIELDS, $post_str);

		curl_setopt($this->curl, CURLOPT_URL, $url);

		$this->csv = trim(curl_exec($this->curl));

		if(stripos($this->csv, '<html>')!==FALSE) {
			echo "Invalid username and/or password.\n";
			exit;
		}

		return $this->csv;
	}


	/* Convert the CSV usage data from Tarsnap into an array */
	public function get_array() {
		$retval = array();
		$records = array();

		if(strlen($this->csv)==0)
			$this->csv = $this->get_csv();

		if(!function_exists('str_getcsv')) {
			/* Write to a temporary file */
			$fh = 'php://temp';
			fwrite($fh, $this->csv);

			/* Be kind, please rewind */
			rewind($fh);

			/* Read it back with fgetcsv() */
			while(!feof($fh))
				$records[] = fgetcsv($fh);

			fclose($fh);
		} else {
			$csv = explode("\n", $this->csv);
			foreach($csv as $line) {
				$line = trim($line);
				$records[] = str_getcsv($line);
			}
		}

		/* Set headers */
		$headers = $records[0];
		unset($records[0]);
		$retval = array();

		/* Change record keys to headers */
		foreach($records as $r) {
			$arr = array();

			foreach($headers as $h_k => $h_v) {
				$arr[$h_v] = $r[$h_k];
			}

			$retval[] = $arr;
		}

		$this->array = $retval;

		return $this->array;
	}


	/* Get all balances -- basically the arrayed CSV from Tarsnap */
	public function get_balances() {
		$retval = array();

		$results = $this->db->query("SELECT DATE,BALANCE FROM tarsnap WHERE RECTYPE='Balance' ORDER BY DATE ASC");

		while($row=$results->fetchArray())
			$retval[] = $row;

		return $retval;
	}


	/* Get the most recent balance and date */
	public function get_current_balance() {
		return $this->db->querySingle("SELECT MAX(DATE) AS DATE,BALANCE FROM tarsnap WHERE RECTYPE='Balance'", TRUE);
	}


	/* Get the highest balance and date */
	public function get_max_balance() {
		return $this->db->querySingle("SELECT DATE,MAX(BALANCE) AS BALANCE FROM tarsnap WHERE RECTYPE='Balance'", TRUE);
	}


	/* Get the lowest balance and date */
	public function get_min_balance() {
		return $this->db->querySingle("SELECT DATE,MIN(BALANCE) AS BALANCE FROM tarsnap WHERE RECTYPE='Balance'", TRUE);
	}


	/* Get the earliest date */
	public function get_min_date() {
		return $this->db->querySingle("SELECT MIN(DATE) AS DATE FROM tarsnap WHERE RECTYPE='Balance'", TRUE);
	}


	/* Get the latest date */
	public function get_max_date() {
		return $this->db->querySingle("SELECT MAX(DATE) AS DATE FROM tarsnap WHERE RECTYPE='Balance'", TRUE);
	}


	/* Get the max storage used and date */
	public function get_max_storage() {
		return $this->db->querySingle("SELECT DATE,MAX(QUANTITY) AS QUANTITY FROM tarsnap WHERE RECTYPE='Usage' AND TYPE='Daily storage'", TRUE);
	}


	/* Get the least storage used and date */
	public function get_min_storage() {
		return $this->db->querySingle("SELECT DATE,MIN(QUANTITY) AS QUANTITY FROM tarsnap WHERE RECTYPE='Usage' AND TYPE='Daily storage'", TRUE);
	}


	/* Get the max client->server bandwidth usage and date */
	public function get_max_clientserver() {
		return $this->db->querySingle("SELECT DATE,MAX(QUANTITY) AS QUANTITY FROM tarsnap WHERE RECTYPE='Usage' AND TYPE='Client->Server bandwidth'", TRUE);
	}


	/* Get the lowest client->server bandwidth usage and date */
	public function get_min_clientserver() {
		return $this->db->querySingle("SELECT DATE,MIN(QUANTITY) AS QUANTITY FROM tarsnap WHERE RECTYPE='Usage' AND TYPE='Client->Server bandwidth'", TRUE);
	}


	/* Get the max server->client bandwidth usage and date */
	public function get_max_serverclient() {
		return $this->db->querySingle("SELECT DATE,MAX(QUANTITY) AS QUANTITY FROM tarsnap WHERE RECTYPE='Usage' AND TYPE='Server->Client bandwidth'", TRUE);
	}


	/* Get the lowest server->client bandwidth usage and date */
	public function get_min_serverclient() {
		return $this->db->querySingle("SELECT DATE,MIN(QUANTITY) AS QUANTITY FROM tarsnap WHERE RECTYPE='Usage' AND TYPE='Server->Client bandwidth'", TRUE);
	}


	/* Calculate the average daily usage from all balanes */
	public function get_avg_daily_usage() {
		/* Empty array to hold the balance differences between days */
		$diffs = array();

		/* Load the balances */
		$balances = array();
		foreach(self::get_balances() as $b)
			$balances[$b['DATE']] = $b['BALANCE'];

		/* Get the earliest date to start and convert it to a Unix timestamp */
		$date_start = self::get_min_date();
		$date_start = strtotime($date_start['DATE']);

		/* Get the latest (current) date to stop and convert it to a Unix timestamp */
		$date_stop = self::get_max_date();
		$date_stop = strtotime($date_stop['DATE']);

		/* Set this to FALSE so we know we're at the beginning balance */
		$balance_last = FALSE;

		/* Set the "current" date. Current is relevant to the loop. */
		$date_cur = $date_start;
		while($date_cur<=$date_stop) {
			/* Set a 0 balance just in case */
			$balance_this = 0;

			/* If there is a balance for $date_cur, use it for $balance_this */
			if(isset($balances[date('Y-m-d', $date_cur)]))
				$balance_this = $balances[date('Y-m-d', $date_cur)];

			/* If this is NOT the first iteration (basically), store the calculated difference */
			if($balance_last!==FALSE)
				$diffs[date('Y-m-d', $date_cur)] = $balance_last-$balance_this;

			/* And reset $balance_last */
			$balance_last = $balance_this;

			/* Add one day (86400 seconds) */
			$date_cur += 86400;
		}

		/* Return the average (sum/count) */
		return array_sum($diffs)/count($diffs);
	}


	/* Get the best-guess ETA (in seconds) as to when the balance will run out */
	public function get_eta() {
		$balance = self::get_current_balance();
		return $balance['BALANCE']/self::get_avg_daily_usage()*86400;
	}
}
?>
