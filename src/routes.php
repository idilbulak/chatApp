<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

require __DIR__ . '/../config/config.php';

$app->get('/start', function (Request $request, Response $response, $args) {
    $response->getBody()->write("Hadi baslayalim!");
    return $response;
});

// get all groups from db
$app->get('/groups', function (Request $request, Response $response ) {
    $db = getDatabaseConnection();
    $stmt = $db->query('SELECT * FROM groups');
    $groups = $stmt->fetchAll(PDO::FETCH_OBJ);

    $response->getBody()->write(json_encode($groups));
    return $response->withHeader('Content-Type', 'application/json');
});

//  create new group
$app->post('/groups', function (Request $request, Response $response) {

    $db = getDatabaseConnection();

    // JSON'dan veriyi çek
    $data = $request->getParsedBody();
    $groupName = $data['group_name'] ?? null;
    $groupAdminId = $data['group_admin'] ?? null;

    // group_admin kontrolü
    if (!$groupAdminId) {
        $response->getBody()->write(json_encode([
            'status' => 'error',
            'message' => 'Group admin ID is required'
        ]));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
    }

    // group_name kontrolü
    if (!$groupName) {
        $randomNumber = rand(1000, 9999);
        $timestamp = time();
        $groupName = "group" . $randomNumber . "_" . $timestamp;
    } else if (strlen($groupName) > 100) {
        $response->getBody()->write(json_encode([
            'status' => 'error',
            'message' => 'Group name is too long'
        ]));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
    }

    // Veritabanına grup ekle
    $stmt = $db->prepare('INSERT INTO groups (group_name, group_admin) VALUES (:group_name, :group_admin)');
    $stmt->bindParam(':group_name', $groupName);
    $stmt->bindParam(':group_admin', $groupAdminId, PDO::PARAM_INT);
    $stmt->execute();

    $newGroupId = $db->lastInsertId();

    // group_members tablosuna grup adminini ekleyin
    $stmtMember = $db->prepare('INSERT INTO group_members (group_id, user_id) VALUES (:group_id, :user_id)');
    $stmtMember->bindParam(':group_id', $newGroupId, PDO::PARAM_INT);
    $stmtMember->bindParam(':user_id', $groupAdminId, PDO::PARAM_INT);
    $stmtMember->execute();

    // JSON olarak yanıt dön
    $responseData = ['id' => $newGroupId, 'group_name' => $groupName, 'group_admin' => $groupAdminId];
    $response->getBody()->write(json_encode($responseData));
    return $response->withHeader('Content-Type', 'application/json');
});

$app->post('/groups/{group_id}/join', function (Request $request, Response $response, $args) {
    $db = getDatabaseConnection();

    $groupId = $args['group_id'];

    // Veriyi JSON'dan çek
    $data = $request->getParsedBody();
    $userId = $data['user_id'] ?? null;

    if (!$userId || !$groupId) {
        $response->getBody()->write(json_encode([
            'status' => 'error',
            'message' => 'User ID and Group ID are required'
        ]));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
    }

    // TODO: group_id ve user_id'nin geçerli olup olmadığını kontrol edin. Eğer değilse, hata dön.
    $stmtGroup = $db->prepare('SELECT COUNT(*) as count FROM groups WHERE group_id = :group_id');
    $stmtGroup->bindParam(':group_id', $groupId, PDO::PARAM_INT);
    $stmtGroup->execute();
    $groupCount = $stmtGroup->fetch(PDO::FETCH_OBJ)->count;

    if ($groupCount == 0) {
        $response->getBody()->write(json_encode([
            'status' => 'error',
            'message' => 'Invalid group ID'
        ]));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
    }

    // Veritabanına yeni üyeyi ekle
    $stmt = $db->prepare('INSERT INTO group_members (group_id, user_id) VALUES (:group_id, :user_id)');
    $stmt->bindParam(':group_id', $groupId, PDO::PARAM_INT);
    $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
    $stmt->execute();

    // JSON olarak yanıt dön
    $responseData = ['group_id' => $groupId, 'user_id' => $userId, 'status' => 'joined'];
    $response->getBody()->write(json_encode($responseData));
    return $response->withHeader('Content-Type', 'application/json');
});

