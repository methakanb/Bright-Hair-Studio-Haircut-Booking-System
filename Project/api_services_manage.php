<?php
require_once 'db.php';
header('Content-Type: application/json; charset=utf-8');

$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    if (isset($_POST['action']) || isset($_GET['action'])) {
        $input = $_POST ?: $_GET;
    } else {
        echo json_encode(['success' => false, 'message' => 'No data provided']);
        exit;
    }
}

$action = $input['action'] ?? '';

try {
    // ============================================
    // SERVICES
    // ============================================
    if ($action === 'add_service') {
        $code = trim($input['code'] ?? 'S_NEW');
        $name = trim($input['name'] ?? 'New Service');
        $name_en = trim($input['name_en'] ?? 'New Service');
        $duration = (int)($input['duration_min'] ?? 60);
        $price = (float)($input['price'] ?? 0);
        $desc = trim($input['description'] ?? '');
        $icon = trim($input['icon'] ?? 'fa-cut');

        $stmt = $pdo->prepare("INSERT INTO services (code, name, name_en, description, icon, duration_min, price, active) VALUES (?, ?, ?, ?, ?, ?, ?, 1)");
        $stmt->execute([$code, $name, $name_en, $desc, $icon, $duration, $price]);
        $newId = $pdo->lastInsertId();

        echo json_encode(['success' => true, 'id' => $newId]);
    }
    elseif ($action === 'edit_service') {
        $id = $input['id'] ?? null;
        if (!$id) { echo json_encode(['success' => false, 'message' => 'Service ID required']); exit; }

        $name = trim($input['name'] ?? '');
        $name_en = trim($input['name_en'] ?? '');
        $duration = (int)($input['duration_min'] ?? 0);
        $price = (float)($input['price'] ?? 0);
        $desc = trim($input['description'] ?? '');

        // Use COALESCE in SQL or skip empty updates if you want, but we assume full update
        $stmt = $pdo->prepare("UPDATE services SET name = ?, name_en = ?, description = ?, duration_min = ?, price = ? WHERE id = ?");
        $stmt->execute([$name, $name_en, $desc, $duration, $price, $id]);

        echo json_encode(['success' => true]);
    }
    elseif ($action === 'delete_service') {
        $id = $input['id'] ?? null;
        if (!$id) { echo json_encode(['success' => false, 'message' => 'Service ID required']); exit; }
        
        $stmt = $pdo->prepare("DELETE FROM services WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(['success' => true]);
    }

    // ============================================
    // PROMOTIONS
    // ============================================
    elseif ($action === 'add_promotion') {
        $name = trim($input['name'] ?? 'New Promotion');
        $type = trim($input['type'] ?? 'bundle');
        $start = trim($input['start_date'] ?? date('Y-m-d'));
        $end = trim($input['end_date'] ?? date('Y-m-d'));
        $price_old = (float)($input['price_old'] ?? 0);
        $price_new = (float)($input['price_new'] ?? 0);
        $desc = trim($input['description'] ?? '');
        $serviceIds = $input['service_ids'] ?? [];

        $stmt = $pdo->prepare("INSERT INTO promotions (name, description, type, start_date, end_date, price_old, price_new, icon, active) VALUES (?, ?, ?, ?, ?, ?, ?, 'fa-star', 1)");
        $stmt->execute([$name, $desc, $type, $start, $end, $price_old, $price_new]);
        $newId = $pdo->lastInsertId();

        if (is_array($serviceIds) && count($serviceIds) > 0) {
            $stmtMap = $pdo->prepare("INSERT INTO promotion_services (promotion_id, service_id) VALUES (?, ?)");
            foreach ($serviceIds as $sId) {
                $stmtMap->execute([$newId, $sId]);
            }
        }
        
        echo json_encode(['success' => true, 'id' => $newId]);
    }
    elseif ($action === 'edit_promotion') {
        $id = $input['id'] ?? null;
        if (!$id) { echo json_encode(['success' => false, 'message' => 'Promotion ID required']); exit; }

        $name = trim($input['name'] ?? '');
        $type = trim($input['type'] ?? 'bundle');
        $start = trim($input['start_date'] ?? date('Y-m-d'));
        $end = trim($input['end_date'] ?? date('Y-m-d'));
        $price_old = (float)($input['price_old'] ?? 0);
        $price_new = (float)($input['price_new'] ?? 0);
        $desc = trim($input['description'] ?? '');
        $serviceIds = $input['service_ids'] ?? [];

        $stmt = $pdo->prepare("UPDATE promotions SET name = ?, description = ?, type = ?, start_date = ?, end_date = ?, price_old = ?, price_new = ? WHERE id = ?");
        $stmt->execute([$name, $desc, $type, $start, $end, $price_old, $price_new, $id]);

        $pdo->prepare("DELETE FROM promotion_services WHERE promotion_id = ?")->execute([$id]);
        if (is_array($serviceIds) && count($serviceIds) > 0) {
            $stmtMap = $pdo->prepare("INSERT INTO promotion_services (promotion_id, service_id) VALUES (?, ?)");
            foreach ($serviceIds as $sId) {
                $stmtMap->execute([$id, $sId]);
            }
        }

        echo json_encode(['success' => true]);
    }
    elseif ($action === 'delete_promotion') {
        $id = $input['id'] ?? null;
        if (!$id) { echo json_encode(['success' => false, 'message' => 'Promotion ID required']); exit; }
        
        // Due to CASCADE ON DELETE in promotion_services, mappings will also be deleted
        $stmt = $pdo->prepare("DELETE FROM promotions WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(['success' => true]);
    }

    else {
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'DB Error: ' . $e->getMessage()]);
}
?>
