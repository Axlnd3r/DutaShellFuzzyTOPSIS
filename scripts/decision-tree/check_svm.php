<?php
$db = new mysqli('127.0.0.1', 'root', '', 'expertt2', 3307);
$r = $db->query("SELECT * FROM inferensi_user_1 WHERE case_id='50' AND rule_id='SVM'");
if($r && $r->num_rows > 0) {
    while($row = $r->fetch_assoc()) {
        print_r($row);
    }
} else {
    echo "No SVM result found for case_id=50\n";
}

// Also show recent SVM entries
echo "\nRecent SVM entries:\n";
$r2 = $db->query("SELECT inf_id, case_id, rule_id, rule_goal, created_at FROM inferensi_user_1 WHERE rule_id='SVM' ORDER BY inf_id DESC LIMIT 10");
if ($r2 && $r2->num_rows > 0) {
    while($row = $r2->fetch_assoc()) {
        echo "inf_id={$row['inf_id']}, case_id={$row['case_id']}, rule_goal={$row['rule_goal']}, created_at={$row['created_at']}\n";
    }
}
