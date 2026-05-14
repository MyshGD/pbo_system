<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

try {
    require_once __DIR__ . '/config.php';
    require_once __DIR__ . '/database/db.php';
    
    echo "Testing database connection...<br>";
    $pdo = db();
    echo "✓ Connected to database<br>";
    
    // Test a simple query
    $result = $pdo->query("SELECT COUNT(*) as count FROM people");
    $row = $result->fetch(PDO::FETCH_ASSOC);
    echo "Current people count: " . $row['count'] . "<br>";
    
    // Test insert
    echo "Attempting test insert...<br>";
    $stmt = $pdo->prepare("INSERT INTO people (full_name, person_code, department, status) VALUES (?, ?, ?, ?)");
    $stmt->execute(['Test Person', 'TEST-001', 'Test Dept', 'active']);
    echo "✓ Insert successful<br>";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "<br>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}
?>
