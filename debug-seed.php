<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

try {
    require_once __DIR__ . '/config.php';
    require_once __DIR__ . '/database/db.php';
    
    echo "<pre>";
    echo "Testing people insertion...\n";
    $pdo = db();
    echo "Connected\n";
    
    // Try to insert one record
    $columns = 'full_name, person_code, department, role_or_position, contact_info, status, approved_by, approved_at';
    $placeholders = '?, ?, ?, ?, ?, ?, ?, ?';
    $stmt = $pdo->prepare("INSERT INTO people ($columns) VALUES ($placeholders)");
    
    echo "Prepared statement\n";
    
    $data = [
        'John Carlos',
        'JC001',
        'Office of the President',
        'President',
        'ext-001',
        'active',
        1,
        date('Y-m-d H:i:s')
    ];
    
    echo "About to execute with data: " . json_encode($data) . "\n";
    $result = $stmt->execute($data);
    
    if ($result) {
        echo "✓ Insert successful! LastInsertId: " . $pdo->lastInsertId() . "\n";
    } else {
        echo "✗ Insert failed!\n";
        echo "Error: " . json_encode($stmt->errorInfo()) . "\n";
    }
    
    echo "</pre>";
    
} catch (Exception $e) {
    echo "<pre style='color: red;'>";
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString();
    echo "</pre>";
}
?>
