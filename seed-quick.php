<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
set_time_limit(300); // 5 minutes

echo "<pre style='font-family: monospace; padding: 20px; background: #f0f0f0;'>";

try {
    require_once __DIR__ . '/config.php';
    require_once __DIR__ . '/database/db.php';
    
    $pdo = db();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "🌱 Database Seed\n";
    echo "================\n\n";
    
    // Count existing data
    $result = $pdo->query("SELECT COUNT(*) as cnt FROM people WHERE person_code LIKE 'TEST%'");
    $existing = $result->fetch(PDO::FETCH_ASSOC)['cnt'];
    
    if ($existing > 0) {
        echo "ℹ️ Test data already exists ($existing records). Clearing...\n";
        $pdo->exec("DELETE FROM people WHERE person_code LIKE 'JC%' OR person_code LIKE 'MS%' OR person_code LIKE 'AC%' OR person_code LIKE 'RG%' OR person_code LIKE 'PL%' OR person_code LIKE 'CR%' OR person_code LIKE 'JF%' OR person_code LIKE 'IT%' OR person_code LIKE 'RM%' OR person_code LIKE 'SD%'");
        echo "✓ Cleared\n\n";
    }
    
    // Insert people
    echo "📝 Adding 10 staff members...\n";
    $people_data = [
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
    
    $stmt = $pdo->prepare("INSERT INTO people (full_name, person_code, department, role_or_position, contact_info, status, approved_by, approved_at) VALUES (?, ?, ?, ?, ?, 'active', 1, NOW())");
    $people = [];
    foreach ($people_data as $idx => $p) {
        $contact = 'ext-' . str_pad((string)($idx + 1), 3, '0', STR_PAD_LEFT);
        $stmt->execute([$p[0], $p[1], $p[2], $p[3], $contact]);
        $people[] = $pdo->lastInsertId();
    }
    echo "   ✓ Added " . count($people) . " staff\n\n";
    
    // Insert products
    echo "📦 Adding 11 products/services...\n";
    $products_data = [
        ['SCH001', 'Blue Ballpen', 'school_supply', 'product', 'item', 2.50, 5.00, 150],
        ['SCH002', 'Notebook A4', 'school_supply', 'product', 'item', 15.00, 30.00, 80],
        ['SCH003', 'Pencil HB', 'school_supply', 'product', 'item', 1.50, 3.50, 200],
        ['ID001', 'Student ID Card', 'id_supplies', 'product', 'item', 8.00, 15.00, 120],
        ['ID002', 'Staff ID Holder', 'id_supplies', 'product', 'item', 12.00, 25.00, 60],
        ['SRV001', 'ID Photo Session', 'id_services', 'service', 'service', 0, 50.00, 0],
        ['SRV002', 'Document Certification', 'printing', 'service', 'service', 0, 25.00, 0],
        ['PRT001', 'Flyer Printing (500 pcs)', 'printing', 'igp', 'service', 300.00, 800.00, 0],
        ['PRT002', 'Brochure Printing (1000 pcs)', 'printing', 'igp', 'service', 500.00, 1500.00, 0],
        ['PCY001', 'Photocopy Service (B&W)', 'photocopy', 'service', 'service', 0, 0.50, 0],
        ['PCY002', 'Photocopy Service (Color)', 'photocopy', 'service', 'service', 0, 2.00, 0],
    ];
    
    // Clean up existing test data (respect foreign keys)
    $pdo->exec("DELETE FROM sales WHERE or_number LIKE 'OR-%'");
    $pdo->exec("DELETE FROM inventory_stock_movements WHERE reference_no LIKE 'PO-%'");
    $pdo->exec("DELETE FROM inventory_stock_batches WHERE batch_code LIKE 'BATCH-%'");
    $pdo->exec("DELETE FROM products WHERE sku LIKE 'SCH%' OR sku LIKE 'ID%' OR sku LIKE 'SRV%' OR sku LIKE 'PRT%' OR sku LIKE 'PCY%'");
    
    $stmt = $pdo->prepare("INSERT INTO products (sku, name, category, product_group, type, cost_price, selling_price, stock_qty, low_stock_threshold, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 20, 1)");
    $products = [];
    foreach ($products_data as $p) {
        $stmt->execute([$p[0], $p[1], $p[2], $p[3], $p[4], $p[5], $p[6], $p[7]]);
        $products[] = $pdo->lastInsertId();
    }
    echo "   ✓ Added " . count($products) . " products\n\n";
    
    // Insert sales
    echo "💰 Adding ~90 sales records (last 30 days)...\n";
    $stmt = $pdo->prepare("INSERT INTO sales (sale_date, product_id, quantity, unit_price, unit_cost, total_amount, total_cost, total_profit, or_number, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)");
    $sales_count = 0;
    for ($day = -30; $day < 0; $day++) {
        $date = date('Y-m-d', strtotime("$day days"));
        $num_sales = rand(2, 5);
        for ($s = 0; $s < $num_sales; $s++) {
            $product_idx = rand(0, count($products) - 1);
            $product = $products_data[$product_idx];
            $quantity = rand(1, 10);
            $unit_price = $product[6];
            $unit_cost = $product[5];
            $total_amount = $quantity * $unit_price;
            $total_cost = $quantity * $unit_cost;
            $total_profit = $total_amount - $total_cost;
            $or_number = 'OR-' . date('Ymd', strtotime($date)) . '-' . str_pad((string)($s + 1), 4, '0', STR_PAD_LEFT);
            
            $datetime = $date . ' ' . sprintf('%02d:%02d:00', rand(8, 18), rand(0, 59));
            $stmt->execute([$datetime, $products[$product_idx], $quantity, $unit_price, $unit_cost, $total_amount, $total_cost, $total_profit, $or_number]);
            $sales_count++;
        }
    }
    echo "   ✓ Added $sales_count sales\n\n";
    
    // Insert cash transactions
    echo "💵 Adding ~60 cash transactions...\n";
    $stmt = $pdo->prepare("INSERT INTO cash_transactions (txn_date, direction, source_module, amount, or_number, description, created_by) VALUES (?, ?, ?, ?, ?, ?, 1)");
    $cash_count = 0;
    for ($day = -30; $day < 0; $day++) {
        $date = date('Y-m-d', strtotime("$day days"));
        $daily_sales = rand(5000, 25000);
        $stmt->execute([$date . ' 16:00:00', 'in', 'sales', $daily_sales, 'DEP-' . date('Ymd', strtotime($date)), 'Daily sales deposit']);
        $cash_count++;
        
        if (rand(1, 3) === 1) {
            $stmt->execute([$date . ' ' . sprintf('%02d:%02d:00', rand(8, 17), rand(0, 59)), 'out', 'manual', rand(500, 3000), null, 'Office supplies / Maintenance']);
            $cash_count++;
        }
    }
    echo "   ✓ Added $cash_count cash transactions\n\n";
    
    // Insert project categories
    echo "🏗️  Adding 4 project categories...\n";
    
    // Clean up existing test categories and their dependents (respect foreign keys)
    $pdo->exec("DELETE FROM project_entries WHERE category_id IN (SELECT id FROM project_categories WHERE slug IN ('fishpond', 'rental', 'catering', 'laundry'))");
    $pdo->exec("DELETE FROM project_accounts WHERE category_id IN (SELECT id FROM project_categories WHERE slug IN ('fishpond', 'rental', 'catering', 'laundry'))");
    $pdo->exec("DELETE FROM project_categories WHERE slug IN ('fishpond', 'rental', 'catering', 'laundry')");
    
    $stmt = $pdo->prepare("INSERT INTO project_categories (slug, name, description, is_active) VALUES (?, ?, ?, 1)");
    $categories_data = [
        ['fishpond', 'Fishpond Operations', 'Fish farming and pond management'],
        ['rental', 'Rental Operations', 'Stall and toga rentals'],
        ['catering', 'Catering Services', 'Food and catering services'],
        ['laundry', 'Laundry Services', 'Uniform and clothing laundry'],
    ];
    $categories = [];
    foreach ($categories_data as $cat) {
        $stmt->execute($cat);
        $categories[] = $pdo->lastInsertId();
    }
    echo "   ✓ Added " . count($categories) . " categories\n\n";
    
    // Insert project accounts
    echo "📋 Adding 6 project accounts...\n";
    $stmt = $pdo->prepare("INSERT INTO project_accounts (category_id, account_name, code, contact_name, start_date, next_due_date, expected_amount, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'active')");
    $accounts = [];
    
    $stmt->execute([$categories[0], 'Tilapia Pond 1', 'FP-001', null, date('Y-m-d', strtotime('-90 days')), null, 50000.00]);
    $accounts[] = $pdo->lastInsertId();
    $stmt->execute([$categories[0], 'Catfish Pond 2', 'FP-002', null, date('Y-m-d', strtotime('-90 days')), null, 50000.00]);
    $accounts[] = $pdo->lastInsertId();
    
    $stmt->execute([$categories[1], 'Stall A1 - Meat Market', 'STALL-A1', 'Maria Santos', null, date('Y-m-d', strtotime('+5 days')), 3000.00]);
    $accounts[] = $pdo->lastInsertId();
    $stmt->execute([$categories[1], 'Stall B2 - Produce', 'STALL-B2', 'Jose Garcia', null, date('Y-m-d', strtotime('+5 days')), 2500.00]);
    $accounts[] = $pdo->lastInsertId();
    $stmt->execute([$categories[1], 'Stall C3 - Dry Goods', 'STALL-C3', 'Rosa Martinez', null, date('Y-m-d', strtotime('+5 days')), 2500.00]);
    $accounts[] = $pdo->lastInsertId();
    $stmt->execute([$categories[1], 'Toga Rental Account', 'TOGA-001', null, null, date('Y-m-d', strtotime('+5 days')), 1500.00]);
    $accounts[] = $pdo->lastInsertId();
    
    echo "   ✓ Added " . count($accounts) . " accounts\n\n";
    
    // Insert logbook entries
    echo "📖 Adding ~150 logbook entries...\n";
    $stmt = $pdo->prepare("INSERT INTO office_logbook (log_date, time_in, time_out, student_name, student_id, purpose, created_by) VALUES (?, ?, ?, ?, ?, ?, 1)");
    $purposes = ['Document certification', 'ID photo session', 'Photocopy service', 'General inquiry', 'Payment processing', 'Event coordination', 'Pickup order', 'Service request'];
    $logbook_count = 0;
    for ($day = -30; $day < 0; $day++) {
        $date = date('Y-m-d', strtotime("$day days"));
        $num_entries = rand(3, 8);
        for ($e = 0; $e < $num_entries; $e++) {
            $time_in = sprintf('%02d:%02d', rand(8, 17), rand(0, 59));
            $time_out = sprintf('%02d:%02d', rand(intval($time_in) + 1, 18), rand(0, 59));
            $stmt->execute([$date, $time_in . ':00', $time_out . ':00', 'Student ' . rand(1000, 9999), 'STU-' . rand(100000, 999999), $purposes[array_rand($purposes)]]);
            $logbook_count++;
        }
    }
    echo "   ✓ Added $logbook_count logbook entries\n\n";
    
    // Insert proposals
    echo "💡 Adding 5 proposals...\n";
    
    // Clean up existing test proposals
    $pdo->exec("DELETE FROM proposals WHERE title LIKE '%fishpond%' OR title LIKE '%equipment%' OR title LIKE '%reservation%' OR title LIKE '%training%' OR title LIKE '%solar%'");
    
    $stmt = $pdo->prepare("INSERT INTO proposals (proposer_id, title, proposer_name, department, estimated_budget, target_date, summary, status, submitted_at, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)");
    $proposal_titles = [
        'Expand fishpond operations to north sector',
        'Upgrade office equipment and furniture',
        'Implement online reservation system for rentals',
        'Training program for staff development',
        'Green initiative - solar panel installation',
    ];
    $proposal_count = 0;
    foreach ($proposal_titles as $title) {
        $proposer_id = $people[array_rand($people)];
        $status = ['pending', 'approved', 'rejected'][rand(0, 2)];
        $target_date = date('Y-m-d', strtotime('+' . rand(30, 180) . ' days'));
        $submitted_date = date('Y-m-d H:i:s', strtotime('-' . rand(1, 30) . ' days'));
        $stmt->execute([$proposer_id, $title, 'Proposer Name', 'Administrative', rand(50000, 500000), $target_date, 'Project proposal for institutional improvement.', $status, $submitted_date]);
        $proposal_count++;
    }
    echo "   ✓ Added $proposal_count proposals\n\n";
    
    echo "✅ Database seed completed!\n\n";
    echo "Summary:\n";
    echo "   • Staff members: " . count($people) . "\n";
    echo "   • Products: " . count($products) . "\n";
    echo "   • Sales: $sales_count\n";
    echo "   • Cash transactions: $cash_count\n";
    echo "   • Project accounts: " . count($accounts) . "\n";
    echo "   • Logbook entries: $logbook_count\n";
    echo "   • Proposals: $proposal_count\n";
    
} catch (Exception $e) {
    echo "\n❌ Error: " . $e->getMessage() . "\n\n";
    echo "Trace:\n";
    echo $e->getTraceAsString();
}

echo "</pre>";
?>
