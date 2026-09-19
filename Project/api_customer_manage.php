<?php
require_once 'db.php';
header('Content-Type: application/json; charset=utf-8');

$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    if (isset($_POST['action'])) {
        $input = $_POST;
    } else {
        echo json_encode(['success' => false, 'message' => 'No data provided']);
        exit;
    }
}

$action = $input['action'] ?? '';

try {
    if ($action === 'add') {
        $name = trim($input['name'] ?? '');
        $tier = trim($input['tier'] ?? 'Member');
        $phone = trim($input['phone'] ?? '');
        $note = trim($input['note'] ?? '');
        
        $nameParts = explode(' ', $name, 2);
        $firstName = $nameParts[0] ?? '';
        $lastName = $nameParts[1] ?? '';

        // Generate a walk-in email so the frontend classifies this user as a Walk-In customer
        $uniqueEmail = 'walkin_' . time() . '_' . mt_rand(1000,9999) . '@brighthair.local';

        $stmt = $pdo->prepare("INSERT INTO users (first_name, last_name, member_tier, phone, note, email, password, role) VALUES (?, ?, ?, ?, ?, ?, '', 'customer')");
        $stmt->execute([$firstName, $lastName, $tier, $phone, $note, $uniqueEmail]);
        $newId = $pdo->lastInsertId();

        echo json_encode(['success' => true, 'id' => $newId]);
    }
    elseif ($action === 'edit') {
        $id = $input['id'] ?? null;
        if (!$id) {
            echo json_encode(['success' => false, 'message' => 'Customer ID is required']);
            exit;
        }

        $name = trim($input['name'] ?? '');
        $tier = trim($input['tier'] ?? 'Member');
        $phone = trim($input['phone'] ?? '');
        $note = trim($input['note'] ?? '');
        
        $nameParts = explode(' ', $name, 2);
        $firstName = $nameParts[0] ?? '';
        $lastName = $nameParts[1] ?? '';

        $stmt = $pdo->prepare("UPDATE users SET first_name = ?, last_name = ?, member_tier = ?, phone = ?, note = ? WHERE id = ?");
        $stmt->execute([$firstName, $lastName, $tier, $phone, $note, $id]);

        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'DB Error: ' . $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
