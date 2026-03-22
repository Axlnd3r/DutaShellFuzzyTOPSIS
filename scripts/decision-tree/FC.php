<?php
// fungsi ini untuk melakukan inferensi melalui pencocokan rule

// Load database config from Laravel .env
$envPath = __DIR__ . '/../../.env';
$dbConfig = [
    'host' => '127.0.0.1',
    'port' => 3306,
    'database' => 'expertt',
    'username' => 'root',
    'password' => ''
];

if (file_exists($envPath)) {
    $envContent = file_get_contents($envPath);
    if (preg_match('/^DB_HOST=(.*)$/m', $envContent, $m)) $dbConfig['host'] = trim($m[1]);
    if (preg_match('/^DB_PORT=(\d+)/m', $envContent, $m)) $dbConfig['port'] = (int)trim($m[1]);
    if (preg_match('/^DB_DATABASE=(.*)$/m', $envContent, $m)) $dbConfig['database'] = trim($m[1]);
    if (preg_match('/^DB_USERNAME=(.*)$/m', $envContent, $m)) $dbConfig['username'] = trim($m[1]);
    if (preg_match('/^DB_PASSWORD=(.*)$/m', $envContent, $m)) $dbConfig['password'] = trim($m[1]);
}

$conn = new mysqli($dbConfig['host'], $dbConfig['username'], $dbConfig['password'], $dbConfig['database'], $dbConfig['port']);
if ($conn->connect_error) {
  die("Connection failed: " . $conn->connect_error);
}

// menangkap user_id yang aktif
$user_id = $argv[1];
$case_num = $argv[2];
$awal = microtime(true);
//create tabel temp utk menyimpan inferensi
$tabelinferensi="inferensi_fc_user_".$user_id;

$result = $conn->query("SHOW TABLES LIKE '$tabelinferensi'");

// periksa apakah tabel sudah ada
if ($result->num_rows > 0) {
    // Tambah kolom created_at jika belum ada
    $colCheck = $conn->query("SHOW COLUMNS FROM $tabelinferensi LIKE 'created_at'");
    if ($colCheck && $colCheck->num_rows == 0) {
        $conn->query("ALTER TABLE $tabelinferensi ADD COLUMN `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP");
    }
} else {
    //membentuk tabel untuk proses
    $sql = "CREATE table $tabelinferensi (
            `inf_id` int(11) primary key NOT NULL AUTO_INCREMENT,
            `case_id` varchar(100) NOT NULL,
            `case_goal` varchar(200) NOT NULL,
            `rule_id` varchar(100) NOT NULL,
            `rule_goal` varchar(200) NOT NULL,
            `match_value` decimal(5,4) NOT NULL,
            `cocok` enum('1','0') NOT NULL,
            `user_id` int(11) NOT NULL,
            `waktu` DECIMAL(16,14) NOT NULL DEFAULT 0,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
            )";

    if ($conn->query($sql) === TRUE) {
        echo "create table successfully";
    } else {
        echo "Error creating tabel: " . $conn->error;
    }
}

// Ambil case_id yang sudah pernah diproses untuk menghindari duplikasi
$processedCaseIds = [];
$existingRes = $conn->query("SELECT case_id FROM $tabelinferensi");
if ($existingRes) {
    while ($row = $existingRes->fetch_assoc()) {
        $processedCaseIds[$row['case_id']] = true;
    }
}

