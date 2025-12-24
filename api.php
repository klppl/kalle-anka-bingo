<?php
// api.php
require_once 'db.php';
session_start();

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

function jsonResponse($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}




function requireLogin() {
    if (!isset($_SESSION['user_id'])) {
        jsonResponse(['error' => 'Unauthorized'], 401);
    }
    return $_SESSION['user_id'];
}

function requireAdmin() {
    if (!isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
        jsonResponse(['error' => 'Unauthorized: Admin access required'], 403);
    }
}

try {
    switch ($action) {
        case 'login':
            if ($method !== 'POST') jsonResponse(['error' => 'Method not allowed'], 405);
            $input = json_decode(file_get_contents('php://input'), true);
            $username = trim($input['username'] ?? '');
            
            if (empty($username)) jsonResponse(['error' => 'Username required'], 400);

            // Find or create user
            $stmt = $db->prepare("SELECT id FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if (!$user) {
                $stmt = $db->prepare("INSERT INTO users (username) VALUES (?)");
                $stmt->execute([$username]);
                $userId = $db->lastInsertId();
            } else {
                $userId = $user['id'];
            }

            $_SESSION['user_id'] = $userId;
            $_SESSION['username'] = $username;
            jsonResponse(['success' => true, 'userId' => $userId]);
            break;

        case 'logout':
            session_destroy();
            jsonResponse(['success' => true]);
            break;

        case 'check_auth':
            if (isset($_SESSION['user_id'])) {
                jsonResponse(['authenticated' => true, 'username' => $_SESSION['username']]);
            } else {
                jsonResponse(['authenticated' => false]);
            }
            break;

        case 'get_items':
            $stmt = $db->query("SELECT * FROM bingo_items");
            $items = $stmt->fetchAll();
            jsonResponse($items);
            break;

        case 'get_active_game':
            $userId = requireLogin();
            
            // Get most recent active game
            $stmt = $db->prepare("SELECT id, created_at FROM user_games WHERE user_id = ? AND is_active = 1 ORDER BY id DESC LIMIT 1");
            $stmt->execute([$userId]);
            $game = $stmt->fetch();

            if ($game) {
                // Get squares
                $stmt = $db->prepare("
                    SELECT gs.id, gs.position, gs.is_checked, bi.content 
                    FROM game_squares gs 
                    JOIN bingo_items bi ON gs.item_id = bi.id 
                    WHERE gs.game_id = ? 
                    ORDER BY gs.position ASC
                ");
                $stmt->execute([$game['id']]);
                $squares = $stmt->fetchAll();
                
                jsonResponse(['hasGame' => true, 'game' => $game, 'squares' => $squares]);
            } else {
                jsonResponse(['hasGame' => false]);
            }
            break;

        case 'create_game':
            $userId = requireLogin();
            if ($method !== 'POST') jsonResponse(['error' => 'Method not allowed'], 405);
            $input = json_decode(file_get_contents('php://input'), true);
            $itemIds = $input['itemIds'] ?? [];

            // Validation: Need 25 items or at least allow it. Logic says user picks.
            if (count($itemIds) !== 25) {
                // If checking constraints. But maybe we allow incomplete? No, bingo is 5x5.
                // jsonResponse(['error' => 'Exactly 25 items required'], 400);
            }

            $db->beginTransaction();

            // Deactivate old games
            $stmt = $db->prepare("UPDATE user_games SET is_active = 0 WHERE user_id = ?");
            $stmt->execute([$userId]);

            // Create new game
            $stmt = $db->prepare("INSERT INTO user_games (user_id, is_active) VALUES (?, 1)");
            $stmt->execute([$userId]);
            $gameId = $db->lastInsertId();

            // Add squares
            shuffle($itemIds);
            $stmt = $db->prepare("INSERT INTO game_squares (game_id, item_id, position, is_checked) VALUES (?, ?, ?, 0)");
            foreach ($itemIds as $position => $itemId) {
                $stmt->execute([$gameId, $itemId, $position]);
            }

            $db->commit();
            jsonResponse(['success' => true, 'gameId' => $gameId]);
            break;

        case 'toggle_square':
            $userId = requireLogin();
            if ($method !== 'POST') jsonResponse(['error' => 'Method not allowed'], 405);
            $input = json_decode(file_get_contents('php://input'), true);
            $sId = $input['squareId'] ?? null;
            
            if (!$sId) jsonResponse(['error' => 'Square ID required'], 400);

            // Verify ownership
            $stmt = $db->prepare("
                SELECT gs.id, gs.is_checked 
                FROM game_squares gs 
                JOIN user_games ug ON gs.game_id = ug.id 
                WHERE gs.id = ? AND ug.user_id = ? AND ug.is_active = 1
            ");
            $stmt->execute([$sId, $userId]);
            $square = $stmt->fetch();

            if (!$square) jsonResponse(['error' => 'Square not found or not active'], 404);

            $newState = $square['is_checked'] ? 0 : 1;
            $stmt = $db->prepare("UPDATE game_squares SET is_checked = ? WHERE id = ?");
            $stmt->execute([$newState, $sId]);

            jsonResponse(['success' => true, 'newState' => (bool)$newState]);
            break;

        case 'admin_login':
            if ($method !== 'POST') jsonResponse(['error' => 'Method not allowed'], 405);
            $input = json_decode(file_get_contents('php://input'), true);
            $password = $input['password'] ?? '';
            
            if ($password === 'hunter2') {
                $_SESSION['is_admin'] = true;
                jsonResponse(['success' => true]);
            } else {
                jsonResponse(['error' => 'Invalid password'], 401);
            }
            break;

        case 'check_admin':
            jsonResponse(['isAdmin' => isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true]);
            break;

        case 'get_users':
            requireAdmin();
            $stmt = $db->query("SELECT id, username, created_at FROM users ORDER BY created_at DESC");
            $users = $stmt->fetchAll();
            jsonResponse($users);
            break;

        case 'delete_user':
            requireAdmin();
            if ($method !== 'POST') jsonResponse(['error' => 'Method not allowed'], 405);
            $input = json_decode(file_get_contents('php://input'), true);
            $userId = $input['userId'] ?? null;
            
            if (!$userId) jsonResponse(['error' => 'User ID required'], 400);

            $db->beginTransaction();
            // Cascade delete
            // 1. Delete squares
            $stmt = $db->prepare("DELETE FROM game_squares WHERE game_id IN (SELECT id FROM user_games WHERE user_id = ?)");
            $stmt->execute([$userId]);
            
            // 2. Delete games
            $stmt = $db->prepare("DELETE FROM user_games WHERE user_id = ?");
            $stmt->execute([$userId]);

            // 3. Delete user
            $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            
            $db->commit();
            jsonResponse(['success' => true]);
            break;

        case 'admin_add_item':
            requireAdmin();
            if ($method !== 'POST') jsonResponse(['error' => 'Method not allowed'], 405);
            $input = json_decode(file_get_contents('php://input'), true);
            $content = trim($input['content'] ?? '');
            
            if (empty($content)) jsonResponse(['error' => 'Content required'], 400);

            $stmt = $db->prepare("INSERT INTO bingo_items (content) VALUES (?)");
            $stmt->execute([$content]);
            jsonResponse(['success' => true, 'id' => $db->lastInsertId()]);
            break;

        case 'admin_update_item':
            requireAdmin();
            if ($method !== 'POST') jsonResponse(['error' => 'Method not allowed'], 405);
            $input = json_decode(file_get_contents('php://input'), true);
            $id = $input['id'] ?? null;
            $content = trim($input['content'] ?? '');
            
            if (!$id || empty($content)) jsonResponse(['error' => 'ID and content required'], 400);

            $stmt = $db->prepare("UPDATE bingo_items SET content = ? WHERE id = ?");
            $stmt->execute([$content, $id]);
            jsonResponse(['success' => true]);
            break;

        case 'admin_delete_item':
            requireAdmin();
            if ($method !== 'POST') jsonResponse(['error' => 'Method not allowed'], 405);
            $input = json_decode(file_get_contents('php://input'), true);
            $id = $input['id'] ?? null;
            
            if (!$id) jsonResponse(['error' => 'ID required'], 400);

            $db->beginTransaction();
            // Optional: delete or nullify game squares using this item?
            // To be safe, let's delete squares referencing this item to avoid orphans if no FK cascade
            $stmt = $db->prepare("DELETE FROM game_squares WHERE item_id = ?");
            $stmt->execute([$id]);

            $stmt = $db->prepare("DELETE FROM bingo_items WHERE id = ?");
            $stmt->execute([$id]);
            $db->commit();
            jsonResponse(['success' => true]);
            break;

        default:
            jsonResponse(['error' => 'Invalid action'], 400);
    }
} catch (Exception $e) {
    if ($db->inTransaction()) $db->rollBack();
    jsonResponse(['error' => $e->getMessage()], 500);
}
?>
