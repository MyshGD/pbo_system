<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

try {
    require_once __DIR__ . '/config.php';
    require_once __DIR__ . '/database/db.php';
    
    echo "<pre>";
    echo "Testing loop with 10 people insertions...\n\n";
    $pdo = db();
    
    $person_names = [
        ['John Carlos', 'JC001', 'Office of the President', 'President'],
        ['Maria Santos', 'MS002', 'Business Operations', 'Director'],
        ['Antonio Cruz', 'AC003', 'Finance Department', 'Accountant'],
        ['Rosa Garcia', 'RG004', 'Inventory Management', 'Manager'],
        ['Pedro Lopez', 'PL005', 'Production', 'Supervisor'],
        ['Carmen Reyes', 'CR006', 'Sales', 'Officer'],
        ['Juan Fernandez', 'JF007', 'IT Support', 'Technician'],
        ['Isabel Torres', 'IT008', 'Student Services', 'Coordinator'],
        ['Ricardo Mendoza', 'RM009', 'Maintenance', 'Specialist'],
        ['Sofia Diaz', 'SD010', 'Administration', 'Assistant'],
    ];

    $people = [];
    $columns = 'full_name, person_code, department, role_or_position, contact_info, status, approved_by, approved_at';
    $placeholders = '?, ?, ?, ?, ?, ?, ?, ?';
    $stmt = $pdo->prepare("INSERT INTO people ($columns) VALUES ($placeholders)");
    
    foreach ($person_names as $idx => $person) {
        echo "Inserting person $idx: " . $person[0] . "... ";
        
        $data = [
            $person[0],
            $person[1],
            $person[2],
            $person[3],
            'ext-' . str_pad((string)count($people) + 1, 3, '0', STR_PAD_LEFT),
            'active',
            1,
            date('Y-m-d H:i:s'),
        ];
        
        if ($stmt->execute($data)) {
            $id = $pdo->lastInsertId();
            $people[] = $id;
            echo "✓ (ID: $id)\n";
        } else {
            echo "✗ ERROR: " . json_encode($stmt->errorInfo()) . "\n";
            break;
        }
    }
    
    echo "\n✓ Successfully inserted " . count($people) . " people\n";
    echo "</pre>";
    
} catch (Exception $e) {
    echo "<pre style='color: red;'>";
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString();
    echo "</pre>";
}
?>
