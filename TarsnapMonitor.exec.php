<?
/* Require stuff */
require 'TarsnapMonitor.class.php';


/* Set up the object, passing e-mail address and password */
$t = new TarsnapMonitor('YOUR TARSNAP USERNAME','YOUR TARSNAP PASSWORD');


/* Get the current balance (for usage below) */
$balance = $t->get_current_balance();


/* Print stats */
echo "Average Daily Usage: ".$t->get_avg_daily_usage()."\n";
echo "Current Balance: ".$balance['BALANCE']."\n";
echo "ETA: ".($t->get_eta()/86400)." days\n";
?>