/* urutan algoritma FC
1. ekstrak fakta pada test case
2. lihat aturan pada rule base
3. lakukan ekstraksi fakta pada rule
4. lakukan inferensi dengan mencari kecocokan antara rule dengan fakta pada test case.
   lakukan pencocokan untuk semua fakta pada setiap rule. 
   gunakan fungsi explode() untuk mengambil fakta pada rule. 
   cari fakta pada test case yang sesuai dengan menggunakan fungsi strstr()
   abaikan pencocokan pada kata "dan"
5. Hitung banyaknya fakta pada rule dan bandingkan banyaknya fakta yang cocok.
   jika jumlah sama maka goal ditemukan. proses inferensi dihentikan. rekam goal dari rule
6. lakukan perulangan sampai rule habis atau kecocokan 100%.
7. jika rule sudah habis dan belum ada yang cocok 100% maka hitung tingkat kecocokan terbaik
   tingkat kecocokan dihitung menggunakan rumus: jumlah fakta cocok/jumlah seluruh fakta
8. simpan hasil proses pada tabel inferensi. 
   Salin goal pada test case; simpan goal hasil inferensi dengan kecocokan tertinggi; 
   jika goal sama maka tandai atribut match dengan 1; simpan data lain sesuai dengan data inferensi.
9. lakukan perulangan untuk setiap test case
10. jgn lupa mencatat waktu proses.
*/ 