$app->post('/groups/{group_id}/leave', function (Request $request, Response $response, $args) {
    $db = getDatabaseConnection();

    $groupId = $args['group_id'];
    $userId = $request->getParsedBody()['user_id'] ?? null;

    // Admin kontrolü
    $stmt = $db->prepare('SELECT group_admin FROM groups WHERE group_id = :group_id');
    $stmt->bindParam(':group_id', $groupId);
    $stmt->execute();
    $adminId = $stmt->fetchColumn();

    if ($userId == $adminId) {
        // Eğer grupta başka üyeler varsa, rastgele birini admin yap
        $stmtOtherMembers = $db->prepare('SELECT user_id FROM group_members WHERE group_id = :group_id AND user_id != :user_id LIMIT 1');
        $stmtOtherMembers->bindParam(':group_id', $groupId);
        $stmtOtherMembers->bindParam(':user_id', userId);
        $stmtOtherMembers->execute();
        $newAdmin = $stmtOtherMembers->fetchColumn();

        if ($newAdmin) {
            $stmtNewAdmin = $db->prepare('UPDATE groups SET group_admin = :new_admin WHERE group_id = :group_id');
            $stmtNewAdmin->bindParam(':new_admin', $newAdmin);
            $stmtNewAdmin->bindParam(':group_id', $groupId);
            $stmtNewAdmin->execute();
        } else {
            // Eğer grupta başka üye yoksa, grubu sil
            $stmtDelete = $db->prepare('DELETE FROM groups WHERE group_id = :group_id');
            $stmtDelete->bindParam(':group_id', $groupId);
            $stmtDelete->execute();
        }
    }

    // Kullanıcıyı gruptan çıkar
    $stmtLeave = $db->prepare('DELETE FROM group_members WHERE group_id = :group_id AND user_id = :user_id');
    $stmtLeave->bindParam(':group_id', $groupId);
    $stmtLeave->bindParam(':user_id', $userId);
    $stmtLeave->execute();

    $response->getBody()->write(json_encode(['status' => 'left']));
    return $response->withHeader('Content-Type', 'application/json');
});


//  grubu silme
$app->delete('/groups/{group_id}', function (Request $request, Response $response, $args) {
    $db = getDatabaseConnection();

    $groupId = $args['group_id'];
    $userId = $request->getParsedBody()['user_id'] ?? null; // kullanıcının ID'sini request'ten al

    // Admin kontrolü
    $stmt = $db->prepare('SELECT group_admin FROM groups WHERE group_id = :group_id');
    $stmt->bindParam(':group_id', $groupId);
    $stmt->execute();
    $adminId = $stmt->fetchColumn();

    if ($userId != $adminId) {
        $response->getBody()->write(json_encode([
            'status' => 'error',
            'message' => 'Only the group admin can delete the group'
        ]));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(403); // Forbidden
    }

    // Grubu sil
    $stmtDelete = $db->prepare('DELETE FROM groups WHERE group_id = :group_id');
    $stmtDelete->bindParam(':group_id', $groupId);
    $stmtDelete->execute();

    // Gruba ait tüm üyelikleri sil
    $stmtDeleteMembers = $db->prepare('DELETE FROM group_members WHERE group_id = :group_id');
    $stmtDeleteMembers->bindParam(':group_id', $groupId);
    $stmtDeleteMembers->execute();

    $response->getBody()->write(json_encode(['status' => 'success']));
    return $response->withHeader('Content-Type', 'application/json');
});

$app->post('/messages/send', function (Request $request, Response $response) {
    $db = getDatabaseConnection();

    $data = $request->getParsedBody();
    $groupId = $data['group_id'] ?? null;
    $userId = $data['user_id'] ?? null;
    $content = $data['content'] ?? null;

    $maxContentLength = 1000; // Maksimum karakter sayısı

    if (strlen($content) > $maxContentLength) {
        $response->getBody()->write(json_encode([
            'status' => 'error',
            'message' => "Content exceeds the maximum allowed length of {$maxContentLength} characters."
        ]));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
    }

    if (!$groupId || !$userId || !$content) {
        $response->getBody()->write(json_encode([
            'status' => 'error',
            'message' => 'Group ID, User ID and content are required'
        ]));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
    }

    $stmt = $db->prepare('INSERT INTO messages (group_id, user_id, content) VALUES (:group_id, :user_id, :content)');
    $stmt->bindParam(':group_id', $groupId, PDO::PARAM_INT);
    $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
    $stmt->bindParam(':content', $content);
    $stmt->execute();

    $newMessageId = $db->lastInsertId();

    $responseData = ['message_id' => $newMessageId, 'status' => 'sent'];
    $response->getBody()->write(json_encode($responseData));
    return $response->withHeader('Content-Type', 'application/json');
});

$app->get('/groups/{group_id}/messages', function (Request $request, Response $response, $args) {
    $db = getDatabaseConnection();

    // Grup ID'sini URL'den al
    $groupId = $args['group_id'];

    // Grup ID'sini kontrol edin
    $stmtGroup = $db->prepare('SELECT COUNT(*) as count FROM groups WHERE group_id = :group_id');
    $stmtGroup->bindParam(':group_id', $groupId, PDO::PARAM_INT);
    $stmtGroup->execute();
    $groupCount = $stmtGroup->fetch(PDO::FETCH_OBJ)->count;

    if ($groupCount == 0) {
        $response->getBody()->write(json_encode([
            'status' => 'error',
            'message' => 'Invalid group ID'
        ]));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
    }

    // Belirtilen grup için tüm mesajları sıralı bir şekilde al
    $stmt = $db->prepare('SELECT * FROM messages WHERE group_id = :group_id ORDER BY timestamp ASC');
    $stmt->bindParam(':group_id', $groupId, PDO::PARAM_INT);
    $stmt->execute();
    $messages = $stmt->fetchAll(PDO::FETCH_OBJ);

    // JSON olarak yanıt dön
    $response->getBody()->write(json_encode($messages));
    return $response->withHeader('Content-Type', 'application/json');
});

$app->any('{route:.*}', function (Request $request, Response $response) {
    $response->getBody()->write("Not Found");
    return $response->withStatus(404);
});