<?php


//display all errors
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$sysinfo_path = __DIR__ . '/scripts/sysinfo.sh';
$bin_path = "/web/private/bin";
//if bin dir doesn't exist, create it
if (!is_dir($bin_path)) {
    die("Bin directory not found at $bin_path");
}
//if sysinfo script doesn't exist in bin, copy it
if (!file_exists($sysinfo_path)) {    
    die("Sysinfo script not found at $sysinfo_path");
}


$sysinfo_script = "/web/private/bin/sysinfo";
//run hourly sysinfo script
//stores in db
$db_path = '/web/private/db/inbox/sysinfo_new.db';

if (file_exists($db_path)) {
    $db = new SQLite3($db_path, SQLITE3_OPEN_READONLY);
} else {
    die("Sysinfo database not found at $db_path");
}

// Get database schema (tables and structure)
echo "<h2>Sysinfo Database Schema</h2>";
echo "<p>Database: " . htmlspecialchars($db_path) . "</p>";
echo "<pre>@hourly /usr/bin/env -S bash /web/private/bin/sysinfo http://localhost/v1/inbox/ >> /web/private/logs/sysinfo_local.log 2>&1</pre>";
// Get all tables
$tables_res = $db->query("SELECT name, sql FROM sqlite_master WHERE type='table' ORDER BY name");
echo "<h3>Tables:</h3>";

$table_count = 0;
while ($table = $tables_res->fetchArray(SQLITE3_ASSOC)) {
    $table_count++;
    $table_name = $table['name'];
    echo "<h4>Table: " . htmlspecialchars($table_name) . "</h4>";
    
    // Show CREATE TABLE statement
    echo "<pre>" . htmlspecialchars($table['sql']) . "</pre>";
    
    // Show row count
    $count_res = $db->querySingle("SELECT COUNT(*) FROM " . $table_name);
    echo "<p>Rows: " . $count_res . "</p>";
    
    // Show sample data (first 5 rows)
    $sample_res = $db->query("SELECT * FROM " . $table_name . " LIMIT 5");
    if ($sample_res) {
        echo "<p><strong>Sample data (first 5 rows):</strong></p>";
        echo "<table border='1' cellpadding='5' cellspacing='0' style='border-collapse: collapse;'>";
        
        $first_row = true;
        while ($row = $sample_res->fetchArray(SQLITE3_ASSOC)) {
            if ($first_row) {
                // Print headers
                echo "<tr style='background: #ddd;'>";
                foreach (array_keys($row) as $col) {
                    echo "<th>" . htmlspecialchars($col) . "</th>";
                }
                echo "</tr>";
                $first_row = false;
            }
            
            // Print data
            echo "<tr>";
            foreach ($row as $val) {
                echo "<td>" . htmlspecialchars($val ?? 'NULL') . "</td>";
            }
            echo "</tr>";
        }
        echo "</table>";
    }
    
    echo "<hr>";
}

if ($table_count === 0) {
    echo "<p>No tables found in database.</p>";
}




?>