// melakukan inferensi melalui algoritma Forward Chaining antara kasus dengan rule yang dibentuk dari C45
// 1. Mengakses data kasus 
$tabelkasus="test_case_user_".$user_id;
$firstdata=1;
$casedata = "select * from $tabelkasus WHERE algoritma = 'Forward Chaining'";
if ($result=mysqli_query($conn,$casedata)){
    // menentukan banyak atribut
    $fieldcount=mysqli_num_fields($result);
    //menentukan nama atribut goal yang dideteksi pada posisi ke - dari belakang
    $fieldgoal=$result -> fetch_field_direct($fieldcount-3);
    $namaatributgoal= $fieldgoal -> name;
    // menentukan jumlah fakta dengan rumus: jumlah atribut-4
    $factnum=$fieldcount-4;
    //echo "jumlah fakta: ".$factnum."<br>";
    //menyimpan nama semua fakta
    $i=1;
    for ($i; $i<$factnum+1; $i++)
    {
        $fieldatt=$result -> fetch_field_direct($i);
        $namaatributfact= $fieldatt -> name;
        //buat array penampung atribut
        if ($firstdata==1) {
            $factnamearray=array($namaatributfact);
            $firstdata=0;
        } else {
            $pusharrayfact=$namaatributfact;
            $factnamearray[]=$pusharrayfact;
        }
        //echo "..nama atribut".$namaatributfact."<br>";
    } 
}
// membuat pernyataan fakta kasus
$casefactquery = $conn->query($casedata);
while ($row= $casefactquery->fetch_assoc()) {
    // mengambil atribut kasus
    $faktacase="";
    $case_id=$row["case_id"];

    // Skip jika case_id sudah pernah diproses
    if (isset($processedCaseIds[$case_id])) {
        continue;
    }

    // mengambil atribut goal
    $goalstt=$namaatributgoal."=".$row["$namaatributgoal"];
    //echo $case_id." pernyataan goal: ".$goalstt."<br>";
    // mengambil fakta kasus
    for ($brs=0; $brs<$factnum; $brs++)
    {
        $factnamestt=$factnamearray[$brs];
        $factstt=$factnamestt."=".$row["$factnamestt"];

        $faktacase=$faktacase." ".$factstt;
    }
    //echo "case_id:".$case_id."  pernyataan fakta: ".$faktacase."<br>";

// 2. lihat aturan pada rule base
    $hasilcocok=$cocok=$simpan=$matchvalue=$matchvaluebr=$prob=0;
    $tabelrule="rule_user_".$user_id;
    $ruledata = $conn->query("select * from $tabelrule");
    while ($row= $ruledata->fetch_assoc()) {
        $rule_id=$row["rule_id"];
        $if_part=$row["if_part"];
        $then_part=$row["then_part"];
        //menghitung jumlah fakta setiap rule dengan medeteksi banyaknya karakter "=" pada if_part 
        // pendeteksian karakter menggunakan fungsi substr_count()
        $jumlahfakta=substr_count($if_part,"=");
        // mendapatkan fakta pada setiap rule menggunakan fungsi explode()
        $faktarule=explode(" ",$if_part);
        // menghitung jumlah string pada fakta
        $jlhstring=count($faktarule);
        // melakukan perulangan untuk mengetahui apakah semua fakta terpenuhi pada test case
        $i=0;
        $ada=0;
        for ($i;$i<$jlhstring;$i++)
        {
            //melakukan pencarian jika string tidak sama dengan "and"
            $isifaktarule=$faktarule[$i];
            if ($isifaktarule<>"and")
            {
                //lakukan pencarian apakah fakta terdapat pada test case dengan fungsi strstr
                if (strstr($faktacase,$isifaktarule))
                {
                    $ada=$ada+1;
                    $matchvaluebr=$ada/$jumlahfakta;
                };
            }
        }

        //echo $case_id."...."."rule_id"."----".$ada."..".$jumlahfakta."<br>";
        // periksa apakah cocok = jumlah fakta
        // jika iya maka goal sudah ditemukan dan keluar dari proses inferensi utk kasus ini
        if ($ada==$jumlahfakta)
        {
           if ($goalstt==$then_part)
           {
             $cocok='1';
           } else {$cocok='0';}
            $matchvalue=1;

            $akhir = microtime(true);
            $lama = $akhir - $awal;

            // simpan data inferensi
            $sql = "INSERT INTO $tabelinferensi (inf_id, case_id, case_goal, rule_id, rule_goal, match_value, cocok, user_id, waktu, created_at)
           VALUES ($case_id, $case_id, '$goalstt', $rule_id, '$then_part', $matchvalue, '$cocok', $user_id, $lama, NOW())";
           
            if (mysqli_query($conn, $sql)) {
                //echo "New record created successfully".$case_id."....".$rule_id."<br>";
                $simpan = 1;
            } else {
                echo "Error: " . $sql . "<br>" . mysqli_error($conn);
            }
            // keluar dari perulangan
            break;
        } 
        
        if ($ada > 0 and $matchvaluebr>$matchvalue)
        {
            // tidak ditemukan rule yg cocok dengan fakta 100%
            // simpan hasil kesesuian dlm prosesntase
            $matchvalue=$matchvaluebr;
            $rule_id_ok=$rule_id;
            $then_part_ok=$then_part;
            $prob=1;
            //echo "lewat sini....".$case_id."...."."rule_id"."----".$ada."..".$jumlahfakta."<br>";
            //echo $rule_id_ok." ok ".$then_part_ok."prob ".$prob."<br>";
            if ($goalstt==$then_part)
            {
                $cocok_ok=1;
            } else {$cocok_ok=0; }
        }
    }
    
    if ($simpan==0 and $prob==1)
    {
        $akhir = microtime(true);
        $lama = $akhir - $awal;
        // simpan data inferensi
        $sql = "INSERT INTO $tabelinferensi (inf_id, case_id, case_goal, rule_id, rule_goal, match_value, cocok, user_id, waktu, created_at)
        VALUES ($case_id, $case_id, '$goalstt', $rule_id_ok, '$then_part_ok', $matchvalue, $cocok_ok, $user_id, $lama, NOW())";
        
        if (mysqli_query($conn, $sql)) {
            //echo "New record created successfully".$case_id."....".$rule_id_ok."<br>";
        } else {
            echo "Error: " . $sql . "<br>" . mysqli_error($conn);
        }
    } elseif($simpan==0 and $prob==0)
    { 
        $akhir = microtime(true);
        $lama = $akhir - $awal;
        // simpan data inferensi
        $sql = "INSERT INTO $tabelinferensi (inf_id, case_id, case_goal, rule_id, rule_goal, match_value, cocok, user_id, waktu, created_at)
        VALUES ($case_id, $case_id, '$goalstt', '', '', 0, 0, $user_id, $lama, NOW())";
        
        if (mysqli_query($conn, $sql)) {
            //echo "New record created successfully".$case_id."....".$rule_id."<br>";
        } else {
            echo "Error: " . $sql . "<br>" . mysqli_error($conn);
        }
    }

}
// $akhir = microtime(true);
// $lama = $akhir - $awal;
// echo "Lama eksekusi script adalah: ".$lama." microsecond";

$conn->close();
?>
